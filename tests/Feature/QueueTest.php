<?php

use App\Jobs\FanOutChunk;
use App\Jobs\FanOutPost;
use App\Jobs\NotifyFollowers;
use App\Jobs\SendNotificationChunk;
use App\Models\Post;
use App\Models\User;
use App\Notifications\NewPostNotification;
use App\Repositories\FollowRepository;
use Illuminate\Bus\PendingBatch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

it('queues fan-out and notifications on separate queues when a post is created', function () {
    Queue::fake();
    Sanctum::actingAs(User::factory()->create());

    $postId = $this->postJson('/api/posts', ['content' => 'Hello'])->assertCreated()->json('data.id');

    Queue::assertPushedOn('feed', FanOutPost::class, fn (FanOutPost $job) => $job->postId === $postId);
    Queue::assertPushedOn('notifications', NotifyFollowers::class, fn (NotifyFollowers $job) => $job->postId === $postId);
});

it('fans out a normal author in chunked batches', function () {
    config(['feed.chunk_size' => 2]);
    Bus::fake();
    $author = User::factory()->create();
    follow(User::factory()->count(5)->create(), $author);
    $post = Post::factory()->for($author, 'author')->create();

    (new FanOutPost($post->id, $author->id))->handle(app(FollowRepository::class));

    Bus::assertBatched(fn (PendingBatch $batch) => $batch->jobs->count() === 3
        && $batch->jobs->every(fn ($job) => $job instanceof FanOutChunk)
        && $batch->jobs->flatMap(fn (FanOutChunk $job) => $job->followerIds)->count() === 5
        && $batch->queue() === 'feed');
});

it('does not fan out a celebrity author', function () {
    config(['feed.celebrity_threshold' => 3]);
    Bus::fake();
    $celebrity = User::factory()->create();
    follow(User::factory()->count(3)->create(), $celebrity);
    $post = Post::factory()->for($celebrity, 'author')->create();

    (new FanOutPost($post->id, $celebrity->id))->handle(app(FollowRepository::class));

    Bus::assertNothingBatched();
});

it('does not fan out a post deleted before the job ran', function () {
    Bus::fake();
    $author = User::factory()->create();
    follow(User::factory()->create(), $author);
    $post = Post::factory()->for($author, 'author')->create();
    $post->delete();

    (new FanOutPost($post->id, $author->id))->handle(app(FollowRepository::class));

    Bus::assertNothingBatched();
});

it('notifies every follower with bulk inserts', function () {
    config(['feed.chunk_size' => 2]);
    $author = User::factory()->create(['name' => 'Mohammed']);
    $followers = User::factory()->count(5)->create();
    follow($followers, $author);
    $post = Post::factory()->for($author, 'author')->create();

    NotifyFollowers::dispatchSync($post->id, $author->id);

    $this->assertDatabaseCount('notifications', 5);

    $notification = $followers[0]->notifications()->sole();
    expect($notification->type)->toBe(NewPostNotification::class)
        ->and($notification->data)->toBe(['post_id' => $post->id, 'author' => ['id' => $author->id, 'name' => 'Mohammed']]);
});

it('sends celebrity notifications on the low priority queue', function () {
    config(['feed.celebrity_threshold' => 2]);
    Bus::fake();
    $celebrity = User::factory()->create();
    follow(User::factory()->count(2)->create(), $celebrity);

    (new NotifyFollowers(1, $celebrity->id))->handle(app(FollowRepository::class));

    Bus::assertBatched(fn (PendingBatch $batch) => $batch->queue() === 'notifications-low');
});

it('makes a retried notification chunk idempotent', function () {
    $author = User::factory()->create();
    $followers = User::factory()->count(3)->create();
    $post = Post::factory()->for($author, 'author')->create();
    $chunk = new SendNotificationChunk($post->id, $author->id, $author->name, $followers->modelKeys());

    $chunk->handle();
    $chunk->handle();

    $this->assertDatabaseCount('notifications', 3);
});
