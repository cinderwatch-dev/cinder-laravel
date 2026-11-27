<?php

use Cinderwatch\Config\CinderConfig;
use Cinderwatch\Laravel\Facades\Cinder;
use Cinderwatch\Laravel\Tests\Fixtures\Jobs\LogsFromJob;
use Cinderwatch\Transport\ArrayTransport;
use Cinderwatch\Transport\BufferedTransport;

function bufferLogsInto(ArrayTransport $transport): void
{
    test()->bindClient(new BufferedTransport($transport), new CinderConfig(
        key: 'test-key',
        baseUrl: 'https://cinder.test',
        reportLogs: true,
    ));
}

it('flushes buffered telemetry after each queue job', function () {
    $transport = new ArrayTransport;
    bufferLogsInto($transport);

    dispatch_sync(new LogsFromJob);

    expect($transport->sent)->toHaveCount(1)
        ->and($transport->sent[0]['url'])->toBe('https://cinder.test/api/v1/logs');
})->group('telemetry');

it('flushes buffered telemetry when the application terminates', function () {
    $transport = new ArrayTransport;
    bufferLogsInto($transport);

    Cinder::log('request finished');
    app()->terminate();

    expect($transport->sent)->toHaveCount(1);
})->group('telemetry');

it('keeps telemetry buffered when no cinder key is configured', function () {
    $transport = new ArrayTransport;
    bufferLogsInto($transport);
    config(['cinder.key' => null]);

    dispatch_sync(new LogsFromJob);

    expect($transport->sent)->toBeEmpty();
})->group('telemetry');
