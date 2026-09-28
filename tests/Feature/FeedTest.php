<?php

use App\Contracts\FeedStore;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->ahmed = User::factory()->create(['name' => 'Ahmed']);
    $this->mohammed = User::factory()->create(['name' => 'Mohammed']);
    $this->sara = User::factory()->create(['name' => 'Sara']);
    $this->stranger = User::factory()->create();
});

function feedIds(mixed $response): array
{
    return array_column($response->json('data'), 'id');
}

it('returns only posts from followed users, newest first', function () {
    follow($this->ahmed, $this->mohammed);
    follow($this->ahmed, $this->sara);
    $first = publish($this->mohammed);
    $second = publish($this->sara);
    publish($this->stranger);
    publish($this->ahmed);
    $third = publish($this->mohammed);

    Sanctum::actingAs($this->ahmed);

    $response = $this->getJson('/api/feed')->assertOk();

    expect(feedIds($response))->toBe([$third, $second, $first]);
    $response->assertJsonPath('data.0.author.name', 'Mohammed')
        ->assertJsonPath('meta.has_more', false)
        ->assertJsonPath('meta.next_cursor', null);
});

it('pushes new posts into an existing feed through the fan-out', function () {
    follow($this->ahmed, $this->mohammed);
    Sanctum::actingAs($this->ahmed);
    $this->getJson('/api/feed')->assertOk()->assertJsonCount(0, 'data');

    $postId = publish($this->mohammed);

    expect(app(FeedStore::class)->feed($this->ahmed->id, null, 10))->toBe([$postId]);
    expect(feedIds($this->getJson('/api/feed')))->toBe([$postId]);
});

it('keeps cursor pagination stable while new posts arrive', function () {
    follow($this->ahmed, $this->mohammed);
    foreach (range(1, 25) as $i) {
        publish($this->mohammed);
    }
    Sanctum::actingAs($this->ahmed);

    $pageOne = $this->getJson('/api/feed?limit=10')->assertOk();
    $cursor = $pageOne->json('meta.next_cursor');

    foreach (range(1, 5) as $i) {
        publish($this->mohammed);
    }

    $pageTwo = $this->getJson("/api/feed?limit=10&cursor={$cursor}")->assertOk();
    $pageThree = $this->getJson('/api/feed?limit=10&cursor='.$pageTwo->json('meta.next_cursor'))->assertOk();

    $seen = [...feedIds($pageOne), ...feedIds($pageTwo), ...feedIds($pageThree)];
    $original = Post::query()->orderByDesc('id')->pluck('id')->slice(5)->values()->all();

    expect($seen)->toBe($original)
        ->and($pageOne->json('meta.has_more'))->toBeTrue()
        ->and($pageThree->json('meta.has_more'))->toBeFalse();
});

it('skips deleted posts', function () {
    follow($this->ahmed, $this->mohammed);
    $kept = publish($this->mohammed);
    $deleted = publish($this->mohammed);
    Sanctum::actingAs($this->ahmed);
    expect(feedIds($this->getJson('/api/feed')))->toBe([$deleted, $kept]);

    Sanctum::actingAs($this->mohammed);
    $this->deleteJson("/api/posts/{$deleted}")->assertNoContent();

    Sanctum::actingAs($this->ahmed);
    expect(feedIds($this->getJson('/api/feed')))->toBe([$kept]);
});

it('merges celebrity posts on read without fanning them out', function () {
    config(['feed.celebrity_threshold' => 2]);
    follow([$this->ahmed, $this->sara], $this->mohammed);
    follow($this->ahmed, $this->sara);
    Sanctum::actingAs($this->ahmed);
    $this->getJson('/api/feed')->assertOk();

    $normal = publish($this->sara);
    $celebrity = publish($this->mohammed);

    expect(app(FeedStore::class)->feed($this->ahmed->id, null, 10))->toBe([$normal])
        ->and(feedIds($this->getJson('/api/feed')))->toBe([$celebrity, $normal]);
});

it('rebuilds the feed from MySQL when the store is empty', function () {
    follow($this->ahmed, $this->mohammed);
    $postIds = [publish($this->mohammed), publish($this->mohammed)];
    app(FeedStore::class)->flush();
    Sanctum::actingAs($this->ahmed);

    expect(feedIds($this->getJson('/api/feed')))->toBe(array_reverse($postIds))
        ->and(app(FeedStore::class)->feed($this->ahmed->id, null, 10))->toBe(array_reverse($postIds));
});

it('falls back to MySQL when scrolling past the feed cap', function () {
    config(['feed.cap' => 5]);
    app()->forgetInstance(FeedStore::class);
    follow($this->ahmed, $this->mohammed);
    foreach (range(1, 12) as $i) {
        publish($this->mohammed);
    }
    Sanctum::actingAs($this->ahmed);

    $seen = [];
    $cursor = null;

    do {
        $response = $this->getJson('/api/feed?limit=3'.($cursor ? "&cursor={$cursor}" : ''))->assertOk();
        array_push($seen, ...feedIds($response));
        $cursor = $response->json('meta.next_cursor');
    } while ($response->json('meta.has_more'));

    expect($seen)->toBe(Post::query()->orderByDesc('id')->pluck('id')->all());
});

it('backfills the feed on follow and prunes it on unfollow', function () {
    $old = publish($this->mohammed);
    Sanctum::actingAs($this->ahmed);
    $this->getJson('/api/feed')->assertJsonCount(0, 'data');

    $this->postJson("/api/follow/{$this->mohammed->id}")->assertNoContent();
    expect(app(FeedStore::class)->feed($this->ahmed->id, null, 10))->toBe([$old]);

    $this->deleteJson("/api/follow/{$this->mohammed->id}")->assertNoContent();
    expect(app(FeedStore::class)->feed($this->ahmed->id, null, 10))->toBe([])
        ->and(feedIds($this->getJson('/api/feed')))->toBe([]);
});

it('returns likes count and liked_by_me for each post', function () {
    follow($this->ahmed, $this->mohammed);
    $liked = publish($this->mohammed);
    $notLiked = publish($this->mohammed);
    Sanctum::actingAs($this->sara);
    $this->postJson("/api/posts/{$liked}/like");
    Sanctum::actingAs($this->ahmed);
    $this->postJson("/api/posts/{$liked}/like");

    $this->getJson('/api/feed')
        ->assertJsonPath('data.0.id', $notLiked)
        ->assertJsonPath('data.0.likes_count', 0)
        ->assertJsonPath('data.0.liked_by_me', false)
        ->assertJsonPath('data.1.id', $liked)
        ->assertJsonPath('data.1.likes_count', 2)
        ->assertJsonPath('data.1.liked_by_me', true);
});

it('hydrates a page of 20 posts from 20 authors in at most 4 queries', function () {
    $authors = User::factory()->count(20)->create();
    follow($this->ahmed, $this->mohammed);
    foreach ($authors as $author) {
        follow($this->ahmed, $author);
    }
    Sanctum::actingAs($this->ahmed);
    $this->getJson('/api/feed')->assertOk();

    foreach ($authors as $author) {
        publish($author);
    }
    Cache::flush();

    DB::enableQueryLog();
    $response = $this->getJson('/api/feed?limit=20')->assertOk();
    $queries = DB::getQueryLog();

    expect($response->json('data'))->toHaveCount(20)
        ->and(collect($response->json('data'))->pluck('author.id')->unique())->toHaveCount(20)
        ->and($queries)->toHaveCount(4);
});

it('validates the page size', function (int $limit) {
    Sanctum::actingAs($this->ahmed);

    $this->getJson("/api/feed?limit={$limit}")
        ->assertUnprocessable()
        ->assertJsonPath('code', 'VALIDATION_FAILED');
})->with([0, 51]);
