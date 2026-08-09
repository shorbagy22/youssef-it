<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Http;

test('guests are redirected to login', function () {
    $response = $this->get('/dashboard');

    $response->assertRedirect('/login');
});

test('authenticated users can view the dashboard', function () {
    Http::fake([
        'ai-service.test/health' => Http::response('OK', 200),
    ]);

    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get('/dashboard');

    $response
        ->assertOk()
        ->assertSeeText('AI Service')
        ->assertSeeText('Database')
        ->assertSeeText('Authentication')
        ->assertSeeText('Connected');
});

test('the dashboard shows the AI service as disconnected when its health check fails', function () {
    Http::fake([
        'ai-service.test/health' => Http::response('Service Unavailable', 503),
    ]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertSeeText('Disconnected');
});
