<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched only after the post's transaction commits, so no worker ever
 * picks up a post that was rolled back.
 */
class PostCreated implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $postId,
        public readonly int $authorId,
    ) {}
}
