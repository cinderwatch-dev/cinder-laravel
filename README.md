<img src="https://banners.beyondco.de/Cinder%20Laravel.png?theme=light&packageManager=composer+require&packageName=cinderwatch%2Fcinder-laravel&pattern=circuitBoard&style=style_2&description=Errors%2C+logs%2C+traces%2C+health+and+schedule+monitoring+for+Laravel.&md=1&showWatermark=0&fontSize=150px&images=fire&widths=500&heights=500">

[![Latest Version on Packagist](https://img.shields.io/packagist/v/cinderwatch/cinder-laravel.svg?style=flat-square)](https://packagist.org/packages/cinderwatch/cinder-laravel)
[![Total Downloads](https://img.shields.io/packagist/dt/cinderwatch/cinder-laravel.svg?style=flat-square)](https://packagist.org/packages/cinderwatch/cinder-laravel)
[![GitHub-Tests](https://github.com/cinderwatch-dev/cinder-clients/actions/workflows/run-tests.yml/badge.svg?branch=main)](https://github.com/cinderwatch-dev/cinder-clients/actions/workflows/run-tests.yml)
[![GitHub Code Style](https://github.com/cinderwatch-dev/cinder-clients/actions/workflows/fix-php-code-style-issues.yml/badge.svg?branch=main)](https://github.com/cinderwatch-dev/cinder-clients/actions/workflows/fix-php-code-style-issues.yml)
[![PHPStan](https://github.com/cinderwatch-dev/cinder-clients/actions/workflows/phpstan.yml/badge.svg)](https://github.com/cinderwatch-dev/cinder-clients/actions/workflows/phpstan.yml)

> **Develop in the [cinder-clients](https://github.com/cinderwatch-dev/cinder-clients) monorepo** (`packages/cinder-laravel`). This GitHub repository is a read-only split mirror; do not commit here.

This package was developed to give you a quick start to sending errors, logs, performance traces, health checks and scheduled task runs from a Laravel application to [Cinder](https://cinderwatch.dev).

## 📑 Table of Contents

- [What is Cinder?](#-what-is-cinder)
- [Requirements](#-requirements)
- [Installation](#️-installation)
- [Configuration](#configuration)
  - [Environment Variables](#environment-variables)
  - [Deployed Commit](#deployed-commit)
- [Error Reporting](#error-reporting)
  - [Reporting Manually](#reporting-manually)
  - [Filtering Reports](#filtering-reports)
  - [Grouping Reports](#grouping-reports)
  - [Adding Context](#adding-context)
  - [Request Context](#request-context)
- [Logging](#logging)
- [Performance Tracing](#performance-tracing)
  - [Custom Spans](#custom-spans)
  - [Sampling](#sampling)
  - [Ignoring Jobs and Commands](#ignoring-jobs-and-commands)
  - [Outgoing HTTP](#outgoing-http)
- [Health Checks](#health-checks)
  - [Health Endpoint](#health-endpoint)
  - [Probes](#probes)
  - [Registering Probes](#registering-probes)
  - [Scheduling the Health Command](#scheduling-the-health-command)
  - [Multiple Servers](#multiple-servers)
- [Schedule Monitor](#schedule-monitor)
- [Artisan Commands](#artisan-commands)
- [Facade Methods](#facade-methods)
- [Testing](#-testing)
- [Changelog](#-changelog)
- [Contributing](#️-contributing)
  - [Code Style](#code-style)
- [Security Vulnerabilities](#-security-vulnerabilities)
- [Credits](#-credits)
- [License](#-license)

## 💡 What is Cinder?

Cinder is an application monitoring service. It groups the errors your application throws, stores its logs, shows where
time goes in requests, jobs and commands, polls the health checks you define, and tells you when a scheduled task runs
late or fails.

This package is the Laravel adapter. It builds on [cinderwatch/cinder-php](https://github.com/cinderwatch-dev/cinder-php),
which holds the framework-agnostic client.

## 🛠 Requirements

| Package | PHP | Laravel |
|---------|-----|---------|
| **v1.x** | `^8.3` | **^11.0\|^12.0\|^13.0** |

## ⚙️ Installation

You can install the package via composer:

```bash
composer require cinderwatch/cinder-laravel
```

The service provider and the `Cinder` facade are registered automatically.

Add your project key to `.env`:

```dotenv
CINDER_KEY=your-project-api-key
```

Then send a test exception to check everything is wired up:

```bash
php artisan cinder:test
```

Optionally, you can publish the config file with:

```bash
php artisan vendor:publish --provider="Cinderwatch\Laravel\CinderServiceProvider" --tag="cinder-config"
```

## Configuration

Every option lives in `config/cinder.php` and can be set from the environment.

### Environment Variables

| Variable | Default | Config key | Description |
|----------|---------|------------|-------------|
| `CINDER_KEY` | | `key`, `schedule_monitor.api_token` | Project API key. Nothing is sent without it. |
| `CINDER_URL` | `https://cinderwatch.dev` | `base_url` | The Cinder instance to send to. |
| `CINDER_SERVICE_NAME` | `APP_NAME` | `service_name` | Reported as `service.name`. |
| `CINDER_SERVICE_VERSION` | | `service_version` | Reported as `service.version`. |
| `CINDER_STAGE` | `APP_ENV` | `stage` | Reported as the deployment environment. |
| `CINDER_ERRORS_ENABLED` | `true` | `report_errors` | Send error reports. |
| `CINDER_TRACING_ENABLED` | `true` | `report_traces` | Record performance traces. |
| `CINDER_LOGS_ENABLED` | `true` | `report_logs` | Send records written to the `cinder` log channel. |
| `CINDER_TRACE_SAMPLE_RATE` | `0.1` | `trace_sample_rate` | Share of traces to record, from `0.0` to `1.0`. |
| `CINDER_VCS_DETECT` | `true` | `vcs.detect` | Detect the deployed commit, see [Deployed Commit](#deployed-commit). |
| `CINDER_VCS_REVISION` | Detected | `vcs.revision` | Deployed commit hash. |
| `CINDER_VCS_BRANCH` | Detected | `vcs.branch` | Deployed branch. |
| `CINDER_VCS_MESSAGE` | Detected | `vcs.message` | Deployed commit message. |
| `CINDER_TRACE_VIEWS` | `true` | `trace.views` | Record rendered views as spans. |
| `CINDER_TRACE_LIVEWIRE` | `true` | `trace.livewire` | Record Livewire components as spans. |
| `CINDER_TRACE_IGNORE_JOBS` | | `trace.ignore_jobs` | Comma separated job classes that are never traced. |
| `CINDER_HEALTH_ENDPOINT_ENABLED` | `false` | `health.endpoint.enabled` | Register the health endpoint. |
| `CINDER_HEALTH_CHECK_SECRET` | | `health.endpoint.secret` | Bearer token Cinder sends to the endpoint. |
| `CINDER_HEALTH_ENDPOINT_URL` | `/cinder/v1/health` | `health.endpoint.path` | Path of the health endpoint. |
| `CINDER_HEALTH_INSPECT_ON_REQUEST` | `true` | `health.endpoint.inspect_on_request` | Run the probes on each request, or serve the last stored snapshot. |
| `CINDER_HEALTH_CACHE_STORE` | default store | `health.storage.cache_store` | Cache store for snapshots and the scheduler heartbeat. |
| `CINDER_HEALTH_CACHE_PREFIX` | `cinder:health` | `health.storage.key_prefix` | Prefix for those cache keys. |
| `CINDER_HEALTH_UPDATES_SCHEDULE_HEARTBEAT` | `true` | `health.scheduler_heartbeat.record` | Let `cinder:health` record the scheduler heartbeat. |
| `CINDER_HEALTH_NODE` | `default` | `health.node.name` | This server's name. |
| `CINDER_HEALTH_NODE_LABEL` | | `health.node.label` | This server's display name. |
| `CINDER_HEALTH_AGGREGATE_NODES` | | `health.peers.names` | Comma separated names of the other servers. |
| `CINDER_HEALTH_AGGREGATE_MAX_AGE_SECONDS` | `180` | `health.peers.max_age_seconds` | Oldest peer snapshot still counted as fresh (at least 30). |
| `CINDER_SCHEDULE_MONITOR_ENABLED` | `true` | `schedule_monitor.enabled` | Send scheduled task heartbeats. |
| `CINDER_SCHEDULE_MONITOR_API_URL` | `CINDER_URL` + `/api/v1` | `schedule_monitor.api_url` | API root for the schedule monitor. |
| `CINDER_SCHEDULE_GRACE_MINUTES` | `5` | `schedule_monitor.grace_minutes` | Minutes Cinder waits past a task's due time before flagging it late. |

A few options only exist in the config file: `trace.middleware_groups`, `trace.ignore_commands`, `health.probes` and
`health.peers.labels`. They are covered in the sections below.

Telemetry is buffered and sent when the request terminates, after each queue job and when a job fails, so calls to
Cinder never slow down the response.

### Deployed Commit

Every error, trace and log is tagged with the commit that is running, so you can see which deploy introduced a
problem. There is nothing to set up: Cinder reads the commit, branch and message from the `.git` folder of your app
(or up to three folders above it, for apps inside a monorepo). It reads the files directly, so it works on hosts that
disable `shell_exec`.

When you deploy without a `.git` folder, for example in a Docker image, the commit is taken from the variables these
platforms set: GitHub Actions (`GITHUB_SHA`), Vercel (`VERCEL_GIT_COMMIT_SHA`), Render (`RENDER_GIT_COMMIT`) and
Heroku (`SOURCE_VERSION`). Anywhere else, write it to your `.env` during the deploy:

```dotenv
CINDER_VCS_REVISION=3f2c1a9...
CINDER_VCS_BRANCH=main
CINDER_VCS_MESSAGE="Fix checkout totals"
```

A configured revision always wins, and the branch and message are only used alongside it. The commit message is left
out once git has packed the commit (after `git gc`), because reading it would mean unpacking git's object store. Set
`CINDER_VCS_DETECT=false` to turn detection off.

## Error Reporting

Send every exception Laravel reports to Cinder from `bootstrap/app.php`:

```php
use Cinderwatch\Laravel\Facades\Cinder;
use Illuminate\Foundation\Configuration\Exceptions;

->withExceptions(function (Exceptions $exceptions) {
    Cinder::reportExceptions($exceptions);
})
```

Exceptions Laravel does not report (for example those passed to `dontReport()`) are not sent either. Each report
carries the stack frames with source excerpts, the chain of previous exceptions, and the trace it happened in.

### Reporting Manually

```php
use Cinderwatch\Laravel\Facades\Cinder;

try {
    $gateway->charge($order);
} catch (PaymentFailed $exception) {
    Cinder::report($exception, context: ['order.id' => $order->id], handled: true);
}
```

`report()` returns the payload that was queued, or `null` when the exception was filtered out or error reporting is
turned off.

### Filtering Reports

Only report the exceptions a predicate accepts, for example in a service provider's `boot()` method:

```php
Cinder::reportOnlyWhen(fn (Throwable $exception) => ! $exception instanceof TooManyRequestsHttpException);
```

To change or scrub the payload before it is sent:

```php
Cinder::beforeSend(function (array $payload, Throwable $exception) {
    unset($payload['context']['user.email']);

    return $payload;
});
```

### Grouping Reports

Errors are grouped by exception type and message. Pass a fingerprint to group them yourself:

```php
Cinder::report($exception, fingerprint: ['checkout', 'payment-declined']);
```

Or decide for every report. Return `null` to keep the default grouping:

```php
Cinder::fingerprintUsing(fn (Throwable $exception) => $exception instanceof PaymentFailed ? ['payments'] : null);
```

### Adding Context

To attach your own attributes to every report, add an enricher. It receives the exception and returns a flat array
that is merged over the report context:

```php
use Cinderwatch\Errors\ReportEnricher;

class TenantEnricher implements ReportEnricher
{
    public function attributesFor(Throwable $throwable): array
    {
        return ['tenant.id' => tenant()?->id];
    }
}

Cinder::addEnricher(new TenantEnricher);
```

### Request Context

Errors raised during an HTTP request include the method, full URL, path, user agent, route template and route action.
When a user is signed in, their id and email are added as `user.id` and `user.email`. Console and queue errors are
sent without request context.

## Logging

Add a `cinder` channel to `config/logging.php`:

```php
'channels' => [
    'stack' => [
        'driver' => 'stack',
        'channels' => ['single', 'cinder'],
    ],

    'cinder' => [
        'driver' => 'cinder',
        'level' => env('LOG_LEVEL', 'debug'),
    ],
],
```

Records at or above `level` are sent with their context. Keys such as `password`, `token`, `authorization` and `cookie`
are redacted before they leave the application. Logs written during a traced request, job or command are linked to that
trace. Set `CINDER_LOGS_ENABLED=false` to stop sending them without touching the channel.

To send a single entry to Cinder without adding a channel:

```php
Cinder::log('Import finished', 'info', ['rows' => 1200]);
```

## Performance Tracing

With `CINDER_TRACING_ENABLED=true` (the default) the package records:

- **Web and API requests** - a root span for every route in the `web` and `api` middleware groups
- **Queued jobs** - one trace per job, marked as succeeded or failed
- **Artisan commands** - one trace per command, with its exit code
- **Database queries** - a child span for every query with its SQL and connection
- **Rendered views** - a span per view, include and Blade component, nested under the view that rendered it (`CINDER_TRACE_VIEWS`)
- **Livewire components** - a span per component mount or update when Livewire 3 or 4 is installed (`CINDER_TRACE_LIVEWIRE`)
- **Outgoing HTTP** - a span per request made with the `Http` facade or Saloon, see [Outgoing HTTP](#outgoing-http)

To trace routes in another middleware group, list it in the config:

```php
'trace' => [
    'middleware_groups' => ['web', 'api', 'webhooks'],
],
```

### Custom Spans

Wrap any piece of work in `Cinder::trace()` to see it as its own span, nested under the request, job or command that
ran it. The callback's return value is passed straight back:

```php
$invoices = Cinder::trace('Build invoices', fn () => $this->invoices->build($order), [
    'order.id' => $order->id,
]);
```

The callback receives the span, so you can add attributes once you know them:

```php
use Cinderwatch\Tracing\Span;

Cinder::trace('Sync stock', function (Span $span) {
    $updated = $this->stock->sync();

    $span->annotate(['stock.updated' => $updated]);
});
```

### Sampling

`CINDER_TRACE_SAMPLE_RATE` sets the share of traces that are recorded. The decision is made once for the root span, so
a trace is always recorded whole or not at all. Errors and logs from traces that were not sampled are still sent.

```dotenv
# Record every trace while you try it out
CINDER_TRACE_SAMPLE_RATE=1.0
```

### Ignoring Jobs and Commands

Long-running workers are never traced. Add your own commands to `trace.ignore_commands`, where a trailing `*` matches
a prefix:

```php
'trace' => [
    'ignore_commands' => [
        'horizon:work',
        'horizon:supervisor',
        'queue:work',
        'queue:listen',
        'reverb:*',
    ],
],
```

Noisy jobs can be skipped by class name:

```dotenv
CINDER_TRACE_IGNORE_JOBS="App\Jobs\IngestTelemetry,App\Jobs\SyncSearchIndex"
```

### Outgoing HTTP

Requests made with Laravel's `Http` facade are recorded automatically, and so are Saloon requests when
`saloonphp/saloon` is installed. Each span carries the URL, method and status code. A request that got no response
(DNS, TLS or a timeout) is recorded with status `503`. Requests to your Cinder instance are never recorded.

For a Guzzle client you build yourself, push the middleware onto its handler stack:

```php
use Cinderwatch\Laravel\Tracing\OutboundHttpSpans;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;

$stack = HandlerStack::create();
$stack->push(OutboundHttpSpans::guzzleMiddleware());

$client = new Client(['handler' => $stack]);
```

## Health Checks

Cinder polls an endpoint in your application and shows the result of each probe you register.

### Health Endpoint

```dotenv
CINDER_HEALTH_ENDPOINT_ENABLED=true
CINDER_HEALTH_CHECK_SECRET=a-long-random-string
```

Add the endpoint URL (`https://your-app.test/cinder/v1/health` unless you change `CINDER_HEALTH_ENDPOINT_URL`) and the
same secret to the health check in your Cinder project. The endpoint answers `404` unless the request sends
`Authorization: Bearer <secret>`, so it cannot be discovered.

A response looks like this:

```json
{
    "generated_at": "2026-01-01T00:00:00.000000Z",
    "condition": "degraded",
    "probes": [
        {
            "id": "queue",
            "title": "Queue",
            "condition": "degraded",
            "summary": "Backlog",
            "detail": "500 jobs waiting",
            "facts": {"pending_jobs": 500}
        }
    ]
}
```

A probe's condition is `healthy`, `degraded`, `unhealthy`, `errored` (the probe threw) or `not_applicable`.

### Probes

| Probe | Options | Checks |
|-------|---------|--------|
| `Cinderwatch\Laravel\Health\Probes\DatabaseConnectionProbe` | `connection` | A database connection can be opened. |
| `Cinderwatch\Laravel\Health\Probes\CacheRoundTripProbe` | `store` | A value written to a cache store can be read back. |
| `Cinderwatch\Laravel\Health\Probes\QueueConnectionProbe` | `connection`, `queue` | The queue backend answers, and reports the pending job count. |
| `Cinderwatch\Laravel\Health\Probes\DebugModeProbe` | `expectEnabled` (`false`) | `app.debug` matches what this environment should run. |
| `Cinderwatch\Laravel\Health\Probes\EnvironmentProbe` | `expected` (`production`) | The application booted in the intended environment. |
| `Cinderwatch\Laravel\Health\Probes\OptimisationProbe` | | Config and routes are cached (degraded when they are not). |
| `Cinderwatch\Laravel\Health\Probes\SchedulerHeartbeatProbe` | `maxSilenceMinutes` (`2`) | The scheduler ran `cinder:health` recently. |
| `Cinderwatch\Laravel\Health\Probes\HorizonProbe` | | A Horizon master supervisor is running and none is paused. Not applicable without Horizon. |
| `Cinderwatch\Health\Probes\DiskUsageProbe` | `path`, `warnAbovePercent` (`70`), `failAbovePercent` (`90`) | Disk usage on a path. |
| `Cinderwatch\Health\Probes\PhpRuntimeProbe` | `minimumVersion` | The running PHP version. |
| `Cinderwatch\Health\Probes\UrlReachableProbe` | `url`, `timeoutSeconds`, `requireSuccessStatus` | A URL responds. |
| `Cinderwatch\Health\Probes\ComposerAdvisoriesProbe` | `lockFile`, `timeoutSeconds` | Installed packages with known security advisories. |

### Registering Probes

List probes in `config/cinder.php`. An entry can be a class name, a class name with its options, `false` to turn a
probe off (handy with `env()`), or an array when you need the same probe twice:

```php
use Cinderwatch\Health\Probes\DiskUsageProbe;
use Cinderwatch\Laravel\Health\Probes;

'health' => [
    'probes' => [
        Probes\DatabaseConnectionProbe::class,
        Probes\CacheRoundTripProbe::class,
        Probes\QueueConnectionProbe::class,
        Probes\DebugModeProbe::class => ['expectEnabled' => false],
        Probes\OptimisationProbe::class,
        Probes\SchedulerHeartbeatProbe::class => ['maxSilenceMinutes' => 2],
        Probes\HorizonProbe::class => env('HORIZON_ENABLED', true),
        DiskUsageProbe::class => ['path' => '/', 'warnAbovePercent' => 70, 'failAbovePercent' => 90],
        [
            'probe' => Probes\DatabaseConnectionProbe::class,
            'with' => ['connection' => 'replica'],
            'id' => 'replica-db',
            'title' => 'Replica database',
        ],
    ],
],
```

Probes are resolved through the container, so framework services are injected and the options fill the named
constructor parameters.

Your own probes implement `Cinderwatch\Health\Probe`:

```php
use Cinderwatch\Health\Observation;
use Cinderwatch\Health\Probe;
use Illuminate\Support\Facades\DB;

class FailedJobsProbe implements Probe
{
    public function id(): string
    {
        return 'failed-jobs';
    }

    public function title(): string
    {
        return 'Failed jobs';
    }

    public function examine(): Observation
    {
        $failed = DB::table('failed_jobs')->count();

        if ($failed > 0) {
            return Observation::degraded("{$failed} failed", "{$failed} jobs are waiting in failed_jobs.", ['failed_jobs' => $failed]);
        }

        return Observation::healthy('None', ['failed_jobs' => 0]);
    }
}
```

Add it to `health.probes`, or register it in code:

```php
Cinder::registerProbe(new FailedJobsProbe);

// Replace every configured probe
Cinder::probes([new FailedJobsProbe]);
```

### Scheduling the Health Command

Schedule `cinder:health` every minute. It runs the probes, stores this server's snapshot and records the heartbeat read
by `SchedulerHeartbeatProbe`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('cinder:health')->everyMinute();
```

Pass `--strict` to exit with a failure code when any probe is degraded, unhealthy or errored.

When probes are slow, set `CINDER_HEALTH_INSPECT_ON_REQUEST=false` and the endpoint serves the snapshot stored by the
last `cinder:health` run instead of running them on every poll.

### Multiple Servers

When the application runs on more than one server, every server stores its snapshot in a shared cache store and the
server that answers the endpoint merges them:

```dotenv
# On every server
CINDER_HEALTH_CACHE_STORE=redis

# On the worker
CINDER_HEALTH_NODE=worker

# On the web server that serves the endpoint
CINDER_HEALTH_NODE=web
CINDER_HEALTH_NODE_LABEL="Web server"
CINDER_HEALTH_AGGREGATE_NODES=worker
```

Labels for the other servers go in `health.peers.labels`, for example `['worker' => 'Queue worker']`. A server whose
snapshot is missing or older than `CINDER_HEALTH_AGGREGATE_MAX_AGE_SECONDS` is marked stale and reported as an
unhealthy `<name>-snapshot` probe, so a dead worker shows up instead of disappearing.

## Schedule Monitor

Register your scheduled tasks with Cinder. Run it again after each deploy, because it replaces the monitored task list:

```bash
php artisan cinder:schedule-sync
```

Each task is keyed by its command (or the job or closure description) and sent with its cron expression, timezone,
description and grace period. From then on every run sends a `started` heartbeat and then a `succeeded` or `failed`
one with its duration, peak memory, exit code and error message.

Give closures a description so they keep the same key between deploys:

```php
Schedule::call(fn () => Report::prune())->daily()->description('Prune reports');
```

Monitoring uses `CINDER_KEY` and can be turned off with `CINDER_SCHEDULE_MONITOR_ENABLED=false`.

## Artisan Commands

| Command | Description |
|---------|-------------|
| `php artisan cinder:test` | Send a test exception to Cinder. |
| `php artisan cinder:health {--strict}` | Run the health probes, store this server's snapshot and record the scheduler heartbeat. |
| `php artisan cinder:schedule-sync` | Replace the scheduled tasks Cinder monitors with this application's schedule. |

## Facade Methods

```php
use Cinderwatch\Laravel\Facades\Cinder;

// Send every reported exception to Cinder, from bootstrap/app.php
Cinder::reportExceptions($exceptions);

// Report an exception, returns the payload or null when filtered
Cinder::report($exception, context: [], handled: null, fingerprint: null);

// Only report exceptions the predicate accepts
Cinder::reportOnlyWhen(fn (Throwable $exception) => true);

// Group reports by a custom fingerprint, null keeps the default
Cinder::fingerprintUsing(fn (Throwable $exception) => null);

// Change or scrub a report right before it is sent
Cinder::beforeSend(fn (array $payload, Throwable $exception) => $payload);

// Add attributes to every report
Cinder::addEnricher(new TenantEnricher);

// Record a custom span around the callback and return its result
Cinder::trace('Build invoices', fn () => $builder->build());

// Send a log entry without a log channel
Cinder::log('Import finished', 'info', ['rows' => 1200]);

// Add probes, or replace every registered probe
Cinder::registerProbe(new FailedJobsProbe);
Cinder::probes([new FailedJobsProbe]);

// Send everything that is still buffered now, instead of when the app terminates
Cinder::flush();

// The underlying objects, when you need something the facade does not cover
Cinder::client();
Cinder::tracer();
Cinder::health();
```

## 🚧 Testing

Copy your own phpunit.xml-file.

```bash
cp phpunit.xml.dist phpunit.xml
```

Run the tests:

```bash
./vendor/bin/pest
```

## 📝 Changelog

Please see [CHANGELOG](CHANGELOG.md) for recent changes.

## ✏️ Contributing

Please see [CONTRIBUTING](.github/CONTRIBUTING.md) for details.

```bash
composer test
```

### Code Style

```bash
./vendor/bin/pint
```

## 🧑‍💻 Security Vulnerabilities

Please review [our security policy](.github/SECURITY.md) on reporting security vulnerabilities.

## 🙏 Credits

- [Rhys Lees](https://github.com/RhysLees)
- [All Contributors](../../contributors)
- [Skeleton Repository from Spatie](https://github.com/spatie/package-skeleton-laravel)
- [Laravel Package Tools from Spatie](https://github.com/spatie/laravel-package-tools)

## 🎭 License

The MIT License (MIT). Please have a look at [License File](LICENSE.md) for more information.
