<?php

namespace App\Exceptions;

class UserNotFoundException extends ApiException
{
    public function __construct()
    {
        parent::__construct('User not found', 'USER_NOT_FOUND', 404);
    }
}
