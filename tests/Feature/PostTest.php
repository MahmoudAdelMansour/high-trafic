<?php

use App\Events\PostCreated;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;

it('creates a post and dispatches PostCreated', function () {
    Event::fake([PostCreated::class]);
    $author = Sanctum::actingAs(User::factory()->create(['name' => 'Mohammed']));

    $response = $this->postJson('/api/posts', ['content' => 'Hello world']);

    $response->assertCreated()
        ->assertJsonPath('data.content', 'Hello world')
        ->assertJsonPath('data.author.name', 'Mohammed')
        ->assertJsonPath('data.likes_count', 0)
        ->assertJsonPath('data.liked_by_me', false);

    $this->assertDatabaseHas('posts', ['id' => $response->json('data.id'), 'user_id' => $author->id]);
    Event::assertDispatched(PostCreated::class, fn (PostCreated $event) => $event->postId === $response->json('data.id'));
});

it('validates post content', function (mixed $content) {
    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/posts', ['content' => $content])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'VALIDATION_FAILED')
        ->assertJsonStructure(['errors' => ['content']]);
})->with([
    'missing' => null,
    'empty' => '',
    'too long' => str_repeat('a', 1001),
]);

it('requires authentication even without an Accept header', function () {
    $this->post('/api/posts', ['content' => 'hello'])
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Unauthenticated', 'code' => 'UNAUTHENTICATED', 'errors' => []]);
});

it('shows a post with author, likes count and liked_by_me', function () {
    $post = Post::factory()->create();
    $viewer = Sanctum::actingAs(User::factory()->create());
    $this->postJson("/api/posts/{$post->id}/like")->assertNoContent();

    $this->getJson("/api/posts/{$post->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $post->id)
        ->assertJsonPath('data.author.id', $post->user_id)
        ->assertJsonPath('data.likes_count', 1)
        ->assertJsonPath('data.liked_by_me', true);
});

it('returns POST_NOT_FOUND for a missing post', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/posts/999999')
        ->assertNotFound()
        ->assertExactJson(['message' => 'Post not found', 'code' => 'POST_NOT_FOUND', 'errors' => []]);
});

it('lets the author delete a post', function () {
    $post = Post::factory()->create();
    Sanctum::actingAs($post->author);

    $this->getJson("/api/posts/{$post->id}")->assertOk();
    $this->deleteJson("/api/posts/{$post->id}")->assertNoContent();

    $this->assertSoftDeleted($post);
    $this->getJson("/api/posts/{$post->id}")->assertNotFound();
});

it('forbids deleting another user\'s post', function () {
    $post = Post::factory()->create();
    Sanctum::actingAs(User::factory()->create());

    $this->deleteJson("/api/posts/{$post->id}")
        ->assertForbidden()
        ->assertJsonPath('code', 'FORBIDDEN');

    $this->assertNotSoftDeleted($post);
});

it('returns 404 when deleting a post that is already deleted', function () {
    $post = Post::factory()->create();
    $post->delete();
    Sanctum::actingAs($post->author);

    $this->deleteJson("/api/posts/{$post->id}")
        ->assertNotFound()
        ->assertJsonPath('code', 'POST_NOT_FOUND');
});
