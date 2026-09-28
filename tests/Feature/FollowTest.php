<?php

use App\Jobs\BackfillFeed;
use App\Jobs\PruneFeedOnUnfollow;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

it('follows a user and updates both counters', function () {
    $ahmed = Sanctum::actingAs(User::factory()->create());
    $mohammed = User::factory()->create();

    $this->postJson("/api/follow/{$mohammed->id}")->assertNoContent();

    $this->assertDatabaseHas('follows', ['follower_id' => $ahmed->id, 'followee_id' => $mohammed->id]);
    expect($mohammed->refresh()->followers_count)->toBe(1)
        ->and($ahmed->refresh()->following_count)->toBe(1);
});

it('is idempotent when following twice', function () {
    $ahmed = Sanctum::actingAs(User::factory()->create());
    $mohammed = User::factory()->create();

    $this->postJson("/api/follow/{$mohammed->id}")->assertNoContent();
    $this->postJson("/api/follow/{$mohammed->id}")->assertNoContent();

    $this->assertDatabaseCount('follows', 1);
    expect($mohammed->refresh()->followers_count)->toBe(1)
        ->and($ahmed->refresh()->following_count)->toBe(1);
});

it('cannot follow yourself', function () {
    $ahmed = Sanctum::actingAs(User::factory()->create());

    $this->postJson("/api/follow/{$ahmed->id}")
        ->assertUnprocessable()
        ->assertJsonPath('code', 'CANNOT_FOLLOW_SELF');

    $this->assertDatabaseCount('follows', 0);
});

it('returns USER_NOT_FOUND for an unknown user', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/follow/999999')
        ->assertNotFound()
        ->assertJsonPath('code', 'USER_NOT_FOUND');
});

it('unfollows idempotently and decrements the counters once', function () {
    $ahmed = Sanctum::actingAs(User::factory()->create());
    $mohammed = User::factory()->create();
    $this->postJson("/api/follow/{$mohammed->id}");

    $this->deleteJson("/api/follow/{$mohammed->id}")->assertNoContent();
    $this->deleteJson("/api/follow/{$mohammed->id}")->assertNoContent();

    $this->assertDatabaseCount('follows', 0);
    expect($mohammed->refresh()->followers_count)->toBe(0)
        ->and($ahmed->refresh()->following_count)->toBe(0);
});

it('queues a backfill on follow and a prune on unfollow on the feed queue', function () {
    Queue::fake();
    $ahmed = Sanctum::actingAs(User::factory()->create());
    $mohammed = User::factory()->create();

    $this->postJson("/api/follow/{$mohammed->id}");
    $this->postJson("/api/follow/{$mohammed->id}");
    $this->deleteJson("/api/follow/{$mohammed->id}");

    Queue::assertPushedOn('feed', BackfillFeed::class, fn (BackfillFeed $job) => $job->followerId === $ahmed->id && $job->followeeId === $mohammed->id);
    Queue::assertPushed(BackfillFeed::class, 1);
    Queue::assertPushedOn('feed', PruneFeedOnUnfollow::class);
});
