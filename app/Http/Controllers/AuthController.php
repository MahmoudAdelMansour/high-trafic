<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;

/**
 * @group Authentication
 */
class AuthController extends Controller
{
    public function __construct(private readonly AuthService $auth) {}

    /**
     * Register
     *
     * Creates a user and returns a Sanctum bearer token.
     *
     * @unauthenticated
     *
     * @response 201 {"data": {"id": 1, "name": "Ahmed", "email": "ahmed@example.com", "followers_count": 0, "following_count": 0, "created_at": "2026-09-28T12:00:00+00:00"}, "token": "1|pZ8x..."}
     * @response 422 {"message": "The email has already been taken.", "code": "VALIDATION_FAILED", "errors": {"email": ["The email has already been taken."]}}
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        ['user' => $user, 'token' => $token] = $this->auth->register($request->validated());

        return UserResource::make($user)
            ->additional(['token' => $token])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Login
     *
     * Exchanges credentials for a Sanctum bearer token. Throttled to 5 attempts per minute.
     *
     * @unauthenticated
     *
     * @response {"data": {"id": 1, "name": "Ahmed", "email": "ahmed@example.com", "followers_count": 12, "following_count": 300, "created_at": "2026-09-28T12:00:00+00:00"}, "token": "2|kQ3v..."}
     * @response 401 {"message": "The provided credentials are incorrect", "code": "INVALID_CREDENTIALS", "errors": {}}
     * @response 429 {"message": "Too Many Attempts.", "code": "TOO_MANY_REQUESTS", "errors": {}}
     */
    public function login(LoginRequest $request): JsonResponse
    {
        ['user' => $user, 'token' => $token] = $this->auth->login(
            $request->validated('email'),
            $request->validated('password'),
        );

        return UserResource::make($user)->additional(['token' => $token])->response();
    }
}
