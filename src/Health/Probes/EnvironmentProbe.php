<?php

namespace Cinderwatch\Laravel\Health\Probes;

use Cinderwatch\Health\Observation;
use Cinderwatch\Health\Probe;
use Illuminate\Contracts\Foundation\Application;

class EnvironmentProbe implements Probe
{
    public function __construct(
        private readonly Application $app,
        private readonly string $expected = 'production',
    ) {}

    public function id(): string
    {
        return 'environment';
    }

    public function title(): string
    {
        return 'Environment';
    }

    public function examine(): Observation
    {
        $running = (string) $this->app->environment();
        $facts = ['running' => $running, 'expected' => $this->expected];

        if ($running !== $this->expected) {
            return Observation::unhealthy($running, "Running as `{$running}` instead of `{$this->expected}`.", $facts);
        }

        return Observation::healthy($running, $facts);
    }
}
