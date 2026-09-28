<?php

namespace App\Exceptions;

class PostNotFoundException extends ApiException
{
    public function __construct()
    {
        parent::__construct('Post not found', 'POST_NOT_FOUND', 404);
    }
}
