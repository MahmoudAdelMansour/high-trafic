<?php

namespace App\Repositories;

use Illuminate\Support\Facades\DB;

class FollowRepository
{
    /**
     * Returns true only when a new follow row was inserted; the counters move
     * only in that case so repeating the call is harmless.
     */
    public function follow(int $followerId, int $followeeId): bool
    {
        $inserted = DB::table('follows')->insertOrIgnore([
            'follower_id' => $followerId,
            'followee_id' => $followeeId,
            'created_at' => now(),
        ]);

        if ($inserted === 0) {
            return false;
        }

        DB::table('users')->where('id', $followeeId)->increment('followers_count');
        DB::table('users')->where('id', $followerId)->increment('following_count');

        return true;
    }

    public function unfollow(int $followerId, int $followeeId): bool
    {
        $deleted = DB::table('follows')
            ->where('follower_id', $followerId)
            ->where('followee_id', $followeeId)
            ->delete();

        if ($deleted === 0) {
            return false;
        }

        DB::table('users')->where('id', $followeeId)->where('followers_count', '>', 0)->decrement('followers_count');
        DB::table('users')->where('id', $followerId)->where('following_count', '>', 0)->decrement('following_count');

        return true;
    }

    /**
     * Followees of the user that are at or above the celebrity threshold.
     * A user follows few celebrities, so this is small and cached by callers.
     *
     * @return list<int>
     */
    public function celebrityFolloweeIds(int $followerId, int $threshold): array
    {
        return DB::table('follows')
            ->join('users', 'users.id', '=', 'follows.followee_id')
            ->where('follows.follower_id', $followerId)
            ->where('users.followers_count', '>=', $threshold)
            ->pluck('users.id')
            ->map(intval(...))
            ->all();
    }

    /**
     * Walk the followers of an author in keyset-paginated chunks, served by
     * the covering (followee_id, follower_id) index.
     *
     * @param  callable(list<int>): void  $callback
     */
    public function chunkFollowerIds(int $followeeId, int $size, callable $callback): void
    {
        DB::table('follows')
            ->where('followee_id', $followeeId)
            ->select('follower_id')
            ->chunkById($size, function ($rows) use ($callback): void {
                $callback($rows->pluck('follower_id')->map(intval(...))->all());
            }, 'follower_id');
    }
}
