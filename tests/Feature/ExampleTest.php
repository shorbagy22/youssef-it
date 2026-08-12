<?php

declare(strict_types=1);

test('guests visiting the root see the public dashboard', function () {
    $response = $this->get('/');

    $response->assertOk()->assertSee('Factory AI Dashboard');
});
