<?php

namespace App\Jobs;

use App\Contracts\FeedStore;
use App\Models\User;
use App\Repositories\PostRepository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * After a follow, copy the followee's latest posts into the follower's feed
 * so the feed changes right away instead of on the followee's next post.
 */
class BackfillFeed implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 30, 120];

    public function __construct(
        public readonly int $followerId,
        public readonly int $followeeId,
    ) {
        $this->onQueue(config('feed.queues.feed'));
        $this->afterCommit();
    }

    public function handle(FeedStore $feeds, PostRepository $posts): void
    {
        $followee = User::query()->find($this->followeeId, ['id', 'followers_count']);

        // Celebrities are merged on read; nothing to copy.
        if ($followee === null || $followee->isCelebrity()) {
            return;
        }

        $size = config('feed.backfill_size');
        $postIds = $feeds->timeline($this->followeeId, null, $size)
            ?? $posts->latestIdsByAuthor($this->followeeId, $size);

        $feeds->addToFeed($this->followerId, $postIds);
    }
}
