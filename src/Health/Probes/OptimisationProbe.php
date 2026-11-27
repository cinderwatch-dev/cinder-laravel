<?php

namespace Cinderwatch\Laravel\Health\Probes;

use Cinderwatch\Health\Observation;
use Cinderwatch\Health\Probe;
use Illuminate\Foundation\Application;

/**
 * Config and routes must be cached; the events cache is only reported.
 */
class OptimisationProbe implements Probe
{
    public function __construct(
        private readonly Application $app,
    ) {}

    public function id(): string
    {
        return 'optimisation';
    }

    public function title(): string
    {
        return 'Bootstrap caches';
    }

    public function examine(): Observation
    {
        $cached = [
            'config' => $this->app->configurationIsCached(),
            'routes' => $this->app->routesAreCached(),
            'events' => $this->app->eventsAreCached(),
        ];
        $facts = [
            'config_cached' => $cached['config'],
            'routes_cached' => $cached['routes'],
            'events_cached' => $cached['events'],
        ];

        $missing = array_keys(array_filter(
            ['config' => ! $cached['config'], 'routes' => ! $cached['routes']],
        ));

        if ($missing !== []) {
            return Observation::degraded(
                'Not cached: '.implode(', ', $missing),
                'Run `php artisan optimize` during deployment; '.implode(' and ', $missing).' are not cached.',
                $facts,
            );
        }

        return Observation::healthy('Cached', $facts);
    }
}
