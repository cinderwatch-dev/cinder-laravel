<?php

return [
    // Project API key from your Cinder project settings
    'key' => env('CINDER_KEY'),

    // The Cinder instance that receives errors, traces and logs
    'base_url' => env('CINDER_URL', 'https://cinderwatch.dev'),

    // Reported as `service.name` and `service.version`
    'service_name' => env('CINDER_SERVICE_NAME', env('APP_NAME', 'Laravel')),
    'service_version' => env('CINDER_SERVICE_VERSION'),

    // Reported as the deployment environment
    'stage' => env('CINDER_STAGE', env('APP_ENV', 'production')),

    // Turn each kind of telemetry on or off
    'report_errors' => (bool) env('CINDER_ERRORS_ENABLED', true),
    'report_traces' => (bool) env('CINDER_TRACING_ENABLED', true),
    'report_logs' => (bool) env('CINDER_LOGS_ENABLED', true),

    // Share of traces to record, from 0.0 (none) to 1.0 (all)
    'trace_sample_rate' => (float) env('CINDER_TRACE_SAMPLE_RATE', 0.1),

    // The deployed commit, attached to every error, trace and log. It is read from the
    // .git folder or your host's variables, set these only to override what is detected.
    'vcs' => [
        'detect' => env('CINDER_VCS_DETECT', true),
        'revision' => env('CINDER_VCS_REVISION'),
        'branch' => env('CINDER_VCS_BRANCH'),
        'message' => env('CINDER_VCS_MESSAGE'),
    ],

    'trace' => [
        // Middleware groups that get a root span for each request
        'middleware_groups' => ['web', 'api'],

        // Record rendered views and Livewire components as spans
        'views' => (bool) env('CINDER_TRACE_VIEWS', true),
        'livewire' => (bool) env('CINDER_TRACE_LIVEWIRE', true),

        // Job classes that are never traced, e.g. App\Jobs\IngestTelemetry
        'ignore_jobs' => array_values(array_filter(array_map(
            trim(...),
            explode(',', (string) env('CINDER_TRACE_IGNORE_JOBS', '')),
        ))),

        // Long-running commands that are never traced. A trailing * matches a prefix, e.g. 'horizon:*'
        'ignore_commands' => [
            'horizon:work',
            'horizon:supervisor',
            'queue:work',
            'queue:listen',
        ],
    ],

    'health' => [
        'endpoint' => [
            // The endpoint answers 404 unless the request sends `Authorization: Bearer <secret>`
            'enabled' => (bool) env('CINDER_HEALTH_ENDPOINT_ENABLED', false),
            'secret' => env('CINDER_HEALTH_CHECK_SECRET'),
            'path' => env('CINDER_HEALTH_ENDPOINT_URL', '/cinder/v1/health'),

            // false: serve the snapshot stored by the last `cinder:health` run instead
            'inspect_on_request' => (bool) env('CINDER_HEALTH_INSPECT_ON_REQUEST', true),
        ],

        // A class name, `Probe::class => [constructor args]`, `Probe::class => false` to disable,
        // or ['probe' => Probe::class, 'with' => [...], 'id' => '...', 'title' => '...'] to add a probe twice
        'probes' => [
            // Cinderwatch\Laravel\Health\Probes\DatabaseConnectionProbe::class,
            // Cinderwatch\Laravel\Health\Probes\CacheRoundTripProbe::class,
            // Cinderwatch\Laravel\Health\Probes\QueueConnectionProbe::class,
            // Cinderwatch\Laravel\Health\Probes\DebugModeProbe::class => ['expectEnabled' => false],
            // Cinderwatch\Laravel\Health\Probes\EnvironmentProbe::class => ['expected' => 'production'],
            // Cinderwatch\Laravel\Health\Probes\OptimisationProbe::class,
            // Cinderwatch\Laravel\Health\Probes\SchedulerHeartbeatProbe::class => ['maxSilenceMinutes' => 2],
            // Cinderwatch\Laravel\Health\Probes\HorizonProbe::class,
            // Cinderwatch\Health\Probes\DiskUsageProbe::class => ['path' => '/', 'warnAbovePercent' => 70, 'failAbovePercent' => 90],
        ],

        // Where snapshots and the scheduler heartbeat are kept (falls back to the default cache store if not set)
        'storage' => [
            'cache_store' => env('CINDER_HEALTH_CACHE_STORE'),
            'key_prefix' => env('CINDER_HEALTH_CACHE_PREFIX', 'cinder:health'),
        ],

        // Let `cinder:health` record the heartbeat read by SchedulerHeartbeatProbe
        'scheduler_heartbeat' => [
            'record' => (bool) env('CINDER_HEALTH_UPDATES_SCHEDULE_HEARTBEAT', true),
        ],

        // This server's name in a multi-server setup
        'node' => [
            'name' => env('CINDER_HEALTH_NODE', 'default'),
            'label' => env('CINDER_HEALTH_NODE_LABEL'),
        ],

        // Other servers whose stored snapshots this server merges into its response.
        // A peer without a snapshot newer than max_age_seconds is reported as unhealthy.
        'peers' => [
            'names' => array_values(array_filter(array_map(
                trim(...),
                explode(',', (string) env('CINDER_HEALTH_AGGREGATE_NODES', '')),
            ))),
            'labels' => [],
            'max_age_seconds' => (int) env('CINDER_HEALTH_AGGREGATE_MAX_AGE_SECONDS', 180),
        ],
    ],

    'schedule_monitor' => [
        'enabled' => (bool) env('CINDER_SCHEDULE_MONITOR_ENABLED', true),
        'api_token' => env('CINDER_KEY'),

        // Overrides the API root (defaults to base_url + /api/v1)
        'api_url' => env('CINDER_SCHEDULE_MONITOR_API_URL'),

        // Minutes Cinder waits past a task's due time before flagging it late
        'grace_minutes' => (int) env('CINDER_SCHEDULE_GRACE_MINUTES', 5),
    ],
];
