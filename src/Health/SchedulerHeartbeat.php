<?php

namespace Cinderwatch\Laravel\Health;

use Illuminate\Support\Carbon;

class SchedulerHeartbeat
{
    public function __construct(
        private readonly HealthSettings $settings,
    ) {}

    public function beat(?int $at = null): void
    {
        $this->settings->cache()->forever($this->settings->heartbeatKey(), $at ?? Carbon::now()->getTimestamp());
    }

    public function lastBeatAt(): ?int
    {
        $value = $this->settings->cache()->get($this->settings->heartbeatKey());

        return is_numeric($value) ? (int) $value : null;
    }
}
