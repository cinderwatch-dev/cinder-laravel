<?php

namespace Cinderwatch\Laravel\Tests;

use Cinderwatch\CinderClient;
use Cinderwatch\Config\CinderConfig;
use Cinderwatch\Laravel\CinderManager;
use Cinderwatch\Laravel\CinderServiceProvider;
use Cinderwatch\Transport\ArrayTransport;
use Cinderwatch\Transport\BufferedTransport;
use Cinderwatch\Transport\Transport;
use Illuminate\Support\Facades\Facade;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /** @var array<string, mixed> */
    protected static array $bootConfig = [];

    /** @var list<class-string> */
    protected static array $extraProviders = [];

    /**
     * Boot a fresh application with config that providers read while booting.
     *
     * @param  array<string, mixed>  $config
     * @param  list<class-string>  $providers
     */
    public function rebootWith(array $config, array $providers = []): void
    {
        static::$bootConfig = $config;
        static::$extraProviders = $providers;

        $this->refreshApplication();
    }

    public function bindClient(Transport $transport, ?CinderConfig $config = null): CinderClient
    {
        $config ??= $this->app->make(CinderConfig::class);
        $client = new CinderClient($config, $transport);

        $this->app->instance(CinderConfig::class, $config);
        $this->app->instance(CinderClient::class, $client);
        $this->app->forgetInstance(CinderManager::class);
        Facade::clearResolvedInstance(CinderManager::class);

        return $client;
    }

    /**
     * Bind a client that samples every trace and buffers until flushed.
     */
    public function traceInto(ArrayTransport $transport): CinderClient
    {
        config(['cinder.report_traces' => true, 'cinder.trace_sample_rate' => 1.0]);

        return $this->bindClient(new BufferedTransport($transport), new CinderConfig(
            key: 'test-key',
            baseUrl: 'https://cinder.test',
            reportTraces: true,
            traceSampleRate: 1.0,
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function sentSpans(ArrayTransport $transport): array
    {
        $spans = [];

        foreach ($transport->sent as $request) {
            foreach ($request['payload']['resourceSpans'][0]['scopeSpans'][0]['spans'] ?? [] as $span) {
                $spans[] = $span;
            }
        }

        return $spans;
    }

    /**
     * @param  array<string, mixed>  $span
     */
    public function spanAttribute(array $span, string $key): mixed
    {
        foreach ($span['attributes'] ?? [] as $attribute) {
            if ($attribute['key'] === $key) {
                return array_values($attribute['value'])[0] ?? null;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function spanOfKind(ArrayTransport $transport, string $kind): ?array
    {
        foreach ($this->sentSpans($transport) as $span) {
            if ($this->spanAttribute($span, 'cinder.span.kind') === $kind) {
                return $span;
            }
        }

        return null;
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        static::$bootConfig = [];
        static::$extraProviders = [];
    }

    protected function getPackageProviders($app): array
    {
        return [
            CinderServiceProvider::class,
            ...static::$extraProviders,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('cinder.key', 'test-key');
        $app['config']->set('cinder.base_url', 'https://cinder.test');
        $app['config']->set('cinder.report_errors', true);
        $app['config']->set('cinder.report_traces', false);
        $app['config']->set('cinder.report_logs', false);
        $app['config']->set('cinder.health.endpoint.enabled', true);
        $app['config']->set('cinder.health.endpoint.secret', 'secret');
        $app['config']->set('cinder.schedule_monitor.enabled', false);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('queue.default', 'sync');

        foreach (static::$bootConfig as $key => $value) {
            $app['config']->set($key, $value);
        }
    }
}
