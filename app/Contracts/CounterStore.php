<?php

namespace App\Contracts;

/**
 * Cached like counters (likes:{post}). The posts.likes_count column is the
 * source of truth; a missing counter is reported as null and reloaded from it.
 */
interface CounterStore
{
    /**
     * @param  list<int>  $postIds
     * @return array<int, int|null> post id => count, null when not cached
     */
    public function many(array $postIds): array;

    /**
     * @param  array<int, int>  $counts  post id => count
     */
    public function putMany(array $counts): void;

    /**
     * Adjust a cached counter; a counter that is not cached stays uncached.
     */
    public function adjust(int $postId, int $by): void;

    public function forget(int $postId): void;
}
