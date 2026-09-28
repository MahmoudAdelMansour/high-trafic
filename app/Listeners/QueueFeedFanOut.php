<?php

namespace App\Listeners;

use App\Events\PostCreated;
use App\Jobs\FanOutPost;

class QueueFeedFanOut
{
    public function handle(PostCreated $event): void
    {
        FanOutPost::dispatch($event->postId, $event->authorId);
    }
}
