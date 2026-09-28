<?php

namespace App\Exceptions;

class CannotFollowSelfException extends ApiException
{
    public function __construct()
    {
        parent::__construct('You cannot follow yourself', 'CANNOT_FOLLOW_SELF', 422);
    }
}
