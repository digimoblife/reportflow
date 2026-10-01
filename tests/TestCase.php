<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Pages must render without a front-end build (CI has no public/build); also re-applied after the app is rebuilt
     * (withAppEnvironment() refreshes it).
     */
    protected function refreshApplication(): void
    {
        parent::refreshApplication();

        $this->withoutVite();
    }

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

        // Generated reports never touch a real disk in tests.
        Storage::fake('reports');
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
