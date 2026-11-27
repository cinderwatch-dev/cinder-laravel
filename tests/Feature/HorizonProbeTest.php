<?php

use Cinderwatch\Health\Condition;
use Cinderwatch\Laravel\Health\Probes\HorizonProbe;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;

require_once __DIR__.'/../Fixtures/Stubs/horizon.php';

/**
 * @param  array<int, mixed>|null  $masters
 */
function fakeHorizonSupervisors(?array $masters): void
{
    app()->instance(MasterSupervisorRepository::class, new class($masters) implements MasterSupervisorRepository
    {
        /**
         * @param  array<int, mixed>|null  $masters
         */
        public function __construct(private ?array $masters) {}

        public function all(): ?array
        {
            return $this->masters;
        }
    });
}

it('describes itself as horizon', function () {
    $probe = app(HorizonProbe::class);

    expect($probe->id())->toBe('horizon')
        ->and($probe->title())->toBe('Horizon');
})->group('health');

it('is healthy when a master supervisor is running', function () {
    fakeHorizonSupervisors([(object) ['status' => 'running']]);

    $observation = app(HorizonProbe::class)->examine();

    expect($observation->condition)->toBe(Condition::Healthy)
        ->and($observation->summary)->toBe('Running')
        ->and($observation->facts)->toBe(['supervisors' => 1, 'paused' => 0]);
})->group('health');

it('is unhealthy when no master supervisor is running', function (?array $masters) {
    fakeHorizonSupervisors($masters);

    expect(app(HorizonProbe::class)->examine()->condition)->toBe(Condition::Unhealthy);
})->with([
    'empty list' => [[]],
    'nothing stored' => [null],
])->group('health');

it('is degraded when a supervisor is paused', function () {
    fakeHorizonSupervisors([(object) ['status' => 'running'], (object) ['status' => 'paused'], 'corrupt']);

    $observation = app(HorizonProbe::class)->examine();

    expect($observation->condition)->toBe(Condition::Degraded)
        ->and($observation->detail)->toBe('1 of 3 Horizon supervisor(s) are paused.')
        ->and($observation->facts['paused'])->toBe(1);
})->group('health');

it('does not apply when horizon is not registered', function () {
    expect(app(HorizonProbe::class)->examine()->condition)->toBe(Condition::NotApplicable);
})->group('health');
