<?php

use Cinderwatch\CinderClient;
use Cinderwatch\Config\CinderConfig;
use Cinderwatch\Laravel\Facades\Cinder;
use Cinderwatch\Transport\ArrayTransport;
use Cinderwatch\Transport\SyncTransport;
use Cinderwatch\Transport\Transport;

function resolveClientWithConfig(array $config): void
{
    config(['cinder.key' => 'test-key', 'cinder.base_url' => 'https://cinder.test', 'cinder.report_logs' => true, ...$config]);

    app()->forgetInstance(CinderConfig::class);
    app()->forgetInstance(CinderClient::class);
}

it('delivers telemetry through the transport bound in the app', function () {
    $transport = new ArrayTransport;
    app()->instance(Transport::class, $transport);
    resolveClientWithConfig([]);

    Cinder::log('request finished');
    Cinder::flush();

    expect($transport->sent)->toHaveCount(1)
        ->and($transport->sent[0]['url'])->toBe('https://cinder.test/api/v1/logs');
});

it('sends over HTTP by default', function () {
    resolveClientWithConfig([]);

    expect(app(Transport::class))->toBeInstanceOf(SyncTransport::class);
});

it('gives up on a Cinder that never answers after the configured timeout', function () {
    $server = stream_socket_server('tcp://127.0.0.1:0');
    $address = stream_socket_get_name($server, false);

    app()->singleton(CinderConfig::class, fn (): CinderConfig => new CinderConfig(
        key: 'test-key',
        baseUrl: 'http://'.$address,
        timeoutSeconds: 1,
        maxRetries: 0,
    ));

    $startedAt = microtime(true);
    app(Transport::class)->send('http://'.$address.'/api/v1/logs', ['resourceLogs' => []]);

    expect(microtime(true) - $startedAt)->toBeLessThan(3);

    fclose($server);
});
