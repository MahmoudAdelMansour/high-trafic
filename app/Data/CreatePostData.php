<?php

namespace App\Data;

readonly class CreatePostData
{
    public function __construct(
        public int $authorId,
        public string $content,
    ) {}
}
