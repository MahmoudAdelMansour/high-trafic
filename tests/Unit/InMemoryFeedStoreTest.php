<?php

use App\Stores\InMemoryFeedStore;

it('reports a feed that was never built as missing', function () {
    expect((new InMemoryFeedStore(10))->feed(1, null, 10))->toBeNull();
});

it('keeps an empty rebuilt feed as existing', function () {
    $store = new InMemoryFeedStore(10);
    $store->rebuildFeed(1, []);

    expect($store->feed(1, null, 10))->toBe([]);
});
