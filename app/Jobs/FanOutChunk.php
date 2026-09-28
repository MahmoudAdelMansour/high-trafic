<?php

namespace App\Jobs;

use App\Contracts\FeedStore;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Pushes one post into up to chunk_size feeds in a single Redis pipeline.
 * ZADD is idempotent, so retries are safe.
 */
class FanOutChunk implements ShouldQueue
{
    use Batchable, Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 30, 120];

    /**
     * @param  list<int>  $followerIds
     */
    public function __construct(
        public readonly int $postId,
        public readonly array $followerIds,
    ) {}

    public function handle(FeedStore $feeds): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $feeds->pushToFeeds($this->followerIds, $this->postId);
    }
}
