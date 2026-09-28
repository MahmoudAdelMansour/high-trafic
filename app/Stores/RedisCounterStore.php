<?php

namespace App\Stores;

use App\Contracts\CounterStore;
use Illuminate\Redis\Connections\Connection;
use Redis;

class RedisCounterStore implements CounterStore
{
    private const string INCR_IF_EXISTS = <<<'LUA'
        if redis.call('EXISTS', KEYS[1]) == 0 then return nil end
        return redis.call('INCRBY', KEYS[1], ARGV[1])
        LUA;

    public function __construct(
        private readonly Connection $connection,
        private readonly int $ttlSeconds,
    ) {}

    public function many(array $postIds): array
    {
        if ($postIds === []) {
            return [];
        }

        $values = $this->connection->mget(array_map($this->key(...), $postIds));

        $counts = [];

        foreach ($postIds as $index => $postId) {
            $counts[$postId] = $values[$index] === null ? null : (int) $values[$index];
        }

        return $counts;
    }

    public function putMany(array $counts): void
    {
        if ($counts === []) {
            return;
        }

        $this->connection->pipeline(function (Redis $pipe) use ($counts): void {
            foreach ($counts as $postId => $count) {
                $pipe->setex($this->key($postId), $this->ttlSeconds, $count);
            }
        });
    }

    public function adjust(int $postId, int $by): void
    {
        $this->connection->eval(self::INCR_IF_EXISTS, 1, $this->key($postId), $by);
    }

    public function forget(int $postId): void
    {
        $this->connection->del($this->key($postId));
    }

    private function key(int $postId): string
    {
        return "likes:{$postId}";
    }
}
