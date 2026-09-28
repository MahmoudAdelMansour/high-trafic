<?php

namespace App\Stores;

use App\Contracts\FeedStore;

/**
 * Process-local FeedStore with the same semantics as RedisFeedStore. Used by
 * the test suite so feed logic can be exercised without a Redis server.
 */
class InMemoryFeedStore implements FeedStore
{
    /** @var array<string, array<int, true>> */
    private array $lists = [];

    public function __construct(private readonly int $cap) {}

    public function feed(int $userId, ?int $before, int $limit): ?array
    {
        return $this->range("feed:{$userId}", $before, $limit);
    }

    public function feedSize(int $userId): int
    {
        return count($this->lists["feed:{$userId}"] ?? []);
    }

    public function rebuildFeed(int $userId, array $postIds): void
    {
        $this->replace("feed:{$userId}", $postIds);
    }

    public function pushToFeeds(array $userIds, int $postId): void
    {
        foreach ($userIds as $userId) {
            $this->addIfExists("feed:{$userId}", [$postId]);
        }
    }

    public function addToFeed(int $userId, array $postIds): void
    {
        $this->addIfExists("feed:{$userId}", $postIds);
    }

    public function removeFromFeed(int $userId, array $postIds): void
    {
        foreach ($postIds as $postId) {
            unset($this->lists["feed:{$userId}"][$postId]);
        }
    }

    public function timeline(int $authorId, ?int $before, int $limit): ?array
    {
        return $this->range("timeline:{$authorId}", $before, $limit);
    }

    public function rebuildTimeline(int $authorId, array $postIds): void
    {
        $this->replace("timeline:{$authorId}", $postIds);
    }

    public function addToTimeline(int $authorId, int $postId): void
    {
        $this->addIfExists("timeline:{$authorId}", [$postId]);
    }

    public function removeFromTimeline(int $authorId, int $postId): void
    {
        unset($this->lists["timeline:{$authorId}"][$postId]);
    }

    /**
     * Drop every list, as if Redis had been restarted.
     */
    public function flush(): void
    {
        $this->lists = [];
    }

    /**
     * @return list<int>|null
     */
    private function range(string $key, ?int $before, int $limit): ?array
    {
        if (! isset($this->lists[$key])) {
            return null;
        }

        $ids = array_keys($this->lists[$key]);
        rsort($ids);

        if ($before !== null) {
            $ids = array_filter($ids, fn (int $id): bool => $id < $before);
        }

        return array_slice(array_values($ids), 0, $limit);
    }

    /**
     * @param  list<int>  $postIds
     */
    private function replace(string $key, array $postIds): void
    {
        $this->lists[$key] = [];
        $this->addIds($key, $postIds);
    }

    /**
     * @param  list<int>  $postIds
     */
    private function addIfExists(string $key, array $postIds): void
    {
        if (isset($this->lists[$key])) {
            $this->addIds($key, $postIds);
        }
    }

    /**
     * @param  list<int>  $postIds
     */
    private function addIds(string $key, array $postIds): void
    {
        foreach ($postIds as $postId) {
            $this->lists[$key][$postId] = true;
        }

        krsort($this->lists[$key]);
        $this->lists[$key] = array_slice($this->lists[$key], 0, $this->cap, true);
    }
}
