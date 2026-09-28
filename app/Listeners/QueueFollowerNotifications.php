<?php

namespace App\Listeners;

use App\Events\PostCreated;
use App\Jobs\NotifyFollowers;

class QueueFollowerNotifications
{
    public function handle(PostCreated $event): void
    {
        NotifyFollowers::dispatch($event->postId, $event->authorId);
    }
}
