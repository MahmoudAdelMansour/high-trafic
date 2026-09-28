<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Feed Store Driver
    |--------------------------------------------------------------------------
    |
    | "redis" keeps the per-user feeds and author timelines in Redis sorted
    | sets. "memory" is an in-process implementation used by the tests so the
    | feed logic can run without a Redis server.
    |
    */

    'store' => env('FEED_STORE', 'redis'),

    'redis_connection' => env('FEED_REDIS_CONNECTION', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Hybrid Fan-out
    |--------------------------------------------------------------------------
    |
    | Authors with at least this many followers are "celebrities": their posts
    | are not pushed into follower feeds, they are merged on read instead.
    |
    */

    'celebrity_threshold' => (int) env('FEED_CELEBRITY_THRESHOLD', 10000),

    /*
    |--------------------------------------------------------------------------
    | Feed Shape
    |--------------------------------------------------------------------------
    */

    'cap' => (int) env('FEED_CAP', 500),

    'ttl_seconds' => (int) env('FEED_TTL_DAYS', 7) * 86400,

    'page_size' => 20,

    'max_page_size' => 50,

    /** Extra ids fetched per page so lazily-deleted posts don't leave the page short. */
    'overfetch' => 5,

    /** How many of the followee's latest posts are copied into a feed on follow. */
    'backfill_size' => 20,

    /*
    |--------------------------------------------------------------------------
    | Queue Design
    |--------------------------------------------------------------------------
    */

    'chunk_size' => (int) env('FEED_CHUNK_SIZE', 1000),

    'queues' => [
        'feed' => 'feed',
        'notifications' => 'notifications',
        'notifications_low' => 'notifications-low',
    ],

    /*
    |--------------------------------------------------------------------------
    | Entity Cache TTLs (seconds)
    |--------------------------------------------------------------------------
    */

    'cache_ttl' => [
        'post' => 86400,
        'user_card' => 86400,
        'celebrities' => 3600,
        'likes_counter' => 7 * 86400,
    ],

    /*
    |--------------------------------------------------------------------------
    | Demo Data Volume (database/seeders/FeedSeeder.php)
    |--------------------------------------------------------------------------
    */

    'seed' => [
        'users' => (int) env('SEED_USERS', 12000),
        'posts' => (int) env('SEED_POSTS', 200000),
        'follows_per_user' => (int) env('SEED_FOLLOWS_PER_USER', 30),
        'likes' => (int) env('SEED_LIKES', 100000),
    ],

];
