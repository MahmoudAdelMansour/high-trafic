<?php

namespace App\Services;

use App\Contracts\CounterStore;
use App\Data\PostView;
use App\Models\User;
use App\Repositories\LikeRepository;
use App\Repositories\PostRepository;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Turns a list of post ids into PostViews with a fixed number of queries,
 * whatever the page size or number of distinct authors:
 *
 *   posts    entity cache post:{id}, misses in one WHERE id IN (...)
 *   counts   counter store likes:{id}, misses from the likes_count column
 *   authors  entity cache user:{id}:card, misses in one WHERE id IN (...)
 *   liked?   one query on the likes primary key (never cached)
 */
class PostHydrator
{
    public function __construct(
        private readonly Cache $cache,
        private readonly CounterStore $counters,
        private readonly PostRepository $posts,
        private readonly LikeRepository $likes,
    ) {}

    /**
     * Ids that no longer exist (e.g. soft-deleted) are skipped; order is kept.
     *
     * @param  list<int>  $postIds
     * @return list<PostView>
     */
    public function hydrate(array $postIds, ?User $viewer): array
    {
        if ($postIds === []) {
            return [];
        }

        [$posts, $freshCounts] = $this->loadPosts($postIds);

        $existingIds = array_values(array_filter($postIds, fn (int $id): bool => isset($posts[$id])));

        if ($existingIds === []) {
            return [];
        }

        $counts = $freshCounts + $this->loadCounts(array_values(array_diff($existingIds, array_keys($freshCounts))));
        $authors = $this->loadAuthors(array_values(array_unique(array_column(array_intersect_key($posts, array_flip($existingIds)), 'user_id'))));
        $liked = $viewer === null ? [] : array_flip($this->likes->likedPostIds($viewer->id, $existingIds));

        return array_map(fn (int $id): PostView => new PostView(
            id: $id,
            content: $posts[$id]['content'],
            createdAt: CarbonImmutable::parse($posts[$id]['created_at']),
            authorId: $posts[$id]['user_id'],
            authorName: $authors[$posts[$id]['user_id']] ?? '',
            likesCount: $counts[$id] ?? 0,
            likedByMe: isset($liked[$id]),
        ), $existingIds);
    }

    public function forgetPost(int $postId): void
    {
        $this->cache->forget($this->postKey($postId));
        $this->counters->forget($postId);
    }

    /**
     * @param  list<int>  $postIds
     * @return array{0: array<int, array{id: int, user_id: int, content: string, created_at: string}>, 1: array<int, int>}
     */
    private function loadPosts(array $postIds): array
    {
        $posts = [];

        foreach ($this->cache->many(array_map($this->postKey(...), $postIds)) as $cached) {
            if (is_array($cached)) {
                $posts[$cached['id']] = $cached;
            }
        }

        $missing = array_values(array_diff($postIds, array_keys($posts)));
        $freshCounts = [];

        if ($missing !== []) {
            $toCache = [];

            foreach ($this->posts->findMany($missing) as $post) {
                $posts[$post->id] = $toCache[$this->postKey($post->id)] = [
                    'id' => $post->id,
                    'user_id' => $post->user_id,
                    'content' => $post->content,
                    'created_at' => $post->created_at->toIso8601String(),
                ];
                $freshCounts[$post->id] = $post->likes_count;
            }

            if ($toCache !== []) {
                $this->cache->putMany($toCache, config('feed.cache_ttl.post'));
                $this->counters->putMany($freshCounts);
            }
        }

        return [$posts, $freshCounts];
    }

    /**
     * @param  list<int>  $postIds
     * @return array<int, int>
     */
    private function loadCounts(array $postIds): array
    {
        if ($postIds === []) {
            return [];
        }

        $counts = array_filter($this->counters->many($postIds), fn (?int $count): bool => $count !== null);
        $missing = array_values(array_diff($postIds, array_keys($counts)));

        if ($missing !== []) {
            $fromDatabase = $this->posts->likeCounts($missing);
            $this->counters->putMany($fromDatabase);
            $counts += $fromDatabase;
        }

        return $counts;
    }

    /**
     * @param  list<int>  $userIds
     * @return array<int, string> user id => name
     */
    private function loadAuthors(array $userIds): array
    {
        $names = [];

        foreach ($this->cache->many(array_map($this->userCardKey(...), $userIds)) as $card) {
            if (is_array($card)) {
                $names[$card['id']] = $card['name'];
            }
        }

        $missing = array_values(array_diff($userIds, array_keys($names)));

        if ($missing !== []) {
            $toCache = [];

            foreach (User::query()->whereKey($missing)->get(['id', 'name']) as $user) {
                $names[$user->id] = $user->name;
                $toCache[$this->userCardKey($user->id)] = ['id' => $user->id, 'name' => $user->name];
            }

            if ($toCache !== []) {
                $this->cache->putMany($toCache, config('feed.cache_ttl.user_card'));
            }
        }

        return $names;
    }

    private function postKey(int $postId): string
    {
        return "post:{$postId}";
    }

    private function userCardKey(int $userId): string
    {
        return "user:{$userId}:card";
    }
}
