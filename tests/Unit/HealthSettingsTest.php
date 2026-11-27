<?php

use Cinderwatch\Laravel\Health\HealthSettings;

it('falls back to defaults for missing or empty settings', function () {
    config([
        'cinder.health.node.name' => '',
        'cinder.health.storage.key_prefix' => '',
        'cinder.health.probes' => 'not-a-list',
        'cinder.health.peers.max_age_seconds' => 5,
    ]);

    $settings = app(HealthSettings::class);

    expect($settings->nodeName())->toBe('default')
        ->and($settings->labelFor())->toBe('Application')
        ->and($settings->snapshotKey())->toBe('cinder:health:snapshot:default')
        ->and($settings->heartbeatKey())->toBe('cinder:health:scheduler-heartbeat')
        ->and($settings->probeDefinitions())->toBe([])
        ->and($settings->peerMaxAgeSeconds())->toBe(30);
})->group('health');

it('reads peers from a list or a comma separated string', function (mixed $peers, array $expected) {
    config(['cinder.health.node.name' => 'web', 'cinder.health.peers.names' => $peers]);

    expect(app(HealthSettings::class)->peerNames())->toBe($expected);
})->with([
    'list' => [['worker', 'cron'], ['worker', 'cron']],
    'string' => ['worker, cron,,web', ['worker', 'cron']],
    'duplicates' => [['worker', 'worker'], ['worker']],
    'not a list' => [42, []],
])->group('health');

it('uses the configured cache store', function () {
    config(['cache.stores.health' => ['driver' => 'array'], 'cinder.health.storage.cache_store' => 'health']);

    $settings = app(HealthSettings::class);
    $settings->cache()->put('probe', 'value');

    expect(cache()->store('health')->get('probe'))->toBe('value')
        ->and(cache()->get('probe'))->toBeNull();
})->group('health');
