<?php

namespace App\Repositories;

use Illuminate\Support\Facades\DB;

class LikeRepository
{
    /**
     * Returns true only when a new like row was actually inserted, so a
     * double tap never counts twice.
     */
    public function like(int $userId, int $postId): bool
    {
        $inserted = DB::table('likes')->insertOrIgnore([
            'user_id' => $userId,
            'post_id' => $postId,
            'created_at' => now(),
        ]);

        if ($inserted === 0) {
            return false;
        }

        DB::table('posts')->where('id', $postId)->increment('likes_count');

        return true;
    }

    public function unlike(int $userId, int $postId): bool
    {
        $deleted = DB::table('likes')
            ->where('user_id', $userId)
            ->where('post_id', $postId)
            ->delete();

        if ($deleted === 0) {
            return false;
        }

        DB::table('posts')
            ->where('id', $postId)
            ->where('likes_count', '>', 0)
            ->decrement('likes_count');

        return true;
    }

    /**
     * "Did I like these posts?" in one query on the (user_id, post_id) PK.
     *
     * @param  list<int>  $postIds
     * @return list<int>
     */
    public function likedPostIds(int $userId, array $postIds): array
    {
        if ($postIds === []) {
            return [];
        }

        return DB::table('likes')
            ->where('user_id', $userId)
            ->whereIn('post_id', $postIds)
            ->pluck('post_id')
            ->map(intval(...))
            ->all();
    }
}
