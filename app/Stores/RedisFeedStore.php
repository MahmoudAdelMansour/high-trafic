<?php

namespace App\Stores;

use App\Contracts\FeedStore;
use Illuminate\Redis\Connections\Connection;
use Redis;

/**
 * Feeds and timelines as Redis sorted sets (member = score = post id).
 *
 * Every materialized list holds a sentinel member "0" with score 0, so an
 * empty-but-built feed still exists as a key. Reads use the score range
 * (0, +inf) so the sentinel never leaves Redis.
 */
class RedisFeedStore implements FeedStore
{
    /**
     * ARGV[1] = cap, ARGV[2..] = post ids. Adds only when the key exists and
     * trims to sentinel + newest cap ids.
     */
    private const string ADD_IF_EXISTS = <<<'LUA'
        if redis.call('EXISTS', KEYS[1]) == 0 then return 0 end
        for i = 2, #ARGV do redis.call('ZADD', KEYS[1], ARGV[i], ARGV[i]) end
        redis.call('ZREMRANGEBYRANK', KEYS[1], 1, -(tonumber(ARGV[1]) + 1))
        return 1
        LUA;

    private const string SENTINEL = '0';

    public function __construct(
        private readonly Connection $connection,
        private readonly int $cap,
        private readonly int $ttlSeconds,
    ) {}

    public function feed(int $userId, ?int $before, int $limit): ?array
    {
        return $this->range($this->feedKey($userId), $before, $limit);
    }

    public function feedSize(int $userId): int
    {
        return max(0, (int) $this->connection->zcard($this->feedKey($userId)) - 1);
    }

    public function rebuildFeed(int $userId, array $postIds): void
    {
        $this->replace($this->feedKey($userId), $postIds);
    }

    public function pushToFeeds(array $userIds, int $postId): void
    {
        if ($userIds === []) {
            return;
        }

        $this->connection->pipeline(function (Redis $pipe) use ($userIds, $postId): void {
            foreach ($userIds as $userId) {
                $pipe->eval(self::ADD_IF_EXISTS, [$this->feedKey($userId), $this->cap, $postId], 1);
            }
        });
    }

    public function addToFeed(int $userId, array $postIds): void
    {
        $this->addIfExists($this->feedKey($userId), $postIds);
    }

    public function removeFromFeed(int $userId, array $postIds): void
    {
        if ($postIds !== []) {
            $this->connection->zrem($this->feedKey($userId), ...$postIds);
        }
    }

    public function timeline(int $authorId, ?int $before, int $limit): ?array
    {
        return $this->range($this->timelineKey($authorId), $before, $limit);
    }

    public function rebuildTimeline(int $authorId, array $postIds): void
    {
        $this->replace($this->timelineKey($authorId), $postIds);
    }

    public function addToTimeline(int $authorId, int $postId): void
    {
        $this->addIfExists($this->timelineKey($authorId), [$postId]);
    }

    public function removeFromTimeline(int $authorId, int $postId): void
    {
        $this->connection->zrem($this->timelineKey($authorId), $postId);
    }

    /**
     * @return list<int>|null
     */
    private function range(string $key, ?int $before, int $limit): ?array
    {
        [$exists, $ids] = $this->connection->pipeline(function (Redis $pipe) use ($key, $before, $limit): void {
            $pipe->expire($key, $this->ttlSeconds);
            $pipe->zRevRangeByScore($key, $before === null ? '+inf' : '('.$before, '(0', ['limit' => [0, $limit]]);
        });

        if (! $exists) {
            return null;
        }

        return array_map(intval(...), $ids);
    }

    /**
     * @param  list<int>  $postIds
     */
    private function replace(string $key, array $postIds): void
    {
        $members = [0, self::SENTINEL];

        foreach (array_slice($postIds, 0, $this->cap) as $postId) {
            array_push($members, $postId, $postId);
        }

        $this->connection->transaction(function (Redis $tx) use ($key, $members): void {
            $tx->del($key);
            $tx->zAdd($key, ...$members);
            $tx->expire($key, $this->ttlSeconds);
        });
    }

    /**
     * @param  list<int>  $postIds
     */
    private function addIfExists(string $key, array $postIds): void
    {
        if ($postIds !== []) {
            $this->connection->eval(self::ADD_IF_EXISTS, 1, $key, $this->cap, ...$postIds);
        }
    }

    private function feedKey(int $userId): string
    {
        return "feed:{$userId}";
    }

    private function timelineKey(int $authorId): string
    {
        return "timeline:{$authorId}";
    }
}
