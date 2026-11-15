<?php

use Illuminate\Support\Str;

/*
| Two connections:
|   control - the platform database (clinics, domains, plans, framework tables)
|   clinic  - the current clinic's database, filled in at request time by
|             App\Core\Tenancy\ClinicManager. It is deliberately unusable
|             until a clinic has been resolved.
|
| DB_DRIVER is "mysql" in every real environment. "sqlite" exists only so the
| fast test run needs no database server.
*/

$driver = env('DB_DRIVER', 'mysql');

$mysql = [
    'driver' => 'mysql',
    'host' => env('DB_HOST', '127.0.0.1'),
    'port' => env('DB_PORT', '3306'),
    'username' => env('DB_USERNAME', 'root'),
    'password' => env('DB_PASSWORD', ''),
    'unix_socket' => env('DB_SOCKET', ''),
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix' => '',
    'prefix_indexes' => true,
    'strict' => true,
    'engine' => 'InnoDB',
    'timezone' => '+00:00',
];

$sqlite = [
    'driver' => 'sqlite',
    'prefix' => '',
    'foreign_key_constraints' => true,
    'busy_timeout' => 5000,
];

return [

    'default' => 'control',

    'connections' => [

        'control' => $driver === 'sqlite'
            ? $sqlite + ['database' => env('DB_DATABASE', database_path('control.sqlite'))]
            : $mysql + ['database' => env('DB_DATABASE', 'dms_control')],

        'clinic' => ['driver' => 'clinic-not-resolved'],

    ],

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    'redis' => [

        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug((string) env('APP_NAME', 'dms')).'-database-'),
        ],

        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
        ],

        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
        ],

    ],

];
