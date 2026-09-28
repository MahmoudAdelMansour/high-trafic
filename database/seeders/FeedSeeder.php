<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Bulk-seeds a realistic data set (defaults: 12k users, 3 celebrities,
 * 200k posts, ~400k follows, 100k likes) with raw chunked inserts, so the
 * pull query's EXPLAIN and the celebrity path can be shown on real volumes.
 *
 * Tune with the feed.seed config (SEED_USERS, SEED_POSTS, ...).
 */
class FeedSeeder extends Seeder
{
    private const int CHUNK = 5000;

    private const int CELEBRITIES = 3;

    public function run(): void
    {
        $users = config('feed.seed.users');
        $posts = config('feed.seed.posts');
        $followsPerUser = config('feed.seed.follows_per_user');
        $likes = config('feed.seed.likes');

        DB::connection()->disableQueryLog();

        $firstUserId = $this->seedUsers($users);
        $lastUserId = $firstUserId + $users - 1;
        $celebrityIds = range($firstUserId + 1, $firstUserId + self::CELEBRITIES);

        $this->seedFollows($firstUserId, $lastUserId, $celebrityIds, $followsPerUser);
        $this->seedPosts($firstUserId, $lastUserId, $celebrityIds, $posts);
        $this->seedLikes($firstUserId, $lastUserId, $likes);
        $this->recountCounters();

        $this->command?->info("Seeded {$users} users, {$posts} posts. Login: demo@example.com / password");
    }

    private function seedUsers(int $count): int
    {
        $password = Hash::make('password');
        $now = now();
        $start = (int) DB::table('users')->max('id') + 1;

        foreach (array_chunk(range(0, $count - 1), self::CHUNK) as $chunk) {
            DB::table('users')->insert(array_map(fn (int $i): array => [
                'name' => $i === 0 ? 'Demo User' : ($i <= self::CELEBRITIES ? "Celebrity {$i}" : fake()->name()),
                'email' => $i === 0 ? 'demo@example.com' : "user{$i}.".($start + $i).'@example.com',
                'password' => $password,
                'created_at' => $now,
                'updated_at' => $now,
            ], $chunk));
        }

        return $start;
    }

    /**
     * Every user follows all celebrities plus a random set of normal users.
     *
     * @param  list<int>  $celebrityIds
     */
    private function seedFollows(int $firstId, int $lastId, array $celebrityIds, int $perUser): void
    {
        $rows = [];
        $now = now();

        for ($followerId = $firstId; $followerId <= $lastId; $followerId++) {
            $followees = $celebrityIds;

            for ($n = 0; $n < $perUser; $n++) {
                $followees[] = random_int($firstId + self::CELEBRITIES + 1, $lastId);
            }

            foreach (array_unique($followees) as $followeeId) {
                if ($followeeId !== $followerId) {
                    $rows[] = ['follower_id' => $followerId, 'followee_id' => $followeeId, 'created_at' => $now];
                }
            }

            if (count($rows) >= self::CHUNK) {
                DB::table('follows')->insertOrIgnore($rows);
                $rows = [];
            }
        }

        DB::table('follows')->insertOrIgnore($rows);
    }

    /**
     * Posts spread over the last 30 days in id order; celebrities post more.
     *
     * @param  list<int>  $celebrityIds
     */
    private function seedPosts(int $firstId, int $lastId, array $celebrityIds, int $count): void
    {
        $start = Carbon::now()->subDays(30);
        $step = (30 * 86400) / max(1, $count);

        foreach (array_chunk(range(0, $count - 1), self::CHUNK) as $chunk) {
            DB::table('posts')->insert(array_map(function (int $i) use ($firstId, $lastId, $celebrityIds, $start, $step): array {
                $createdAt = $start->copy()->addSeconds((int) ($i * $step));

                return [
                    'user_id' => random_int(1, 20) === 1 ? $celebrityIds[array_rand($celebrityIds)] : random_int($firstId, $lastId),
                    'content' => fake()->text(random_int(40, 280)),
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ];
            }, $chunk));
        }
    }

    private function seedLikes(int $firstUserId, int $lastUserId, int $count): void
    {
        [$minPost, $maxPost] = [(int) DB::table('posts')->min('id'), (int) DB::table('posts')->max('id')];
        $now = now();

        foreach (array_chunk(range(1, $count), self::CHUNK) as $chunk) {
            DB::table('likes')->insertOrIgnore(array_map(fn (): array => [
                'user_id' => random_int($firstUserId, $lastUserId),
                'post_id' => random_int($minPost, $maxPost),
                'created_at' => $now,
            ], $chunk));
        }
    }

    /**
     * Recompute the denormalized counters in bulk once, instead of per row.
     */
    private function recountCounters(): void
    {
        DB::statement('UPDATE users u
            LEFT JOIN (SELECT followee_id, COUNT(*) c FROM follows GROUP BY followee_id) f ON f.followee_id = u.id
            LEFT JOIN (SELECT follower_id, COUNT(*) c FROM follows GROUP BY follower_id) g ON g.follower_id = u.id
            SET u.followers_count = COALESCE(f.c, 0), u.following_count = COALESCE(g.c, 0)');

        DB::statement('UPDATE posts p
            JOIN (SELECT post_id, COUNT(*) c FROM likes GROUP BY post_id) l ON l.post_id = p.id
            SET p.likes_count = l.c');
    }
}
