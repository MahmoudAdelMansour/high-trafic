<?php

use App\Repositories\PostRepository;
use App\Services\FeedService;
use App\Services\FollowService;
use App\Services\PostHydrator;
use App\Stores\InMemoryFeedStore;

function feedService(InMemoryFeedStore $store, array $celebrities = [], ?PostRepository $posts = null): FeedService
{
    $follows = Mockery::mock(FollowService::class);
    $follows->allows('celebrityFolloweeIds')->andReturn($celebrities);

    return new FeedService(
        feeds: $store,
        posts: $posts ?? Mockery::mock(PostRepository::class),
        follows: $follows,
        hydrator: Mockery::mock(PostHydrator::class),
        cap: 500,
        overfetch: 5,
    );
}

it('merges the pushed feed with celebrity timelines newest first', function () {
    $store = new InMemoryFeedStore(500);
    $store->rebuildFeed(1, [10, 7, 3]);
    $store->rebuildTimeline(50, [9, 4]);
    $store->rebuildTimeline(60, [12, 8]);

    expect(feedService($store, [50, 60])->candidateIds(1, null, 10))->toBe([12, 10, 9, 8, 7, 4, 3]);
});

it('applies the cursor and the window to every source', function () {
    $store = new InMemoryFeedStore(500);
    $store->rebuildFeed(1, [10, 7, 3, 2]);
    $store->rebuildTimeline(50, [9, 8, 4]);

    expect(feedService($store, [50])->candidateIds(1, 9, 3))->toBe([8, 7, 4]);
});

it('de-duplicates posts present in both the feed and a celebrity timeline', function () {
    $store = new InMemoryFeedStore(500);
    $store->rebuildFeed(1, [10, 9]);
    $store->rebuildTimeline(50, [10, 5]);

    expect(feedService($store, [50])->candidateIds(1, null, 10))->toBe([10, 9, 5]);
});

it('rebuilds a missing feed from the repository', function () {
    $store = new InMemoryFeedStore(500);
    $posts = Mockery::mock(PostRepository::class);
    $posts->expects('followedPostIds')->with(1, null, 500)->andReturn([30, 20, 10]);

    expect(feedService($store, posts: $posts)->candidateIds(1, 25, 10))->toBe([20, 10])
        ->and($store->feed(1, null, 10))->toBe([30, 20, 10]);
});
