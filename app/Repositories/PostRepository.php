<?php

namespace App\Repositories;

use App\Models\Post;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;

class PostRepository
{
    public function create(int $authorId, string $content): Post
    {
        return Post::create(['user_id' => $authorId, 'content' => $content]);
    }

    public function exists(int $postId): bool
    {
        return Post::query()->whereKey($postId)->exists();
    }

    /**
     * The pull ("fan-out on read") feed query, used only as a fallback when
     * the Redis feed is missing or the reader scrolls past its cap.
     * Served by follows PK (follower_id, ...) and posts (user_id, id).
     *
     * @return list<int>
     */
    public function followedPostIds(int $followerId, ?int $before, int $limit): array
    {
        return Post::query()
            ->whereIn('user_id', fn (Builder $query) => $query
                ->select('followee_id')
                ->from('follows')
                ->where('follower_id', $followerId))
            ->when($before !== null, fn ($query) => $query->where('id', '<', $before))
            ->orderByDesc('id')
            ->limit($limit)
            ->pluck('id')
            ->map(intval(...))
            ->all();
    }

    /**
     * Newest post ids of one author, served by the (user_id, id) index.
     *
     * @return list<int>
     */
    public function latestIdsByAuthor(int $authorId, int $limit, bool $withTrashed = false): array
    {
        return Post::query()
            ->when($withTrashed, fn ($query) => $query->withTrashed())
            ->where('user_id', $authorId)
            ->orderByDesc('id')
            ->limit($limit)
            ->pluck('id')
            ->map(intval(...))
            ->all();
    }

    /**
     * @param  list<int>  $postIds
     * @return Collection<int, Post>
     */
    public function findMany(array $postIds): Collection
    {
        return Post::query()
            ->whereKey($postIds)
            ->get(['id', 'user_id', 'content', 'likes_count', 'created_at']);
    }

    /**
     * @param  list<int>  $postIds
     * @return array<int, int> post id => likes count
     */
    public function likeCounts(array $postIds): array
    {
        return Post::query()
            ->whereKey($postIds)
            ->pluck('likes_count', 'id')
            ->map(intval(...))
            ->all();
    }
}
