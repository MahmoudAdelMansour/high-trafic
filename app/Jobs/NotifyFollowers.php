<?php

namespace App\Jobs;

use App\Models\User;
use App\Repositories\FollowRepository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;

/**
 * Splits the author's followers into chunks and notifies each chunk with one
 * bulk insert. Runs on its own queue so slow notifications never delay the
 * feed; celebrity posts go to a low-priority queue.
 */
class NotifyFollowers implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 30, 120];

    public function __construct(
        public readonly int $postId,
        public readonly int $authorId,
    ) {
        $this->onQueue(config('feed.queues.notifications'));
    }

    public function handle(FollowRepository $follows): void
    {
        $author = User::query()->find($this->authorId, ['id', 'name', 'followers_count']);

        if ($author === null) {
            return;
        }

        $chunks = [];

        $follows->chunkFollowerIds($this->authorId, config('feed.chunk_size'), function (array $followerIds) use (&$chunks, $author): void {
            $chunks[] = new SendNotificationChunk($this->postId, $author->id, $author->name, $followerIds);
        });

        if ($chunks === []) {
            return;
        }

        Bus::batch($chunks)
            ->name("notify:post:{$this->postId}")
            ->onQueue($author->isCelebrity() ? config('feed.queues.notifications_low') : config('feed.queues.notifications'))
            ->allowFailures()
            ->dispatch();
    }
}
