<?php

it('aborts with RuntimeException before executing anything if active database is not reportflow_test', function () {
    $originalDb = config('database.connections.pgsql.database');

    try {
        config(['database.connections.pgsql.database' => 'reportflow']);

        expect(fn () => $this->ensureRunningOnTestDatabase())
            ->toThrow(
                RuntimeException::class,
                'CRITICAL SAFETY VIOLATION: Test suite attempted to run against database [reportflow]!'
            );
    } finally {
        config(['database.connections.pgsql.database' => $originalDb]);
    }
});
