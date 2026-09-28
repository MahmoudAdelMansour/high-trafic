<?php

namespace App\Jobs;

use App\Models\Post;
use App\Models\User;
use App\Repositories\FollowRepository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;

/**
 * Hybrid fan-out: push the post id into every follower's feed, unless the
 * author is a celebrity, in which case readers merge the author's timeline.
 *
 * The followers are split into chunks, one FanOutChunk job each, so a failed
 * chunk retries on its own instead of repeating the whole fan-out.
 */
class FanOutPost implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 30, 120];

    public function __construct(
        public readonly int $postId,
        public readonly int $authorId,
    ) {
        $this->onQueue(config('feed.queues.feed'));
    }

    public function handle(FollowRepository $follows): void
    {
        $author = User::query()->find($this->authorId, ['id', 'followers_count']);

        if ($author === null || $author->isCelebrity() || ! Post::query()->whereKey($this->postId)->exists()) {
            return;
        }

        $chunks = [];

        $follows->chunkFollowerIds($this->authorId, config('feed.chunk_size'), function (array $followerIds) use (&$chunks): void {
            $chunks[] = new FanOutChunk($this->postId, $followerIds);
        });

        if ($chunks === []) {
            return;
        }

        Bus::batch($chunks)
            ->name("fan-out:post:{$this->postId}")
            ->onQueue(config('feed.queues.feed'))
            ->allowFailures()
            ->dispatch();
    }
}
