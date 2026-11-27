<?php

namespace Cinderwatch\Laravel;

use Cinderwatch\CinderClient;
use Cinderwatch\Config\CinderConfig;
use Cinderwatch\Health\Inspector;
use Cinderwatch\Health\Wire\SnapshotSerializer;
use Cinderwatch\Laravel\Commands\InspectHealthCommand;
use Cinderwatch\Laravel\Commands\ScheduleSyncCommand;
use Cinderwatch\Laravel\Commands\TestCommand;
use Cinderwatch\Laravel\Errors\RequestContextEnricher;
use Cinderwatch\Laravel\Health\ClusterAssembler;
use Cinderwatch\Laravel\Health\HealthSettings;
use Cinderwatch\Laravel\Health\Http\HealthEndpointController;
use Cinderwatch\Laravel\Health\ProbeFactory;
use Cinderwatch\Laravel\Health\SchedulerHeartbeat;
use Cinderwatch\Laravel\Health\SnapshotRepository;
use Cinderwatch\Laravel\Logs\CinderLoggerFactory;
use Cinderwatch\Laravel\ScheduleMonitor\ScheduleMonitor;
use Cinderwatch\Laravel\Tracing\CommandTraceListener;
use Cinderwatch\Laravel\Tracing\HttpClientTraceListener;
use Cinderwatch\Laravel\Tracing\JobTraceListener;
use Cinderwatch\Laravel\Tracing\LivewireTracer;
use Cinderwatch\Laravel\Tracing\QuerySpanListener;
use Cinderwatch\Laravel\Tracing\SaloonOutgoingHttpTracer;
use Cinderwatch\Laravel\Tracing\TracedViewEngine;
use Cinderwatch\Laravel\Tracing\TraceRequests;
use Cinderwatch\Support\DeployedRevision;
use Cinderwatch\Transport\BufferedTransport;
use Cinderwatch\Transport\SyncTransport;
use DateTimeImmutable;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Http\Kernel as FoundationHttpKernel;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Routing\Router;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class CinderServiceProvider extends PackageServiceProvider
{
    /** Reported as `telemetry.sdk.name` / `sdk.name`. */
    public const SDK_NAME = 'cinderwatch/cinder-laravel';

    /** Reported as `telemetry.sdk.version` / `sdk.version`. */
    public const SDK_VERSION = '1.0.0';

    public function configurePackage(Package $package): void
    {
        $package
            ->name('cinder')
            ->hasConfigFile()
            ->hasCommands([
                TestCommand::class,
                InspectHealthCommand::class,
                ScheduleSyncCommand::class,
            ]);
    }

    public function registeringPackage(): void
    {
        $this->app->singleton(CinderConfig::class, function (): CinderConfig {
            $revision = DeployedRevision::resolve(
                base_path(),
                config('cinder.vcs.revision'),
                config('cinder.vcs.branch'),
                config('cinder.vcs.message'),
                (bool) config('cinder.vcs.detect', true),
            );

            return new CinderConfig(
                key: (string) config('cinder.key', ''),
                baseUrl: (string) config('cinder.base_url', 'https://cinderwatch.dev'),
                serviceName: (string) config('cinder.service_name', config('app.name', 'Laravel')),
                serviceVersion: config('cinder.service_version'),
                stage: config('cinder.stage', config('app.env')),
                reportErrors: (bool) config('cinder.report_errors', true),
                reportTraces: (bool) config('cinder.report_traces', true),
                reportLogs: (bool) config('cinder.report_logs', true),
                traceSampleRate: (float) config('cinder.trace_sample_rate', 0.1),
                applicationPath: base_path(),
                sdkName: self::SDK_NAME,
                sdkVersion: self::SDK_VERSION,
                frameworkName: 'laravel',
                frameworkVersion: $this->app->version(),
                vcsRevision: $revision->revision,
                vcsBranch: $revision->branch,
                vcsMessage: $revision->message,
            );
        });

        $this->app->singleton(CinderClient::class, function ($app): CinderClient {
            $config = $app->make(CinderConfig::class);
            $transport = new BufferedTransport(new SyncTransport($config));
            $client = new CinderClient($config, $transport);
            $client->addEnricher($app->make(RequestContextEnricher::class));

            return $client;
        });

        $this->registerHealthServices();
        $this->app->singleton(ScheduleMonitor::class);
        $this->app->singleton(CinderManager::class, function ($app): CinderManager {
            return new CinderManager(
                $app->make(CinderClient::class),
                $app->make(Inspector::class),
            );
        });

        $this->app->singleton(CinderLoggerFactory::class);
        $this->app->singleton(JobTraceListener::class);
        $this->app->singleton(CommandTraceListener::class);
        $this->app->singleton(HttpClientTraceListener::class);
        $this->app->singleton(LivewireTracer::class);
    }

    public function packageBooted(): void
    {
        Log::extend('cinder', function ($app, array $config) {
            return $app->make(CinderLoggerFactory::class)($config);
        });

        $healthSettings = $this->app->make(HealthSettings::class);

        if ($healthSettings->endpointEnabled()) {
            /** @var Router $router */
            $router = $this->app->make(Router::class);
            $router->get($healthSettings->endpointPath(), HealthEndpointController::class);
        }

        if (config('cinder.report_traces')) {
            // Sanctum and Filament re-sync the kernel's middleware groups while
            // booting, which would drop middleware pushed any earlier.
            $this->app->booted(function (): void {
                $this->registerRequestTracing();
                $this->registerViewTracing();
                $this->registerLivewireTracing();
            });

            Event::listen(QueryExecuted::class, QuerySpanListener::class);
        }

        Event::listen(JobProcessing::class, [JobTraceListener::class, 'handleProcessing']);
        Event::listen(JobProcessed::class, [JobTraceListener::class, 'handleProcessed']);
        Event::listen(JobFailed::class, [JobTraceListener::class, 'handleFailed']);

        Event::listen(CommandStarting::class, [CommandTraceListener::class, 'handleStarting']);
        Event::listen(CommandFinished::class, [CommandTraceListener::class, 'handleFinished']);

        Event::listen(RequestSending::class, [HttpClientTraceListener::class, 'handleRequestSending']);
        Event::listen(ResponseReceived::class, [HttpClientTraceListener::class, 'handleResponseReceived']);
        Event::listen(ConnectionFailed::class, [HttpClientTraceListener::class, 'handleConnectionFailed']);

        SaloonOutgoingHttpTracer::register();

        if (config('cinder.schedule_monitor.enabled')) {
            Event::listen(ScheduledTaskStarting::class, [ScheduleMonitor::class, 'handleStarting']);
            Event::listen(ScheduledTaskFinished::class, [ScheduleMonitor::class, 'handleFinished']);
            Event::listen(ScheduledTaskFailed::class, [ScheduleMonitor::class, 'handleFailed']);
        }

        $this->registerTelemetryFlush();
    }

    protected function registerHealthServices(): void
    {
        $this->app->singleton(HealthSettings::class);
        $this->app->singleton(SnapshotSerializer::class);
        $this->app->singleton(SnapshotRepository::class);
        $this->app->singleton(SchedulerHeartbeat::class);
        $this->app->singleton(ClusterAssembler::class);
        $this->app->singleton(Inspector::class, function ($app): Inspector {
            return new Inspector(
                $app->make(ProbeFactory::class)->fromDefinitions($app->make(HealthSettings::class)->probeDefinitions()),
                fn (): DateTimeImmutable => Carbon::now()->toDateTimeImmutable(),
            );
        });
    }

    /**
     * Added to the kernel's own groups when it is resolved, so a later re-sync keeps it.
     */
    protected function registerRequestTracing(): void
    {
        $kernel = $this->app->resolved(HttpKernel::class) ? $this->app->make(HttpKernel::class) : null;

        /** @var Router $router */
        $router = $this->app->make(Router::class);

        foreach (config('cinder.trace.middleware_groups', ['web', 'api']) as $group) {
            $group = (string) $group;

            if ($kernel instanceof FoundationHttpKernel && array_key_exists($group, $kernel->getMiddlewareGroups())) {
                $kernel->appendMiddlewareToGroup($group, TraceRequests::class);

                continue;
            }

            $router->pushMiddlewareToGroup($group, TraceRequests::class);
        }
    }

    protected function registerViewTracing(): void
    {
        if (! config('cinder.trace.views', true) || ! $this->app->bound('view.engine.resolver')) {
            return;
        }

        TracedViewEngine::decorate(
            $this->app->make('view.engine.resolver'),
            $this->app,
        );
    }

    protected function registerLivewireTracing(): void
    {
        if (! config('cinder.trace.livewire', true) || ! LivewireTracer::livewireInstalled()) {
            return;
        }

        $this->app->make(LivewireTracer::class)->register();
    }

    protected function registerTelemetryFlush(): void
    {
        $flush = function (): void {
            if (filled(config('cinder.key'))) {
                $this->app->make(CinderClient::class)->flush();
            }
        };

        $this->app->terminating($flush);

        Queue::after($flush);
        Event::listen(JobFailed::class, $flush);
    }
}
