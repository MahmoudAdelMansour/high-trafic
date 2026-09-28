<?php

namespace App\Data;

readonly class FeedPage
{
    /**
     * @param  list<PostView>  $posts
     */
    public function __construct(
        public array $posts,
        public ?int $nextCursor,
        public bool $hasMore,
    ) {}
}
