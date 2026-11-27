<?php

use Cinderwatch\Health\AliasedProbe;
use Cinderwatch\Health\Observation;
use Cinderwatch\Laravel\Facades\Cinder;
use Cinderwatch\Laravel\Health\SchedulerHeartbeat;
use Cinderwatch\Laravel\Health\SnapshotRepository;
use Cinderwatch\Laravel\Tests\Fixtures\StubProbe;

it('succeeds by default even when a probe is unhealthy', function () {
    Cinder::probes([new StubProbe(observation: Observation::unhealthy('Broken', 'It broke'))]);

    $this->artisan('cinder:health')->assertSuccessful();
})->group('commands');

it('fails in strict mode when a probe is degraded', function () {
    Cinder::probes([new StubProbe(observation: Observation::degraded('Slow', 'It is slow'))]);

    $this->artisan('cinder:health --strict')->assertFailed();
})->group('commands');

it('fails in strict mode when a probe errors', function () {
    Cinder::probes([StubProbe::throwing()]);

    $this->artisan('cinder:health --strict')->assertFailed();
})->group('commands');

it('succeeds in strict mode when probes are healthy or not applicable', function () {
    Cinder::probes([
        new StubProbe(observation: Observation::healthy('Fine')),
    ]);
    Cinder::registerProbe(new AliasedProbe(new StubProbe(observation: Observation::notApplicable('n/a')), 'other'));

    $this->artisan('cinder:health --strict')->assertSuccessful();
})->group('commands');

it('stores the snapshot and records a scheduler heartbeat', function () {
    Cinder::probes([new StubProbe(observation: Observation::healthy('Fine'))]);

    $this->artisan('cinder:health')->assertSuccessful();

    expect(app(SnapshotRepository::class)->find()?->findings)->toHaveCount(1)
        ->and(app(SchedulerHeartbeat::class)->lastBeatAt())->toBeGreaterThanOrEqual(time() - 5);
})->group('commands');

it('does not record a heartbeat when disabled', function () {
    config()->set('cinder.health.scheduler_heartbeat.record', false);
    Cinder::probes([]);

    $this->artisan('cinder:health')->assertSuccessful();

    expect(app(SchedulerHeartbeat::class)->lastBeatAt())->toBeNull();
})->group('commands');

it('stores the snapshot under the configured node name', function () {
    config()->set('cinder.health.node.name', 'worker');
    Cinder::probes([new StubProbe(observation: Observation::healthy('Fine'))]);

    $this->artisan('cinder:health')->assertSuccessful();

    expect(app(SnapshotRepository::class)->find('worker'))->not->toBeNull();
})->group('commands');
