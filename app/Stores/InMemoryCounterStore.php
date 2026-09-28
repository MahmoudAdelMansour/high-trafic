<?php

namespace App\Stores;

use App\Contracts\CounterStore;

class InMemoryCounterStore implements CounterStore
{
    /** @var array<int, int> */
    private array $counts = [];

    public function many(array $postIds): array
    {
        $counts = [];

        foreach ($postIds as $postId) {
            $counts[$postId] = $this->counts[$postId] ?? null;
        }

        return $counts;
    }

    public function putMany(array $counts): void
    {
        foreach ($counts as $postId => $count) {
            $this->counts[$postId] = $count;
        }
    }

    public function adjust(int $postId, int $by): void
    {
        if (isset($this->counts[$postId])) {
            $this->counts[$postId] += $by;
        }
    }

    public function forget(int $postId): void
    {
        unset($this->counts[$postId]);
    }
}
