<?php

namespace App\Data;

use Carbon\CarbonImmutable;

/**
 * A fully hydrated post as the API returns it, independent of where each
 * piece came from (entity cache, counter store or MySQL).
 */
readonly class PostView
{
    public function __construct(
        public int $id,
        public string $content,
        public CarbonImmutable $createdAt,
        public int $authorId,
        public string $authorName,
        public int $likesCount,
        public bool $likedByMe,
    ) {}
}
