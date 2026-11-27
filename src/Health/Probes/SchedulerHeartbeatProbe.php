<?php

namespace Cinderwatch\Laravel\Health\Probes;

use Cinderwatch\Health\Observation;
use Cinderwatch\Health\Probe;
use Cinderwatch\Laravel\Health\SchedulerHeartbeat;
use Illuminate\Support\Carbon;

class SchedulerHeartbeatProbe implements Probe
{
    public function __construct(
        private readonly SchedulerHeartbeat $heartbeat,
        private readonly int $maxSilenceMinutes = 2,
    ) {}

    public function id(): string
    {
        return 'scheduler';
    }

    public function title(): string
    {
        return 'Scheduler';
    }

    public function examine(): Observation
    {
        $lastBeatAt = $this->heartbeat->lastBeatAt();

        if ($lastBeatAt === null) {
            return Observation::unhealthy('No heartbeat', 'No scheduler heartbeat has been recorded yet. Schedule `cinder:health` to run every minute.');
        }

        $silentForMinutes = intdiv(max(0, Carbon::now()->getTimestamp() - $lastBeatAt), 60);
        $facts = ['last_beat_at' => $lastBeatAt, 'silent_for_minutes' => $silentForMinutes];

        if ($silentForMinutes > $this->maxSilenceMinutes) {
            return Observation::unhealthy(
                "Silent {$silentForMinutes}m",
                "The scheduler last ran {$silentForMinutes} minutes ago (allowed: {$this->maxSilenceMinutes}).",
                $facts,
            );
        }

        return Observation::healthy('Beating', $facts);
    }
}
