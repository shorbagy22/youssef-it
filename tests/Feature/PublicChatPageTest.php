<?php

declare(strict_types=1);

test('the public chat page renders for a known department', function () {
    $response = $this->get('/chat/quality');

    $response->assertOk()->assertSee('quality');
});

test('the public chat page 404s for an unknown department', function () {
    $response = $this->get('/chat/finance');

    $response->assertNotFound();
});
