<?php

declare(strict_types=1);

test('guests visiting the root are redirected to login', function () {
    $response = $this->get('/');

    $response->assertRedirect(route('login'));
});
