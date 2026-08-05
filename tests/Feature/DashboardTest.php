<?php

declare(strict_types=1);

use App\Models\User;

test('guests are redirected to login', function () {
    $response = $this->get('/dashboard');

    $response->assertRedirect('/login');
});

test('authenticated users can view the dashboard', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get('/dashboard');

    $response
        ->assertOk()
        ->assertSeeText('SharePoint')
        ->assertSeeText('Ollama')
        ->assertSeeText('Database')
        ->assertSeeText('Authentication')
        ->assertSeeText('Not Configured')
        ->assertSeeText('Connected');
});
