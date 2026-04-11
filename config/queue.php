<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Queue Connection Name
    |--------------------------------------------------------------------------
    |
    | Laravel's queue API supports an assortment of back-ends via a single
    | API, giving you convenient access to each back-end using the same
    | syntax for every one. Here you may define a default connection.
    |
    */

    'default' => env('QUEUE_CONNECTION', 'database'),

    /*
    |--------------------------------------------------------------------------
    | Queue Connections
    |--------------------------------------------------------------------------
    |
    | Here you may configure the connection information for each server that
    | is used by your application. A default configuration has been added
    | for each back-end shipped with Laravel. You are free to add more.
    |
    | Drivers: "sync", "database", "beanstalkd", "sqs", "redis", "null"
    |
    */

    'connections' => [

        'sync' => [
            'driver' => 'sync',
        ],

        'database' => [
            'driver' => 'database',
            'connection' => 'master',
            'table' => env('JOBS_TABLE', 'jobs'),
            'queue' => 'default',
            'retry_after' => 300,
        ],
        'mc_schedule_job' => [
            'driver' => 'database',
            'connection' => 'master',
            'table' => 'jobs_mc_schedules',
            'queue' => 'default',
            'retry_after' => 300,
        ],
        'mc_run_job' => [
            'driver' => 'database',
            'connection' => 'master',
            'table' => 'jobs_mc_runs',
            'queue' => 'default',
            'retry_after' => 300,
        ],

        'rvm_unsent_schedule_job' => [
            'driver' => 'database',
            'connection' => 'master',
            'table' => 'jobs_rvm_schedules',
            'queue' => 'default',
            'retry_after' => 300,
        ],

        'rvm_timezone_zero_schedule_job' => [
            'driver' => 'database',
            'connection' => 'master',
            'table' => 'jobs_rvm_schedules',
            'queue' => 'default',
            'retry_after' => 300,
        ],

        'rvm_failed_schedule_job' => [
            'driver' => 'database',
            'connection' => 'master',
            'table' => 'jobs_rvm_schedules',
            'queue' => 'default',
            'retry_after' => 300,
        ],

        'rvm_schedule_job' => [
            'driver' => 'database',
            'connection' => 'master',
            'table' => 'jobs_rvm_schedules',
            'queue' => 'default',
            'retry_after' => 300,
        ],

        'lender_api_schedule_job' => [
            'driver' => 'database',
            'connection' => 'master',
            'table' => 'jobs_lender_api_schedules',
            'queue' => 'default',
            'retry_after' => 300,
        ],


        'clients' => [
            'driver' => 'database',
            'connection' => 'master',
            'table' => 'jobs_clients',
            'queue' => 'default',
            'retry_after' => 300,
        ],
        'reset_user_package' => [
            'driver' => 'database',
            'connection' => 'master',
            'table' => 'jobs_reset_user_package',
            'queue' => 'default',
            'retry_after' => 300,
        ],
        'beanstalkd' => [
            'driver' => 'beanstalkd',
            'host' => 'localhost',
            'queue' => 'default',
            'retry_after' => 90,
            'block_for' => 0,
        ],

        'sqs' => [
            'driver' => 'sqs',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'prefix' => env('SQS_PREFIX', 'https://sqs.us-east-1.amazonaws.com/your-account-id'),
            'queue' => env('SQS_QUEUE', 'your-queue-name'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => 'default',
            'queue' => env('REDIS_QUEUE', 'default'),
            'retry_after' => 120,
            'block_for' => 5,
        ],

        // ── RVM v2 Redis queues ────────────────────────────────────────────
        // Separate connections (all hitting the same Redis) give us per-queue
        // retry_after tuning + distinct Horizon workers. See
        // docs/rvm-v2-architecture.md §4 for the topology.
        'rvm.instant' => [
            'driver' => 'redis',
            'connection' => 'default',
            'queue' => 'rvm.instant',
            'retry_after' => 90,
            'block_for' => 2,
        ],
        'rvm.normal' => [
            'driver' => 'redis',
            'connection' => 'default',
            'queue' => 'rvm.normal',
            'retry_after' => 120,
            'block_for' => 5,
        ],
        'rvm.bulk' => [
            'driver' => 'redis',
            'connection' => 'default',
            'queue' => 'rvm.bulk',
            'retry_after' => 180,
            'block_for' => 5,
        ],
        'rvm.retry' => [
            'driver' => 'redis',
            'connection' => 'default',
            'queue' => 'rvm.retry',
            'retry_after' => 300,
            'block_for' => 5,
        ],
        'rvm.webhooks' => [
            'driver' => 'redis',
            'connection' => 'default',
            'queue' => 'rvm.webhooks',
            'retry_after' => 60,
            'block_for' => 5,
        ],
        'rvm.dlq' => [
            'driver' => 'redis',
            'connection' => 'default',
            'queue' => 'rvm.dlq',
            'retry_after' => 600,
            'block_for' => 0,
        ],
        'dc_schedule_job' => [
            'driver' => 'database',
            'connection' => 'master',
            'table' => 'jobs_dc_schedules',
            'queue' => 'default',
            'retry_after' => 300,
        ],
        'dc_run_job' => [
            'driver' => 'database',
            'connection' => 'master',
            'table' => 'jobs_dc_runs',
            'queue' => 'default',
            'retry_after' => 300,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Failed Queue Jobs
    |--------------------------------------------------------------------------
    |
    | These options configure the behavior of failed queue job logging so you
    | can control which database and table are used to store the jobs that
    | have failed. You may change them to any database / table you wish.
    |
    */

    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database'),
        'database' => env('DB_DATABASE', 'master'),
        'table' => env('FAILED_JOBS_TABLE', 'failed_jobs')
    ],

];
