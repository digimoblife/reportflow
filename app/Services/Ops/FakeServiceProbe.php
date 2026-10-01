<?php

namespace App\Services\Ops;

/**
 * Test double for ServiceProbe: everything is healthy until a test says otherwise.
 */
final class FakeServiceProbe implements ServiceProbe
{
    public bool $database = true;

    public bool $redis = true;

    public bool $gotenberg = true;

    /** @var array<string, float|null> path => percent; paths not listed use */
    public array $disk = [];

    public ?float $defaultDisk = 40.0;

    public function database(): bool
    {
        return $this->database;
    }

    public function redis(): bool
    {
        return $this->redis;
    }

    public function gotenberg(): bool
    {
        return $this->gotenberg;
    }

    public function diskUsedPercent(string $path): ?float
    {
        return array_key_exists($path, $this->disk) ? $this->disk[$path] : $this->defaultDisk;
    }
}
