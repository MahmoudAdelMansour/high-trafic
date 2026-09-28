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
     */
    public function destroy(Request $request, int $user): Response
    {
        $this->follows->unfollow($request->user(), $user);

        return response()->noContent();
    }
}
