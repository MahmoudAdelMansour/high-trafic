<?php

namespace App\Contracts;

/**
 * Storage for the precomputed read models of the feed:
 *
 *   feed:{user}      ids of posts pushed to this user by the fan-out (capped)
 *   timeline:{user}  ids of the user's own posts (celebrity merge + backfill)
 *
 * The store is a cache, never the source of truth: a missing list is reported
 * as null so the caller can rebuild it from MySQL. Writes to a list that does
 * not exist are ignored, otherwise a partial list would look complete.
 */
interface FeedStore
{
    /**
     * Newest-first post ids of the user's feed strictly older than $before,
     * or null when the feed is not materialized. Reading extends its TTL.
     *
     * @return list<int>|null
     */
    public function feed(int $userId, ?int $before, int $limit): ?array;

    /**
     * Number of ids currently held in the user's feed.
     */
    public function feedSize(int $userId): int;

    /**
     * Replace the user's feed with the given ids (an empty list is valid).
     *
     * @param  list<int>  $postIds
     */
    public function rebuildFeed(int $userId, array $postIds): void;

    /**
     * Push one post into the feeds of many users, in one round trip.
     *
     * @param  list<int>  $userIds
     */
    public function pushToFeeds(array $userIds, int $postId): void;

    /**
     * Add posts to a single user's feed.
     *
     * @param  list<int>  $postIds
     */
    public function addToFeed(int $userId, array $postIds): void;

    /**
     * @param  list<int>  $postIds
     */
    public function removeFromFeed(int $userId, array $postIds): void;

    /**
     * Newest-first ids of the author's own posts, or null when not materialized.
     *
     * @return list<int>|null
     */
    public function timeline(int $authorId, ?int $before, int $limit): ?array;

    /**
     * @param  list<int>  $postIds
     */
    public function rebuildTimeline(int $authorId, array $postIds): void;

    public function addToTimeline(int $authorId, int $postId): void;

    public function removeFromTimeline(int $authorId, int $postId): void;
}
