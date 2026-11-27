<?php

use Cinderwatch\Health\Condition;
use Cinderwatch\Health\Inspector;
use Cinderwatch\Health\Probes\DiskUsageProbe;
use Cinderwatch\Laravel\Health\ProbeFactory;
use Cinderwatch\Laravel\Health\Probes\CacheRoundTripProbe;
use Cinderwatch\Laravel\Health\Probes\DatabaseConnectionProbe;
use Cinderwatch\Laravel\Health\Probes\DebugModeProbe;
use Cinderwatch\Laravel\Health\Probes\EnvironmentProbe;
use Cinderwatch\Laravel\Health\Probes\OptimisationProbe;
use Cinderwatch\Laravel\Health\Probes\QueueConnectionProbe;
use Cinderwatch\Laravel\Health\Probes\SchedulerHeartbeatProbe;
use Cinderwatch\Laravel\Health\SchedulerHeartbeat;
use Illuminate\Foundation\Application;

it('names each probe after what it checks', function (string $class, array $with, string $id, string $title) {
    $probe = app()->make($class, $with);

    expect($probe->id())->toBe($id)
        ->and($probe->title())->toBe($title);
})->with([
    'default cache' => [CacheRoundTripProbe::class, [], 'cache', 'Cache'],
    'named cache' => [CacheRoundTripProbe::class, ['store' => 'redis'], 'cache-redis', 'Cache (redis)'],
    'default database' => [DatabaseConnectionProbe::class, [], 'database', 'Database'],
    'named database' => [DatabaseConnectionProbe::class, ['connection' => 'replica'], 'database-replica', 'Database (replica)'],
    'default queue' => [QueueConnectionProbe::class, [], 'queue', 'Queue'],
    'named queue' => [QueueConnectionProbe::class, ['connection' => 'redis'], 'queue-redis', 'Queue (redis)'],
    'debug mode' => [DebugModeProbe::class, [], 'debug-mode', 'Debug mode'],
    'environment' => [EnvironmentProbe::class, [], 'environment', 'Environment'],
    'optimisation' => [OptimisationProbe::class, [], 'optimisation', 'Bootstrap caches'],
    'scheduler' => [SchedulerHeartbeatProbe::class, [], 'scheduler', 'Scheduler'],
])->group('health');

it('round-trips a value through the cache store', function () {
    $observation = app(CacheRoundTripProbe::class)->examine();

    expect($observation->condition)->toBe(Condition::Healthy)
        ->and($observation->facts['store'])->toBe('array');
})->group('health');

it('is unhealthy when the cache store cannot be used', function () {
    $observation = app()->make(CacheRoundTripProbe::class, ['store' => 'missing-store'])->examine();

    expect($observation->condition)->toBe(Condition::Unhealthy)
        ->and($observation->summary)->toBe('Unavailable');
})->group('health');

it('is unhealthy when a cached value cannot be read back', function () {
    config(['cache.stores.void' => ['driver' => 'null']]);

    $observation = app()->make(CacheRoundTripProbe::class, ['store' => 'void'])->examine();

    expect($observation->condition)->toBe(Condition::Unhealthy)
        ->and($observation->summary)->toBe('Round trip failed');
})->group('health');

it('connects to the default database and fails for an unknown connection', function () {
    expect(app(DatabaseConnectionProbe::class)->examine()->condition)->toBe(Condition::Healthy)
        ->and(app()->make(DatabaseConnectionProbe::class, ['connection' => 'does-not-exist'])->examine()->condition)->toBe(Condition::Unhealthy);
})->group('health');

it('reaches the default queue connection', function () {
    $observation = app(QueueConnectionProbe::class)->examine();

    expect($observation->condition)->toBe(Condition::Healthy)
        ->and($observation->facts)->toBe(['connection' => 'sync', 'pending_jobs' => 0]);
})->group('health');

it('is unhealthy when the queue connection is not configured', function () {
    $observation = app()->make(QueueConnectionProbe::class, ['connection' => 'missing'])->examine();

    expect($observation->condition)->toBe(Condition::Unhealthy)
        ->and($observation->facts)->toBe(['connection' => 'missing']);
})->group('health');

it('compares debug mode with the expectation', function () {
    config()->set('app.debug', true);

    expect(app(DebugModeProbe::class)->examine()->condition)->toBe(Condition::Unhealthy)
        ->and(app()->make(DebugModeProbe::class, ['expectEnabled' => true])->examine()->condition)->toBe(Condition::Healthy);
})->group('health');

it('flags debug mode that is off when it should be on', function () {
    config()->set('app.debug', false);

    expect(app()->make(DebugModeProbe::class, ['expectEnabled' => true])->examine()->detail)
        ->toBe('APP_DEBUG is off but should be on here.');
})->group('health');

it('compares the running environment with the expectation', function () {
    expect(app(EnvironmentProbe::class)->examine()->condition)->toBe(Condition::Unhealthy)
        ->and(app()->make(EnvironmentProbe::class, ['expected' => 'testing'])->examine()->condition)->toBe(Condition::Healthy);
})->group('health');

it('degrades when config and routes are not cached', function () {
    $observation = app(OptimisationProbe::class)->examine();

    expect($observation->condition)->toBe(Condition::Degraded)
        ->and($observation->summary)->toBe('Not cached: config, routes')
        ->and($observation->facts)->toHaveKeys(['config_cached', 'routes_cached', 'events_cached']);
})->group('health');

it('is healthy once config and routes are cached', function () {
    $app = Mockery::mock(Application::class);
    $app->allows('configurationIsCached')->andReturnTrue();
    $app->allows('routesAreCached')->andReturnTrue();
    $app->allows('eventsAreCached')->andReturnFalse();

    $observation = (new OptimisationProbe($app))->examine();

    expect($observation->condition)->toBe(Condition::Healthy)
        ->and($observation->facts)->toBe(['config_cached' => true, 'routes_cached' => true, 'events_cached' => false]);
})->group('health');

it('judges the scheduler by its heartbeat', function (?int $secondsAgo, Condition $expected) {
    if ($secondsAgo !== null) {
        app(SchedulerHeartbeat::class)->beat(time() - $secondsAgo);
    }

    expect(app()->make(SchedulerHeartbeatProbe::class, ['maxSilenceMinutes' => 2])->examine()->condition)->toBe($expected);
})->with([
    'never beat' => [null, Condition::Unhealthy],
    'just beat' => [10, Condition::Healthy],
    'within allowance' => [150, Condition::Healthy],
    'silent too long' => [600, Condition::Unhealthy],
])->group('health');

it('builds probes from every supported config entry form', function () {
    $probes = app(ProbeFactory::class)->fromDefinitions([
        CacheRoundTripProbe::class,
        DebugModeProbe::class => ['expectEnabled' => true],
        EnvironmentProbe::class => false,
        OptimisationProbe::class => null,
        DiskUsageProbe::class => ['path' => '/', 'warnAbovePercent' => 80, 'failAbovePercent' => 95],
        ['probe' => DatabaseConnectionProbe::class, 'with' => ['connection' => 'testing'], 'id' => 'primary-db', 'title' => 'Primary DB'],
        ['probe' => QueueConnectionProbe::class],
    ]);

    expect(array_map(fn ($probe) => $probe->id(), $probes))->toBe(['cache', 'debug-mode', 'disk-usage', 'primary-db', 'queue'])
        ->and($probes[3]->title())->toBe('Primary DB');
})->group('health');

it('rejects config entries that are not probes', function (array $definitions) {
    app(ProbeFactory::class)->fromDefinitions($definitions);
})->with([
    'not a probe class' => [[stdClass::class]],
    'no probe key' => [[['with' => []]]],
    'not a class name' => [[42]],
])->throws(InvalidArgumentException::class)->group('health');

it('registers configured probes on the inspector', function () {
    config()->set('cinder.health.probes', [DebugModeProbe::class, CacheRoundTripProbe::class]);
    app()->forgetInstance(Inspector::class);

    expect(array_map(fn ($probe) => $probe->id(), app(Inspector::class)->probes()))->toBe(['debug-mode', 'cache']);
})->group('health');
