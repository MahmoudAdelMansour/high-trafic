<?php

namespace App\Services;

use App\Contracts\CounterStore;
use App\Exceptions\PostNotFoundException;
use App\Models\User;
use App\Repositories\LikeRepository;
use App\Repositories\PostRepository;
use Illuminate\Support\Facades\DB;

class LikeService
{
    public function __construct(
        private readonly LikeRepository $likes,
        private readonly PostRepository $posts,
        private readonly CounterStore $counters,
    ) {}

    /**
     * Idempotent: liking twice keeps a single like and a single count.
     *
     * @throws PostNotFoundException
     */
    public function like(User $user, int $postId): void
    {
        $this->ensurePostExists($postId);

        $liked = DB::transaction(fn (): bool => $this->likes->like($user->id, $postId));

        if ($liked) {
            DB::afterCommit(fn () => $this->counters->adjust($postId, 1));
        }
    }

    /**
     * Idempotent: unliking a post that was never liked is a no-op.
     *
     * @throws PostNotFoundException
     */
    public function unlike(User $user, int $postId): void
    {
        $this->ensurePostExists($postId);

        $unliked = DB::transaction(fn (): bool => $this->likes->unlike($user->id, $postId));

        if ($unliked) {
            DB::afterCommit(fn () => $this->counters->adjust($postId, -1));
        }
    }

    private function ensurePostExists(int $postId): void
    {
        if (! $this->posts->exists($postId)) {
            throw new PostNotFoundException;
        }
    }
}
