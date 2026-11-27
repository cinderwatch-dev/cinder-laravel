<?php

use Cinderwatch\Health\Finding;
use Cinderwatch\Health\HealthSnapshot;
use Cinderwatch\Health\Observation;
use Cinderwatch\Laravel\Facades\Cinder;
use Cinderwatch\Laravel\Health\Probes\DebugModeProbe;
use Cinderwatch\Laravel\Health\SnapshotRepository;

beforeEach(function () {
    config()->set('app.debug', true);
    config()->set('cinder.health.node.name', 'web');
    config()->set('cinder.health.node.label', 'Web');
    config()->set('cinder.health.peers.names', ['worker']);
    config()->set('cinder.health.peers.max_age_seconds', 180);

    Cinder::probes([app()->make(DebugModeProbe::class, ['expectEnabled' => true])]);
});

it('merges peer snapshots into nodes and combined check results', function () {
    app(SnapshotRepository::class)->put(HealthSnapshot::at(time(), [
        new Finding('horizon', 'Horizon', Observation::healthy('Running')),
        new Finding('debug-mode', 'Debug mode', Observation::healthy('Enabled')),
    ]), 'worker');

    $this->get('/cinder/v1/health', ['Authorization' => 'Bearer secret'])
        ->assertOk()
        ->assertJsonStructure([
            'generated_at',
            'condition',
            'probes' => [['id', 'title', 'condition', 'summary', 'detail', 'facts', 'node']],
            'nodes' => [['name', 'label', 'generated_at', 'stale', 'probes']],
        ])
        ->assertJsonPath('condition', 'healthy')
        ->assertJsonPath('nodes.0.name', 'web')
        ->assertJsonPath('nodes.0.label', 'Web')
        ->assertJsonPath('nodes.0.stale', false)
        ->assertJsonPath('nodes.1.name', 'worker')
        ->assertJsonPath('nodes.1.label', 'Worker')
        ->assertJsonPath('nodes.1.stale', false)
        ->assertJsonPath('nodes.1.probes.0.id', 'horizon')
        ->assertJsonMissingPath('nodes.1.probes.0.node')
        ->assertJsonPath('probes.0.id', 'debug-mode')
        ->assertJsonPath('probes.0.title', 'Debug mode (Web)')
        ->assertJsonPath('probes.0.node', 'web')
        ->assertJsonPath('probes.1.id', 'horizon')
        ->assertJsonPath('probes.1.title', 'Horizon')
        ->assertJsonPath('probes.1.node', 'worker')
        ->assertJsonPath('probes.2.title', 'Debug mode (Worker)');
})->group('health');

it('marks a peer that never stored a snapshot as stale and unhealthy', function () {
    $this->get('/cinder/v1/health', ['Authorization' => 'Bearer secret'])
        ->assertOk()
        ->assertJsonPath('condition', 'unhealthy')
        ->assertJsonPath('nodes.1.stale', true)
        ->assertJsonPath('nodes.1.probes.0.id', 'worker-snapshot')
        ->assertJsonPath('nodes.1.probes.0.condition', 'unhealthy')
        ->assertJsonPath('probes.1.id', 'worker-snapshot')
        ->assertJsonPath('probes.1.condition', 'unhealthy')
        ->assertJsonPath('probes.1.title', 'Worker report')
        ->assertJsonPath('probes.1.node', 'worker');
})->group('health');

it('marks a peer whose snapshot is too old as stale', function () {
    app(SnapshotRepository::class)->put(HealthSnapshot::at(time() - 600, [
        new Finding('horizon', 'Horizon', Observation::healthy('Running')),
    ]), 'worker');

    $this->get('/cinder/v1/health', ['Authorization' => 'Bearer secret'])
        ->assertOk()
        ->assertJsonPath('nodes.1.stale', true)
        ->assertJsonPath('nodes.1.probes.0.condition', 'unhealthy')
        ->assertJsonPath('nodes.1.probes.0.summary', 'Report missing');
})->group('health');

it('accepts peers as a comma separated string and ignores its own node', function () {
    config()->set('cinder.health.peers.names', 'web, worker');
    config()->set('cinder.health.peers.labels', ['worker' => 'Queue box']);

    $this->get('/cinder/v1/health', ['Authorization' => 'Bearer secret'])
        ->assertOk()
        ->assertJsonCount(2, 'nodes')
        ->assertJsonPath('nodes.1.label', 'Queue box');
})->group('health');

it('returns the single-server shape when no peers are configured', function () {
    config()->set('cinder.health.peers.names', []);

    $this->get('/cinder/v1/health', ['Authorization' => 'Bearer secret'])
        ->assertOk()
        ->assertJsonMissingPath('nodes')
        ->assertJsonMissingPath('probes.0.node')
        ->assertJsonStructure(['generated_at', 'condition', 'probes']);
})->group('health');

it('keeps one stored snapshot per node, overwriting the previous one', function () {
    $repository = app(SnapshotRepository::class);

    $repository->put(HealthSnapshot::at(100, [new Finding('a', 'A', Observation::healthy('first'))]), 'worker');
    $repository->put(HealthSnapshot::at(200, [new Finding('a', 'A', Observation::healthy('second'))]), 'worker');

    expect($repository->find('worker')?->takenAt->getTimestamp())->toBe(200)
        ->and(cache()->get('cinder:health:snapshot:worker'))->toBeString()
        ->and($repository->find('elsewhere'))->toBeNull();
})->group('health');
