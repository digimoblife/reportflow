<?php

it('returns a successful health check response with ok status', function () {
    $response = $this->get('/health');

    $response->assertOk()
        ->assertExactJson(['status' => 'ok']);
});
