<?php

namespace App\Http\Controllers;

use App\Services\FollowService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * @group Follows
 */
class FollowController extends Controller
{
    public function __construct(private readonly FollowService $follows) {}

    /**
     * Follow user
     *
     * Idempotent. Returns 422 when trying to follow yourself.
     *
     * @urlParam user integer required The id of the user to follow. Example: 2
     *
     * @response 204 scenario="Followed"
     * @response 422 {"message": "You cannot follow yourself", "code": "CANNOT_FOLLOW_SELF", "errors": {}}
     * @response 404 {"message": "User not found", "code": "USER_NOT_FOUND", "errors": {}}
     */
    public function store(Request $request, int $user): Response
    {
        $this->follows->follow($request->user(), $user);

        return response()->noContent();
    }

    /**
     * Unfollow user
     *
     * Idempotent.
     *
     * @urlParam user integer required The id of the user to unfollow. Example: 2
     *
     * @response 204 scenario="Unfollowed"
     */
    public function destroy(Request $request, int $user): Response
    {
        $this->follows->unfollow($request->user(), $user);

        return response()->noContent();
    }
}
