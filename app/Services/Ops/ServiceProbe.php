<?php

namespace App\Services\Ops;

/**
 * What the health checks need to ask the infrastructure (PRD §78). The real implementation talks to PostgreSQL, Redis,
 * Gotenberg and the disk; tests bind a fake so that no test depends on a dev service (CLAUDE.md test isolation).
 */
interface ServiceProbe
{
    public function database(): bool;

    public function redis(): bool;

    public function gotenberg(): bool;

    /** Percentage of the filesystem holding $path that is in use, or null when it cannot be read. */
    public function diskUsedPercent(string $path): ?float;
}
