<?php

declare(strict_types=1);

use App\Models\Department;

test('the public chat page renders for a known department', function () {
    $response = $this->get('/chat/quality');

    $response->assertOk()
        ->assertSee('quality')
        ->assertSee(json_encode(route('api.chat', absolute: false), JSON_THROW_ON_ERROR), escape: false)
        ->assertSee('data.error', escape: false);
});

test('the public chat page renders for an admin-managed department', function () {
    Department::factory()->create(['slug' => 'finance']);

    $this->get('/chat/finance')->assertOk()->assertSee('finance');
});

test('the public chat page 404s for an unknown department', function () {
    $response = $this->get('/chat/finance');

    $response->assertNotFound();
});
