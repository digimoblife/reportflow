<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Hook called by RefreshDatabase before executing migrate:fresh.
     */
    protected function beforeRefreshingDatabase()
    {
        $this->ensureRunningOnTestDatabase();
    }

    /**
     * Hook called during test case setup.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureRunningOnTestDatabase();

        // No test may reach the network; tests that exercise the HTTP client add Http::fake().
        Http::preventStrayRequests();
    }

    /**
     * Ensure the active database is strictly 'reportflow_test'.
     *
     * @throws RuntimeException
     */
    public function ensureRunningOnTestDatabase(): void
    {
        $connection = config('database.default');
        $dbName = config("database.connections.{$connection}.database");

        if ($dbName !== 'reportflow_test') {
            throw new RuntimeException(
                "CRITICAL SAFETY VIOLATION: Test suite attempted to run against database [{$dbName}]! ".
                'Tests must ONLY run against [reportflow_test]. Aborting immediately to protect dev data.'
            );
        }
    }
}
