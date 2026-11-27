<?php

use Cinderwatch\Health\Finding;
use Cinderwatch\Health\HealthSnapshot;
use Cinderwatch\Health\Observation;
use Cinderwatch\Laravel\Facades\Cinder;
use Cinderwatch\Laravel\Health\ProbeFactory;
use Cinderwatch\Laravel\Health\Probes\DebugModeProbe;
use Cinderwatch\Laravel\Health\SnapshotRepository;
use Cinderwatch\Laravel\Tests\Fixtures\StubProbe;
use Illuminate\Support\Carbon;

it('serves the exact wire payload the Cinder server consumes', function () {
    Carbon::setTestNow('2024-01-01 00:00:00');

    Cinder::probes([
        new StubProbe('debug-mode', 'Debug mode', Observation::healthy('Disabled', ['enabled' => false])),
        new StubProbe('queue', 'Queue', Observation::degraded('Backlog', '500 jobs waiting', ['pending_jobs' => 500])),
        new StubProbe('horizon', 'Horizon', Observation::notApplicable('laravel/horizon is not installed.')),
    ]);

    $response = $this->get('/cinder/v1/health', ['Authorization' => 'Bearer secret']);

    $response->assertOk()->assertHeader('Content-Type', 'application/json');

    expect($response->getContent())->toBe(json_encode([
        'generated_at' => '2024-01-01T00:00:00.000000Z',
        'condition' => 'degraded',
        'probes' => [
            [
                'id' => 'debug-mode',
                'title' => 'Debug mode',
                'condition' => 'healthy',
                'summary' => 'Disabled',
                'detail' => '',
                'facts' => ['enabled' => false],
            ],
            [
                'id' => 'queue',
                'title' => 'Queue',
                'condition' => 'degraded',
                'summary' => 'Backlog',
                'detail' => '500 jobs waiting',
                'facts' => ['pending_jobs' => 500],
            ],
            [
                'id' => 'horizon',
                'title' => 'Horizon',
                'condition' => 'not_applicable',
                'summary' => 'Not applicable',
                'detail' => 'laravel/horizon is not installed.',
                'facts' => new stdClass,
            ],
        ],
    ]));
})->after(fn () => Carbon::setTestNow())->group('health');

it('reports a throwing probe as errored on the wire', function () {
    Cinder::probes([StubProbe::throwing()]);

    $this->get('/cinder/v1/health', ['Authorization' => 'Bearer secret'])
        ->assertOk()
        ->assertJsonPath('condition', 'errored')
        ->assertJsonPath('probes.0.condition', 'errored')
        ->assertJsonPath('probes.0.detail', 'Boom');
})->group('health');

it('runs probes registered through config', function () {
    config()->set('app.debug', true);
    Cinder::probes(app(ProbeFactory::class)->fromDefinitions([
        DebugModeProbe::class => ['expectEnabled' => true],
    ]));

    $this->get('/cinder/v1/health', ['Authorization' => 'Bearer secret'])
        ->assertOk()
        ->assertJsonPath('probes.0.id', 'debug-mode')
        ->assertJsonPath('probes.0.condition', 'healthy');
})->group('health');

it('returns 404 unless the secret is sent as a bearer token', function (array $headers) {
    $this->get('/cinder/v1/health', $headers)->assertNotFound();
})->with([
    'no header' => [[]],
    'wrong token' => [['Authorization' => 'Bearer nope']],
    'not a bearer token' => [['Authorization' => 'secret']],
])->group('health');

it('returns 404 when no secret is configured even if the token is empty', function () {
    config()->set('cinder.health.endpoint.secret', '');

    $this->get('/cinder/v1/health', ['Authorization' => 'Bearer '])->assertNotFound();
})->group('health');

it('stores the snapshot it served for this node', function () {
    Cinder::probes([new StubProbe('a', 'A', Observation::healthy('fine'))]);

    $this->get('/cinder/v1/health', ['Authorization' => 'Bearer secret'])->assertOk();

    expect(app(SnapshotRepository::class)->find()?->findings[0]->probeId)->toBe('a');
})->group('health');

it('serves the stored snapshot when inspecting on request is disabled', function () {
    config()->set('cinder.health.endpoint.inspect_on_request', false);
    Cinder::probes([new StubProbe('live', 'Live', Observation::healthy('live'))]);

    app(SnapshotRepository::class)->put(HealthSnapshot::at(1700000000, [
        new Finding('stored', 'Stored', Observation::healthy('from cache')),
    ]));

    $this->get('/cinder/v1/health', ['Authorization' => 'Bearer secret'])
        ->assertOk()
        ->assertJsonPath('generated_at', '2023-11-14T22:13:20.000000Z')
        ->assertJsonPath('probes.0.id', 'stored');
})->group('health');

it('inspects live when inspecting on request is disabled but nothing is stored yet', function () {
    config()->set('cinder.health.endpoint.inspect_on_request', false);
    Cinder::probes([new StubProbe('live', 'Live', Observation::healthy('live'))]);

    $this->get('/cinder/v1/health', ['Authorization' => 'Bearer secret'])
        ->assertOk()
        ->assertJsonPath('probes.0.id', 'live');
})->group('health');

it('does not register the endpoint when it is disabled', function () {
    $this->rebootWith(['cinder.health.endpoint.enabled' => false]);

    $this->get('/cinder/v1/health', ['Authorization' => 'Bearer secret'])->assertNotFound();
})->group('health');

it('serves the endpoint on the configured path', function () {
    $this->rebootWith(['cinder.health.endpoint.path' => '/up/cinder']);

    $this->get('/up/cinder', ['Authorization' => 'Bearer secret'])->assertOk();
})->group('health');
