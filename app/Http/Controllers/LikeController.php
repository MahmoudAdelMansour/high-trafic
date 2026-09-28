<?php

namespace App\Http\Controllers;

use App\Services\LikeService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * @group Likes
 */
class LikeController extends Controller
{
    public function __construct(private readonly LikeService $likes) {}

    /**
     * Like post
     *
     * Idempotent: liking twice counts once.
     *
     * @urlParam post integer required The post id. Example: 1
     *
     * @response 204 scenario="Done"
     * @response 404 {"message": "Post not found", "code": "POST_NOT_FOUND", "errors": {}}
     */
    public function store(Request $request, int $post): Response
    {
        $this->likes->like($request->user(), $post);

        return response()->noContent();
    }

    /**
     * Unlike post
     *
     * Idempotent: unliking a post you never liked is a no-op.
     *
     * @urlParam post integer required The post id. Example: 1
     *
     * @response 204 scenario="Done"
     * @response 404 {"message": "Post not found", "code": "POST_NOT_FOUND", "errors": {}}
     */
    public function destroy(Request $request, int $post): Response
    {
        $this->likes->unlike($request->user(), $post);

        return response()->noContent();
    }
}
