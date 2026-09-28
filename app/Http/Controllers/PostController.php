<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePostRequest;
use App\Http\Resources\PostResource;
use App\Models\Post;
use App\Services\PostService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * @group Posts
 */
class PostController extends Controller
{
    public function __construct(private readonly PostService $posts) {}

    /**
     * Create post
     *
     * Returns immediately; fan-out to follower feeds and notifications run in the queue.
     *
     * @response 201 {"data": {"id": 961, "content": "Hello world", "created_at": "2026-09-28T12:00:00+00:00", "author": {"id": 2, "name": "Mohammed"}, "likes_count": 0, "liked_by_me": false}}
     */
    public function store(StorePostRequest $request): JsonResponse
    {
        $post = $this->posts->create($request->user(), $request->toData());

        return PostResource::make($post)->response()->setStatusCode(201);
    }

    /**
     * View post
     *
     * Includes the author, the total likes and whether the current user liked it.
     *
     * @urlParam post integer required The post id. Example: 1
     *
     * @response {"data": {"id": 961, "content": "Hello world", "created_at": "2026-09-28T12:00:00+00:00", "author": {"id": 2, "name": "Mohammed"}, "likes_count": 3, "liked_by_me": true}}
     * @response 404 {"message": "Post not found", "code": "POST_NOT_FOUND", "errors": {}}
     */
    public function show(Request $request, int $post): PostResource
    {
        return PostResource::make($this->posts->find($post, $request->user()));
    }

    /**
     * Delete post
     *
     * Only the author can delete a post.
     *
     * @urlParam post integer required The post id. Example: 1
     *
     * @response 204 scenario="Deleted"
     * @response 403 {"message": "This action is unauthorized.", "code": "FORBIDDEN", "errors": {}}
     */
    public function destroy(Post $post): Response
    {
        $this->posts->delete($post);

        return response()->noContent();
    }
}
