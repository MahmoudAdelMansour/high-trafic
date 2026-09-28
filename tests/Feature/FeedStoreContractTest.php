<?php

use App\Contracts\FeedStore;
use App\Stores\InMemoryFeedStore;
use App\Stores\RedisFeedStore;
use Illuminate\Support\Facades\Redis;

/*
 * Both FeedStore implementations must behave the same way, so the in-memory
 * store used by the suite is a faithful stand-in for Redis.
 */

const CONTRACT_PREFIX = 'feed-contract-test:';

/**
 * Delete only this test's keys; never flush a database other data may live in.
 */
function clearContractKeys(): void
{
    $client = Redis::connection()->client();

    foreach ($client->keys('*') as $key) {
        $client->del(substr($key, strlen(CONTRACT_PREFIX)));
    }
}

dataset('stores', [
    'memory' => fn (): FeedStore => new InMemoryFeedStore(cap: 3),
    'redis' => function (): FeedStore {
        try {
            config(['database.redis.options.prefix' => CONTRACT_PREFIX]);
            Redis::purge();
            Redis::connection()->ping();
        } catch (Throwable) {
            test()->markTestSkipped('Redis is not reachable.');
        }

        clearContractKeys();

        return new RedisFeedStore(Redis::connection(), cap: 3, ttlSeconds: 60);
    },
]);

afterEach(function () {
    if (config('database.redis.options.prefix') === CONTRACT_PREFIX) {
        rescue(clearContractKeys(...), report: false);
    }
});

it('reports missing feeds as null and built empty feeds as empty', function (FeedStore $store) {
    expect($store->feed(1, null, 10))->toBeNull();

    $store->rebuildFeed(1, []);

    expect($store->feed(1, null, 10))->toBe([])
        ->and($store->feedSize(1))->toBe(0);
})->with('stores');

it('ignores pushes to feeds that are not built', function (FeedStore $store) {
    $store->rebuildFeed(1, []);

    $store->pushToFeeds([1, 2], 7);
    $store->addToFeed(3, [8]);

    expect($store->feed(1, null, 10))->toBe([7])
        ->and($store->feed(2, null, 10))->toBeNull()
        ->and($store->feed(3, null, 10))->toBeNull();
})->with('stores');

it('returns ids newest first below the cursor and caps the list', function (FeedStore $store) {
    $store->rebuildFeed(1, [5, 4]);
    $store->pushToFeeds([1], 9);
    $store->addToFeed(1, [2, 7]);

    expect($store->feed(1, null, 10))->toBe([9, 7, 5])
        ->and($store->feed(1, 9, 2))->toBe([7, 5])
        ->and($store->feedSize(1))->toBe(3);
})->with('stores');

it('removes ids from feeds and timelines', function (FeedStore $store) {
    $store->rebuildFeed(1, [3, 2, 1]);
    $store->rebuildTimeline(5, [3, 1]);

    $store->removeFromFeed(1, [2, 3]);
    $store->removeFromTimeline(5, 3);
    $store->addToTimeline(5, 4);
    $store->addToTimeline(6, 4);

    expect($store->feed(1, null, 10))->toBe([1])
        ->and($store->timeline(5, null, 10))->toBe([4, 1])
        ->and($store->timeline(6, null, 10))->toBeNull();
})->with('stores');
