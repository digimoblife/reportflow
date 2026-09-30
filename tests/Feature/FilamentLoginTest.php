<?php

it('renders the filament login page in local environment', function () {
    withAppEnvironment('local', [], function () {
        expect(app()->environment())->toBe('local');

        $this->get('/admin/login')->assertOk();
    });
});

it('does not register the login page outside local environment', function () {
    expect(app()->environment())->toBe('testing');

    $this->get('/admin/login')->assertNotFound();
});
