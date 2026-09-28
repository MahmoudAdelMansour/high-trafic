<?php

namespace App\Services;

use App\Contracts\FeedStore;
use App\Data\CreatePostData;
use App\Data\PostView;
use App\Events\PostCreated;
use App\Exceptions\PostNotFoundException;
use App\Models\Post;
use App\Models\User;
use App\Repositories\PostRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class PostService
{
    public function __construct(
        private readonly PostRepository $posts,
        private readonly PostHydrator $hydrator,
        private readonly FeedStore $feeds,
    ) {}

    /**
     * Persist first (MySQL is the source of truth), then publish. Fan-out and
     * notifications happen in the queue once PostCreated is dispatched after
     * commit, so the request never touches follower feeds.
     */
    public function create(User $author, CreatePostData $data): PostView
    {
        $post = DB::transaction(fn (): Post => $this->posts->create($data->authorId, $data->content));

        DB::afterCommit(fn () => $this->feeds->addToTimeline($post->user_id, $post->id));

        PostCreated::dispatch($post->id, $post->user_id);

        return new PostView(
            id: $post->id,
            content: $post->content,
            createdAt: CarbonImmutable::parse($post->created_at),
            authorId: $author->id,
            authorName: $author->name,
            likesCount: 0,
            likedByMe: false,
        );
    }

    /**
     * @throws PostNotFoundException
     */
    public function find(int $postId, User $viewer): PostView
    {
        return $this->hydrator->hydrate([$postId], $viewer)[0]
            ?? throw new PostNotFoundException;
    }

    /**
     * Soft delete and bust the caches. Follower feeds are cleaned lazily: the
     * hydrator skips the id and the feed cap pushes it out eventually.
     */
    public function delete(Post $post): void
    {
        $post->delete();

        $this->feeds->removeFromTimeline($post->user_id, $post->id);
        $this->hydrator->forgetPost($post->id);
    }
}
