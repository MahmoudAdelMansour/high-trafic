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
     */
    public function show(Request $request, int $post): PostResource
    {
        return PostResource::make($this->posts->find($post, $request->user()));
    }

    /**
     * Delete post
     *
     * Only the author can delete a post.
     */
    public function destroy(Post $post): Response
    {
        $this->posts->delete($post);

        return response()->noContent();
    }
}
