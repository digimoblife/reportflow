<?php

it('renders the filament login page in local environment', function () {
    $response = $this->get('/admin/login');

    $response->assertOk();
});
