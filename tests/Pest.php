<?php

use App\Data\CreatePostData;
use App\Models\User;
use App\Repositories\FollowRepository;
use App\Services\PostService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature tests run against a real MySQL/MariaDB test database (see
| phpunit.xml) with the in-memory FeedStore and CounterStore bindings.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
*/

/**
 * Make each follower follow the followee through the real repository, so the
 * counters stay consistent with the follows table.
 *
 * @param  User|iterable<User>  $followers
 */
function follow(User|iterable $followers, User $followee): void
{
    $followers = $followers instanceof User ? [$followers] : $followers;

    foreach ($followers as $follower) {
        app(FollowRepository::class)->follow($follower->id, $followee->id);
    }

    $followee->refresh();
}

/**
 * Publish a post through PostService so the timeline, PostCreated event and
 * (sync) fan-out all run exactly as in production.
 */
function publish(User $author, ?string $content = null): int
{
    return app(PostService::class)
        ->create($author, new CreatePostData($author->id, $content ?? fake()->sentence()))
        ->id;
}
