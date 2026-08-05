<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Http;

test('guests visiting the chat page are redirected to login', function () {
    $this->get('/chat')->assertRedirect('/login');
});

test('guests cannot send chat messages', function () {
    $this->postJson('/chat/send', ['message' => 'Hello'])->assertUnauthorized();
});

test('authenticated users can view the chat page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/chat')->assertOk();
});

test('sending a message returns the answer from Ollama', function () {
    Http::fake([
        'localhost:11434/api/generate' => Http::response(['response' => 'Hi! How can I help?'], 200),
    ]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/chat/send', ['message' => 'Hello'])
        ->assertOk()
        ->assertJson(['answer' => 'Hi! How can I help?']);
});

test('sending a message includes prior history in the prompt sent to Ollama', function () {
    Http::fake([
        'localhost:11434/api/generate' => Http::response(['response' => 'Sure.'], 200),
    ]);

    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/chat/send', [
        'message' => 'And after that?',
        'history' => [
            ['role' => 'user', 'content' => 'What is the capital of France?'],
            ['role' => 'assistant', 'content' => 'Paris.'],
        ],
    ])->assertOk();

    Http::assertSent(function ($request) {
        return str_contains($request['prompt'], 'User: What is the capital of France?')
            && str_contains($request['prompt'], 'Assistant: Paris.')
            && str_contains($request['prompt'], 'User: And after that?');
    });
});

test('it returns 503 when Ollama is unavailable', function () {
    Http::fake([
        'localhost:11434/api/generate' => Http::response('Service Unavailable', 500),
    ]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/chat/send', ['message' => 'Hello'])
        ->assertStatus(503)
        ->assertJsonStructure(['error']);
});

test('message is required', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/chat/send', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('message');
});

test('history entries must have a valid role', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/chat/send', [
            'message' => 'Hello',
            'history' => [['role' => 'system', 'content' => 'x']],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('history.0.role');
});
