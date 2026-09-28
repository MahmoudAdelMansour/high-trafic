<?php

use App\Models\Post;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('counts a double like once', function () {
    $post = Post::factory()->create();
    Sanctum::actingAs(User::factory()->create());

    $this->postJson("/api/posts/{$post->id}/like")->assertNoContent();
    $this->postJson("/api/posts/{$post->id}/like")->assertNoContent();

    expect($post->refresh()->likes_count)->toBe(1);
    $this->assertDatabaseCount('likes', 1);
    $this->getJson("/api/posts/{$post->id}")->assertJsonPath('data.likes_count', 1);
});

it('treats unlike without a like as a no-op', function () {
    $post = Post::factory()->create();
    Sanctum::actingAs(User::factory()->create());

    $this->deleteJson("/api/posts/{$post->id}/like")->assertNoContent();

    expect($post->refresh()->likes_count)->toBe(0);
});

it('keeps the cached counter in sync on like and unlike', function () {
    $post = Post::factory()->create();
    $users = User::factory()->count(3)->create();

    Sanctum::actingAs($users[0]);
    $this->getJson("/api/posts/{$post->id}")->assertJsonPath('data.likes_count', 0);

    foreach ($users as $user) {
        Sanctum::actingAs($user);
        $this->postJson("/api/posts/{$post->id}/like")->assertNoContent();
    }

    $this->deleteJson("/api/posts/{$post->id}/like")->assertNoContent();

    $this->getJson("/api/posts/{$post->id}")
        ->assertJsonPath('data.likes_count', 2)
        ->assertJsonPath('data.liked_by_me', false);
    expect($post->refresh()->likes_count)->toBe(2);
});

it('returns 404 when liking a missing or deleted post', function () {
    $post = Post::factory()->create();
    $post->delete();
    Sanctum::actingAs(User::factory()->create());

    $this->postJson("/api/posts/{$post->id}/like")->assertNotFound()->assertJsonPath('code', 'POST_NOT_FOUND');
    $this->postJson('/api/posts/999999/like')->assertNotFound();
});
