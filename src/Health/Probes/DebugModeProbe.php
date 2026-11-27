<?php

namespace Cinderwatch\Laravel\Health\Probes;

use Cinderwatch\Health\Observation;
use Cinderwatch\Health\Probe;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

class DebugModeProbe implements Probe
{
    public function __construct(
        private readonly ConfigRepository $config,
        private readonly bool $expectEnabled = false,
    ) {}

    public function id(): string
    {
        return 'debug-mode';
    }

    public function title(): string
    {
        return 'Debug mode';
    }

    public function examine(): Observation
    {
        $enabled = (bool) $this->config->get('app.debug');
        $summary = $enabled ? 'Enabled' : 'Disabled';
        $facts = ['enabled' => $enabled, 'expect_enabled' => $this->expectEnabled];

        if ($enabled !== $this->expectEnabled) {
            return Observation::unhealthy(
                $summary,
                $enabled ? 'APP_DEBUG is on but should be off here.' : 'APP_DEBUG is off but should be on here.',
                $facts,
            );
        }

        return Observation::healthy($summary, $facts);
    }
}
