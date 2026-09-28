<?php

namespace App\Services;

use App\Exceptions\CannotFollowSelfException;
use App\Exceptions\UserNotFoundException;
use App\Jobs\BackfillFeed;
use App\Jobs\PruneFeedOnUnfollow;
use App\Models\User;
use App\Repositories\FollowRepository;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\DB;

class FollowService
{
    public function __construct(
        private readonly FollowRepository $follows,
        private readonly Cache $cache,
    ) {}

    /**
     * Idempotent. The follow row and both counters change in one transaction;
     * the feed backfill happens in the queue.
     *
     * @throws CannotFollowSelfException
     * @throws UserNotFoundException
     */
    public function follow(User $follower, int $followeeId): void
    {
        $this->guard($follower, $followeeId);

        $followed = DB::transaction(fn (): bool => $this->follows->follow($follower->id, $followeeId));

        if ($followed) {
            $this->forgetCelebrities($follower->id);
            BackfillFeed::dispatch($follower->id, $followeeId);
        }
    }

    /**
     * Idempotent. Old posts leave the follower's feed in the background.
     *
     * @throws CannotFollowSelfException
     * @throws UserNotFoundException
     */
    public function unfollow(User $follower, int $followeeId): void
    {
        $this->guard($follower, $followeeId);

        $unfollowed = DB::transaction(fn (): bool => $this->follows->unfollow($follower->id, $followeeId));

        if ($unfollowed) {
            $this->forgetCelebrities($follower->id);
            PruneFeedOnUnfollow::dispatch($follower->id, $followeeId);
        }
    }

    /**
     * Celebrities the user follows, merged into the feed at read time.
     *
     * @return list<int>
     */
    public function celebrityFolloweeIds(int $userId): array
    {
        return $this->cache->remember(
            $this->celebritiesKey($userId),
            config('feed.cache_ttl.celebrities'),
            fn (): array => $this->follows->celebrityFolloweeIds($userId, config('feed.celebrity_threshold')),
        );
    }

    private function forgetCelebrities(int $userId): void
    {
        DB::afterCommit(fn () => $this->cache->forget($this->celebritiesKey($userId)));
    }

    private function guard(User $follower, int $followeeId): void
    {
        if ($follower->id === $followeeId) {
            throw new CannotFollowSelfException;
        }

        if (! User::query()->whereKey($followeeId)->exists()) {
            throw new UserNotFoundException;
        }
    }

    private function celebritiesKey(int $userId): string
    {
        return "celebs:{$userId}";
    }
}
