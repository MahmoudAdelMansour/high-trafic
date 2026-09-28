<?php

namespace App\Services;

use App\Contracts\FeedStore;
use App\Data\FeedPage;
use App\Models\User;
use App\Repositories\PostRepository;
use Illuminate\Container\Attributes\Config;

/**
 * Hybrid feed read path:
 *
 *   1. ids from the reader's precomputed feed (fan-out on write)
 *   2. + ids from the timelines of the celebrities they follow (fan-out on read)
 *   3. merge newest first below the cursor, hydrate in a fixed number of queries
 *
 * Redis is only a cache: a missing feed is rebuilt from MySQL, and a reader
 * who scrolls past the capped feed is served straight from MySQL.
 */
class FeedService
{
    public function __construct(
        private readonly FeedStore $feeds,
        private readonly PostRepository $posts,
        private readonly FollowService $follows,
        private readonly PostHydrator $hydrator,
        #[Config('feed.cap')] private readonly int $cap,
        #[Config('feed.overfetch')] private readonly int $overfetch,
    ) {}

    public function page(User $reader, ?int $cursor, int $limit): FeedPage
    {
        $take = $limit + $this->overfetch;
        $candidates = $this->candidateIds($reader->id, $cursor, $take);

        $posts = $this->hydrator->hydrate($candidates, $reader);
        $page = array_slice($posts, 0, $limit);

        // Candidates filled the window: there may be more below it.
        $hasMore = count($posts) > $limit || count($candidates) === $take;

        $nextCursor = match (true) {
            ! $hasMore => null,
            count($posts) > $limit => end($page)->id,
            default => end($candidates),
        };

        return new FeedPage($page, $nextCursor, $hasMore);
    }

    /**
     * Newest-first, de-duplicated post ids strictly below the cursor.
     *
     * @return list<int>
     */
    public function candidateIds(int $readerId, ?int $cursor, int $take): array
    {
        $ids = $this->feedIds($readerId, $cursor, $take);

        foreach ($this->follows->celebrityFolloweeIds($readerId) as $celebrityId) {
            array_push($ids, ...$this->timelineIds($celebrityId, $cursor, $take));
        }

        $ids = array_unique($ids);
        rsort($ids);

        return array_slice($ids, 0, $take);
    }

    /**
     * @return list<int>
     */
    private function feedIds(int $readerId, ?int $cursor, int $take): array
    {
        $ids = $this->feeds->feed($readerId, $cursor, $take);

        if ($ids === null) {
            $all = $this->posts->followedPostIds($readerId, null, $this->cap);
            $this->feeds->rebuildFeed($readerId, $all);

            return $this->window($all, $cursor, $take);
        }

        // Short page from a full feed: the reader scrolled past the cap.
        if (count($ids) < $take && $this->feeds->feedSize($readerId) >= $this->cap) {
            return $this->posts->followedPostIds($readerId, $cursor, $take);
        }

        return $ids;
    }

    /**
     * @return list<int>
     */
    private function timelineIds(int $authorId, ?int $cursor, int $take): array
    {
        $ids = $this->feeds->timeline($authorId, $cursor, $take);

        if ($ids === null) {
            $all = $this->posts->latestIdsByAuthor($authorId, $this->cap);
            $this->feeds->rebuildTimeline($authorId, $all);

            return $this->window($all, $cursor, $take);
        }

        return $ids;
    }

    /**
     * @param  list<int>  $newestFirst
     * @return list<int>
     */
    private function window(array $newestFirst, ?int $cursor, int $take): array
    {
        if ($cursor !== null) {
            $newestFirst = array_filter($newestFirst, fn (int $id): bool => $id < $cursor);
        }

        return array_slice(array_values($newestFirst), 0, $take);
    }
}
