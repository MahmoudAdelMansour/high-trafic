<?php

namespace App\Http\Controllers;

use App\Http\Requests\FeedRequest;
use App\Http\Resources\PostResource;
use App\Services\FeedService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * @group Feed
 */
class FeedController extends Controller
{
    public function __construct(private readonly FeedService $feed) {}

    /**
     * News feed
     *
     * Latest posts from the users you follow, newest first, with cursor pagination.
     * Pass `meta.next_cursor` back as `cursor` to load the next page.
     *
     * @queryParam cursor integer The `next_cursor` of the previous page. Example: 960
     * @queryParam limit integer Page size, 1 to 50. Defaults to 20. Example: 20
     *
     * @response {"data": [{"id": 961, "content": "Hello world", "created_at": "2026-09-28T12:00:00+00:00", "author": {"id": 2, "name": "Mohammed"}, "likes_count": 3, "liked_by_me": true}], "meta": {"next_cursor": 961, "has_more": true}}
     */
    public function __invoke(FeedRequest $request): AnonymousResourceCollection
    {
        $page = $this->feed->page($request->user(), $request->cursor(), $request->limit());

        return PostResource::collection($page->posts)->additional([
            'meta' => [
                'next_cursor' => $page->nextCursor,
                'has_more' => $page->hasMore,
            ],
        ]);
    }
}
