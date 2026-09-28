<?php

use App\Models\User;

it('registers a user and returns a token', function () {
    $response = $this->postJson('/api/register', [
        'name' => 'Ahmed',
        'email' => 'ahmed@example.com',
        'password' => 'secret-password',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.email', 'ahmed@example.com')
        ->assertJsonStructure(['data' => ['id', 'name', 'email'], 'token']);

    expect(User::firstWhere('email', 'ahmed@example.com')->tokens()->count())->toBe(1);
});

it('rejects a duplicate email with the error envelope', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    $this->postJson('/api/register', [
        'name' => 'Someone',
        'email' => 'taken@example.com',
        'password' => 'secret-password',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'VALIDATION_FAILED')
        ->assertJsonStructure(['message', 'code', 'errors' => ['email']]);
});

it('logs in with valid credentials', function () {
    User::factory()->create(['email' => 'mohammed@example.com']);

    $this->postJson('/api/login', ['email' => 'mohammed@example.com', 'password' => 'password'])
        ->assertOk()
        ->assertJsonStructure(['data' => ['id'], 'token']);
});

it('rejects invalid credentials', function () {
    User::factory()->create(['email' => 'mohammed@example.com']);

    $this->postJson('/api/login', ['email' => 'mohammed@example.com', 'password' => 'wrong'])
        ->assertUnauthorized()
        ->assertJsonPath('code', 'INVALID_CREDENTIALS');
});

it('throttles repeated login attempts', function () {
    foreach (range(1, 5) as $attempt) {
        $this->postJson('/api/login', ['email' => 'x@example.com', 'password' => 'wrong']);
    }

    $this->postJson('/api/login', ['email' => 'x@example.com', 'password' => 'wrong'])
        ->assertTooManyRequests()
        ->assertJsonPath('code', 'TOO_MANY_REQUESTS');
});
