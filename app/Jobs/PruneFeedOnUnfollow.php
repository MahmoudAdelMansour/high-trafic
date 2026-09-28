<?php

namespace App\Jobs;

use App\Contracts\FeedStore;
use App\Repositories\PostRepository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * After an unfollow, remove the followee's posts from the follower's feed.
 * Only the newest `cap` posts can be in a feed, so that is all we look at.
 */
class PruneFeedOnUnfollow implements ShouldQueue
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
        $postIds = $posts->latestIdsByAuthor($this->followeeId, config('feed.cap'), withTrashed: true);

        $feeds->removeFromFeed($this->followerId, $postIds);
    }
}
