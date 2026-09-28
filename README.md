# High-Traffic News Feed API

A Twitter/Instagram-style news feed API in Laravel, built for **5M users and 500k posts per day**.

The full design reasoning (numbers, options, trade-offs) is in [`Architecture/Architecture.md`](Architecture/Architecture.md). This README covers how to run it, what was built, and how it scales.

**Stack:** Laravel 13 (PHP 8.4) · Sanctum · MySQL 8 · Redis (feed store, cache, queue) · Horizon · Pest · Scribe

---

## Setup

### Docker (recommended)

```bash
make up      # builds and starts app (php-fpm), nginx, mysql, redis, horizon, scheduler, then migrates
make seed    # ~12k users, 3 celebrities, 200k posts, ~400k follows, 100k likes (~25s)
make test
```

API: `http://localhost:8080/api` · Docs: `http://localhost:8080/docs` · Horizon: `http://localhost:8080/horizon`

Demo login after seeding: `demo@example.com` / `password`.

### Local (without Docker)

Requirements: PHP 8.4+ with `redis`, `pdo_mysql`, `pcntl`; MySQL 8 or MariaDB 10.6+; Redis.

```bash
composer install
cp .env.example .env            # set DB_* and REDIS_HOST=127.0.0.1
php artisan key:generate
php artisan migrate --seed
php artisan serve               # terminal 1
php artisan horizon             # terminal 2: queue workers (feed + notifications)
```

### Tests

Feature tests run against a real MySQL database named `high_trafic_testing`, using the DB credentials from `.env`. Create the database first; Docker creates it for you. The feed runs on the in-memory `FeedStore`, so Redis is not needed. The Redis `FeedStore` contract tests run when Redis is reachable and are skipped otherwise.

```bash
php artisan test --compact      # 58 tests
```

---

## API

All routes are prefixed with `/api`. Everything except register/login needs `Authorization: Bearer <token>`.

| Method | Endpoint | Response |
|---|---|---|
| POST | `/register` | 201, user + token |
| POST | `/login` | 200, user + token, throttled 5/min |
| POST | `/follow/{user_id}` | 204, idempotent, 422 on self-follow, 404 unknown user |
| DELETE | `/follow/{user_id}` | 204, idempotent |
| POST | `/posts` | 201, content 1–1000 chars |
| GET | `/posts/{id}` | 200, with `author`, `likes_count`, `liked_by_me` |
| DELETE | `/posts/{id}` | 204, author only (403) |
| POST | `/posts/{id}/like` | 204, idempotent |
| DELETE | `/posts/{id}/like` | 204, idempotent |
| **GET** | **`/feed?cursor=&limit=`** | Not in the task's endpoint table, but section 3 needs it. `limit` ≤ 50 |

Feed response:

```json
{
  "data": [
    { "id": 961, "content": "…", "created_at": "…", "author": { "id": 2, "name": "Mohammed" },
      "likes_count": 3, "liked_by_me": true }
  ],
  "meta": { "next_cursor": 961, "has_more": true }
}
```

Every error has the same envelope, rendered in `app/Exceptions/ApiExceptionRenderer.php`:

```json
{ "message": "Post not found", "code": "POST_NOT_FOUND", "errors": {} }
```

Full docs: `/docs` (Scribe, with Postman and OpenAPI exports). Regenerate with `php artisan scribe:generate`.

---

## Architecture decisions

### Hybrid fan-out feed

At the numbers in the task, feed reads (~230/s average, 2–3k/s at peak) outnumber post writes (~6/s) about 40 to 1, so work moves from read time to write time:

- **Normal authors, fan-out on write.** When a post is created, a queued job pushes its id into every follower's `feed:{user}` Redis sorted set.
- **Celebrities (≥ `FEED_CELEBRITY_THRESHOLD` followers, default 10k), fan-out on read.** There's no fan-out for them. Readers merge the author's `timeline:{author}` at read time. Readers follow few celebrities, so the merge is cheap.
- **Redis is a cache, not the source of truth.** A missing feed (inactive user, expired key, Redis restart) is rebuilt from MySQL with the pull query. A reader who scrolls past the 500-id cap is served straight from MySQL.

```
feed:{user}      ZSET  member = score = post id, capped at 500, TTL 7 days (refreshed on read)
timeline:{user}  ZSET  the author's own posts (celebrity merge + follow backfill)
```

Each list holds a sentinel member `0`, so an empty feed that has been built still exists as a key. Fan-out writes go through a Lua script that **only adds to lists that already exist**. Without that, one push to an unbuilt feed would create a one-post list that looks complete.

Code: `app/Services/FeedService.php`, `app/Contracts/FeedStore.php`, `app/Stores/RedisFeedStore.php`.

### Cursor pagination

`?cursor=<last id>` returns posts with `id < cursor`. Each page is an index seek however deep you scroll, and new posts never shift a page (covered by a test). Ids are auto-increment, so ordering by id is the same as ordering by `created_at`, with no tie-breaker needed.

### No N+1: a fixed number of queries per page

`app/Services/PostHydrator.php` turns ids into posts:

| Step | Source | Cost |
|---|---|---|
| ids | Redis `feed:{me}` + celebrity timelines | 0 SQL |
| celebrities I follow | `celebs:{me}` cache (1h) | ≤ 1 query |
| posts | `post:{id}` cache, misses in one `WHERE id IN (…)` | ≤ 1 query |
| authors | `user:{id}:card` cache, misses in one `WHERE id IN (…)` | ≤ 1 query |
| liked by me | one query on the `likes` primary key | 1 query |
| like counts | Redis `likes:{id}`, otherwise the `likes_count` column | 0 extra on the cold path |

`tests/Feature/FeedTest.php` checks this. The test *"hydrates a page of 20 posts from 20 authors in at most 4 queries"* enables the query log with cold caches and asserts exactly 4 queries. The hot path has no `follows ⋈ posts` join.

### Likes

- The `likes` primary key `(user_id, post_id)` makes a double like impossible at the database level.
- The like is written with `insertOrIgnore`. `posts.likes_count` (atomic `+1` in SQL) and the Redis counter move **only if a row was really inserted**. Double taps and unlikes of posts you never liked are 204 no-ops.
- "Did I like it" is never cached. It is per user and must be correct right after the click.
- Upgrade path for viral posts (row-lock contention on one `posts` row): count in Redis only and flush to MySQL on a schedule.

### Queue design

```
PostCreated (ShouldDispatchAfterCommit)
 ├─ QueueFeedFanOut ─────────► FanOutPost        [queue: feed]
 │                               ├─ celebrity? stop, merged on read
 │                               └─ followers chunkById(1000) → Bus::batch(FanOutChunk × N)
 │                                     each chunk: one Redis pipeline of ZADDs
 └─ QueueFollowerNotifications ► NotifyFollowers  [queue: notifications]
                                 └─ Bus::batch(SendNotificationChunk × N) [notifications | notifications-low for celebrities]
                                       each chunk: one bulk INSERT IGNORE of 1000 rows

Follow   → BackfillFeed          [feed]  copies the followee's latest 20 posts into my feed
Unfollow → PruneFeedOnUnfollow   [feed]  removes their posts from my feed
```

- **Separate queues and Horizon supervisors** (`config/horizon.php`). A notification backlog never delays feeds. The feed supervisor gets the most workers, and celebrity notifications go to `notifications-low`.
- **Chunks retry on their own.** A failure at follower 7,000 doesn't resend to the first 7,000.
- **Idempotent jobs.** `ZADD` is naturally idempotent. A notification's id is a UUIDv5 of `(post_id, follower_id)`, so the primary key is effectively unique per (post, user), and a retried chunk inserts nothing twice.
- **After commit.** Workers never see a rolled-back post. Jobs use `tries = 3` with backoff `[5, 30, 120]`s, failures land in `failed_jobs`, and Horizon handles monitoring.
- **Redis, not the database queue driver.** The database driver would poll the MySQL instance this design is trying to protect.

### Caching

| Key | Type | TTL | Invalidation |
|---|---|---|---|
| `feed:{user}` | ZSET of post ids | 7 days, sliding | append-only, capped, rebuilt on miss |
| `timeline:{user}` | ZSET | 7 days, sliding | add on create, remove on delete |
| `post:{id}` | cached array | 24h | forgotten on delete |
| `user:{id}:card` | cached array (id, name) | 24h | — |
| `likes:{post}` | counter | 7 days | `INCRBY` only if cached, reloaded from the column on miss |
| `celebs:{user}` | ids of celebrities I follow | 1h | forgotten on follow/unfollow |

Whole feed responses are **not** cached. Every new post and every like would invalidate them, so the hit rate would be near zero, and each post would be duplicated in every follower's cache. Instead the per-user id lists are cached separately from shared entities, so one cached post serves all its readers.

Redis over Memcached: the feed needs sorted sets.

### Deletes and unfollows are lazy

A deleted post is soft-deleted, removed from the author's timeline and dropped from the entity cache. Follower feeds are **not** touched. The hydrator skips the id, the page fetches `limit + 5` candidates so it stays full, and the 500 cap eventually pushes the id out. Removing it eagerly would cost one Redis write per follower for a post most followers will never scroll to.

### Code structure

| Layer | Where |
|---|---|
| Thin controllers | `app/Http/Controllers` (request → service → resource) |
| Validation | Form Requests in `app/Http/Requests` |
| Authorization | `PostPolicy` (`->can('delete', 'post')` on the route) |
| Business rules and transactions | `AuthService`, `PostService`, `LikeService`, `FollowService`, `FeedService`, `PostHydrator` |
| Non-trivial storage | `FeedStore` / `CounterStore` interfaces with Redis and in-memory implementations, `PostRepository`, `FollowRepository`, `LikeRepository` |
| Events and jobs | `PostCreated` → listeners → `FanOutPost`, `FanOutChunk`, `NotifyFollowers`, `SendNotificationChunk`, `BackfillFeed`, `PruneFeedOnUnfollow` |
| DTOs | `CreatePostData`, `PostView`, `FeedPage` |

---

## Database and indexes

```sql
follows  PRIMARY KEY (follower_id, followee_id)   -- who I follow + no duplicate follows
         INDEX (followee_id, follower_id)          -- who follows me (fan-out), covering
posts    INDEX (user_id, id)                       -- author timeline, pull query, cursor
likes    PRIMARY KEY (user_id, post_id)            -- no double likes + "did I like these"
         INDEX (post_id)
users    UNIQUE (email), followers_count / following_count (denormalized)
```

- **Composite primary keys** on `follows` and `likes`. The pair is the row's identity, and it serves as the index that's needed anyway.
- **`(user_id, id)`, not `(user_id, created_at)`.** Ids are unique and ordered, so the cursor needs no tie-breaker.
- **`followers_count` on users.** The fan-out asks "is this author a celebrity?" in O(1).
- **Foreign keys** are kept in the migrations for development. In production at this scale I'd drop them from the hottest tables: they add check and lock cost, and they block sharding. Integrity is also enforced in the service layer.

`EXPLAIN` on the seeded data set (12k users, 200k posts, 395k follows), MariaDB 12:

```
-- Pull/fallback query (only used when the Redis feed is missing or past the cap)
SELECT id FROM posts WHERE user_id IN (SELECT followee_id FROM follows WHERE follower_id = 1)
  AND deleted_at IS NULL ORDER BY id DESC LIMIT 500;

table    type  key                      ref                      rows  Extra
follows  ref   PRIMARY                  const                    33    Using temporary; Using filesort
posts    ref   posts_user_id_id_index   follows.followee_id      1     Using where
-- 16 ms for a user following 33 accounts incl. 3 celebrities (~10k candidate rows)

-- Fan-out follower walk (chunkById)
SELECT follower_id FROM follows WHERE followee_id = 500 AND follower_id > 0 ORDER BY follower_id LIMIT 1000;
follows  range  follows_followee_id_follower_id_index        Using where; Using index   ← covering

-- "Did I like these posts?"
SELECT post_id FROM likes WHERE user_id = 1 AND post_id IN (…);
likes    range  PRIMARY                                      Using where

-- Author timeline rebuild
SELECT id FROM posts WHERE user_id = 2 AND deleted_at IS NULL ORDER BY id DESC LIMIT 500;
posts    ref    posts_user_id_id_index
```

The filesort in the pull query sorts the union of every followee's posts. It grows with the number of accounts followed, which is why the pull query stays off the hot path.

---

## Scaling strategy (5M users, 500k posts/day)

Back-of-the-envelope numbers (details in `Architecture/Architecture.md`):

| Metric | Value |
|---|---|
| Posts/sec | ~6 average, ~60 at peak |
| Fan-out writes | ~100M/day, ~1.2k/sec average (~12k at peak): fine for Redis, far too much for an HTTP request |
| Feed reads | ~20M/day, ~230/sec average, 2–3k/sec at peak |
| Posts table | ~180M rows/year: every query must hit an index |
| Redis feed memory | 1M active feeds × 500 ids × ~60 B ≈ 30 GB, so Redis Cluster |

**Scaling feed generation.** Hybrid fan-out (above). Tuning knobs are `FEED_CELEBRITY_THRESHOLD`, `FEED_CAP`, `FEED_TTL_DAYS` and `FEED_CHUNK_SIZE`. Scale the `feed` Horizon supervisor horizontally with Redis queue depth. Only active users keep a feed in memory, because of the sliding TTL.

**Avoiding slow queries.** No joins or `COUNT(*)` on the hot path, cursor pagination instead of `OFFSET`, denormalized counters, and fixed-count hydration. Every query above is an index seek or a covering-index range.

**When to cache.** Cache what is read far more often than it changes and is shared across users: post bodies, author cards, like counters, celebrity lists. Precompute what is expensive to assemble (the per-user id lists). Never cache per-user state the user has just changed (`liked_by_me`) or whole responses that change on every write.

**When one box is not enough:**
1. **MySQL read replicas.** Use Laravel read/write connections with `sticky => true` so users see their own writes.
2. **Redis Cluster.** Keys are per user and per post, so they shard naturally. The Lua scripts touch one key each, which is cluster-safe.
3. **Partition `posts` and `likes` by `user_id`,** then move cold posts (older than N months) to archive storage.
4. **Hot-post likes.** Move from the column counter to Redis-only counters flushed on a schedule.
5. **Separate feed service.** Split the fan-out workers into their own service (e.g. Kafka-fed) only when the fan-out itself becomes a separate system.

---

## Differences from `Architecture/Architecture.md`

- **Laravel 13 / PHP 8.4** instead of Laravel 12 / PHP 8.3. The skeleton shipped with Laravel 13, and its Symfony 8 components need PHP ≥ 8.4.1.
- **Like counters have a 7-day TTL** instead of none, so 180M posts/year don't pin a counter in Redis forever. A miss reloads from `posts.likes_count`.
- **Notification idempotency** uses a deterministic UUIDv5 primary key of `(post_id, follower_id)` instead of an extra unique index. It gives the same guarantee without a second index on the largest table.
- **Celebrity status changes** are picked up within the `celebs:{user}` TTL (1h). When an author crosses the threshold, the fan-out stops right away. Followers whose cached celebrity list is from before the promotion won't see that author's new posts until the cache expires, which takes up to an hour.
- **Routes** live under the standard `/api` prefix.
