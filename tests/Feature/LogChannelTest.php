<?php

use Cinderwatch\Config\CinderConfig;
use Cinderwatch\Laravel\Logs\CinderLogHandler;
use Cinderwatch\Transport\ArrayTransport;
use Cinderwatch\Transport\BufferedTransport;
use Illuminate\Support\Facades\Log;
use Monolog\Level;

beforeEach(function () {
    $this->transport = new ArrayTransport;
    $this->client = $this->bindClient(new BufferedTransport($this->transport), new CinderConfig(
        key: 'test-key',
        baseUrl: 'https://cinder.test',
        reportLogs: true,
    ));

    config([
        'cinder.report_logs' => true,
        'logging.channels.cinder' => ['driver' => 'cinder', 'level' => 'warning'],
    ]);
});

function sentLogRecords(ArrayTransport $transport): array
{
    return $transport->sent[0]['payload']['resourceLogs'][0]['scopeLogs'][0]['logRecords'] ?? [];
}

it('sends records at or above the channel level to cinder', function () {
    Log::channel('cinder')->info('ignored');
    Log::channel('cinder')->error('Payment failed', ['order' => 42]);
    $this->client->flush();

    $records = sentLogRecords($this->transport);

    expect($this->transport->sent[0]['url'])->toBe('https://cinder.test/api/v1/logs')
        ->and($records)->toHaveCount(1)
        ->and($records[0]['body']['stringValue'])->toBe('Payment failed')
        ->and($records[0]['severityText'])->toBe('error');
})->group('logs');

it('uses the debug level when the channel sets none', function () {
    $handler = Log::build(['driver' => 'cinder'])->getLogger()->getHandlers()[0];

    expect($handler)->toBeInstanceOf(CinderLogHandler::class)
        ->and($handler->getLevel())->toBe(Level::Debug);
})->group('logs');

it('drops records when log reporting is turned off', function () {
    config(['cinder.report_logs' => false]);

    Log::channel('cinder')->error('Payment failed');
    $this->client->flush();

    expect($this->transport->sent)->toBeEmpty();
})->group('logs');

it('drops records when no cinder key is configured', function () {
    config(['cinder.key' => null]);

    Log::channel('cinder')->error('Payment failed');
    $this->client->flush();

    expect($this->transport->sent)->toBeEmpty();
})->group('logs');
