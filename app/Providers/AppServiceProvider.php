<?php

namespace App\Providers;

use App\Contracts\CounterStore;
use App\Contracts\FeedStore;
use App\Stores\InMemoryCounterStore;
use App\Stores\InMemoryFeedStore;
use App\Stores\RedisCounterStore;
use App\Stores\RedisFeedStore;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(FeedStore::class, fn (): FeedStore => match (config('feed.store')) {
            'memory' => new InMemoryFeedStore(config('feed.cap')),
            default => new RedisFeedStore(
                Redis::connection(config('feed.redis_connection')),
                config('feed.cap'),
                config('feed.ttl_seconds'),
            ),
        });

        $this->app->singleton(CounterStore::class, fn (): CounterStore => match (config('feed.store')) {
            'memory' => new InMemoryCounterStore,
            default => new RedisCounterStore(
                Redis::connection(config('feed.redis_connection')),
                config('feed.cache_ttl.likes_counter'),
            ),
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('api', fn (Request $request): Limit => Limit::perMinute(120)
            ->by($request->user()?->id ?: $request->ip()));

        RateLimiter::for('login', fn (Request $request): Limit => Limit::perMinute(5)
            ->by(mb_strtolower((string) $request->input('email')).'|'.$request->ip()));
    }
}
