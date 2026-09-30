<?php

/*
| Pin the test environment before Laravel boots.
|
| PHPUnit's <env force="true"> writes $_ENV and putenv() but not $_SERVER. Laravel's dotenv
| repository reads $_SERVER before $_ENV, so a process-level APP_ENV (docker-compose sets
| APP_ENV=local) silently won over phpunit.xml and the suite ran as "local". Writing all three
| stores here makes the values identical whichever one is read, in Docker, CI and local shells.
|
| Tests that need another environment or DEV_USER_* must prepare it themselves (see
| withAppEnvironment() in tests/Pest.php).
*/

require __DIR__.'/../vendor/autoload.php';

foreach ([
    'APP_ENV' => 'testing',
    'DB_CONNECTION' => 'pgsql',
    'DB_DATABASE' => 'reportflow_test',
    'DB_USERNAME' => 'reportflow_tester',
    'DEV_USER_EMAIL' => '',
    'DEV_USER_PASSWORD' => '',
] as $name => $value) {
    $_ENV[$name] = $_SERVER[$name] = $value;
    putenv("{$name}={$value}");
}
