<?php

namespace App\Services\Ops;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

final class DefaultServiceProbe implements ServiceProbe
{
    public function database(): bool
    {
        try {
            DB::select('select 1');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function redis(): bool
    {
        try {
            Redis::connection()->ping();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** A TCP connection to Gotenberg's address is enough: its own health endpoint is what Docker already polls. */
    public function gotenberg(): bool
    {
        $parts = parse_url((string) config('reports.pdf.url'));
        $host = $parts['host'] ?? null;

        if ($host === null) {
            return false;
        }

        $socket = @stream_socket_client('tcp://'.$host.':'.($parts['port'] ?? 3000), $errno, $error, 2);

        if ($socket === false) {
            return false;
        }

        fclose($socket);

        return true;
    }

    public function diskUsedPercent(string $path): ?float
    {
        $total = @disk_total_space($path);
        $free = @disk_free_space($path);

        return $total === false || $free === false || $total <= 0 ? null : round((1 - $free / $total) * 100, 1);
    }
}
