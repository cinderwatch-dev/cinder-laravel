<?php

namespace Cinderwatch\Laravel\Health\Probes;

use Cinderwatch\Health\Observation;
use Cinderwatch\Health\Probe;
use Illuminate\Contracts\Container\Container;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;

class HorizonProbe implements Probe
{
    public function __construct(
        private readonly Container $container,
    ) {}

    public function id(): string
    {
        return 'horizon';
    }

    public function title(): string
    {
        return 'Horizon';
    }

    public function examine(): Observation
    {
        if (! interface_exists(MasterSupervisorRepository::class) || ! $this->container->bound(MasterSupervisorRepository::class)) {
            return Observation::notApplicable('laravel/horizon is not installed.');
        }

        /** @var MasterSupervisorRepository $supervisors */
        $supervisors = $this->container->make(MasterSupervisorRepository::class);
        $masters = (array) ($supervisors->all() ?? []);

        if ($masters === []) {
            return Observation::unhealthy('Not running', 'No Horizon master supervisor is running.');
        }

        $statuses = array_map(fn (mixed $master): string => is_object($master) ? (string) ($master->status ?? 'unknown') : 'unknown', $masters);
        $paused = count(array_filter($statuses, fn (string $status): bool => $status === 'paused'));
        $facts = ['supervisors' => count($masters), 'paused' => $paused];

        if ($paused > 0) {
            return Observation::degraded('Paused', "{$paused} of ".count($masters).' Horizon supervisor(s) are paused.', $facts);
        }

        return Observation::healthy('Running', $facts);
    }
}
