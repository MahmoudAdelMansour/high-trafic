<?php

namespace App\Exceptions;

class InvalidCredentialsException extends ApiException
{
    public function __construct()
    {
        parent::__construct('The provided credentials are incorrect', 'INVALID_CREDENTIALS', 401);
    }
}
