<?php

namespace Cinderwatch\Laravel\Health;

use Cinderwatch\Health\Cluster\ClusterSnapshot;
use Cinderwatch\Health\Cluster\NodeSnapshot;
use Cinderwatch\Health\Finding;
use Cinderwatch\Health\HealthSnapshot;
use Cinderwatch\Health\Observation;
use Illuminate\Support\Carbon;

/**
 * A peer with a missing or stale snapshot is reported as an unhealthy
 * `<peer>-snapshot` finding, so a dead worker shows up instead of vanishing.
 */
class ClusterAssembler
{
    public function __construct(
        private readonly HealthSettings $settings,
        private readonly SnapshotRepository $repository,
    ) {}

    public function assemble(HealthSnapshot $local): ClusterSnapshot
    {
        $peers = $this->settings->peerNames();

        if ($peers === []) {
            return new ClusterSnapshot($local, []);
        }

        $nodes = [new NodeSnapshot($this->settings->nodeName(), $this->settings->labelFor(), $local)];

        foreach ($peers as $peer) {
            $fresh = $this->freshSnapshotFor($peer);

            $nodes[] = new NodeSnapshot(
                $peer,
                $this->settings->labelFor($peer),
                $fresh ?? $this->missingSnapshotFor($peer),
                stale: $fresh === null,
            );
        }

        return ClusterSnapshot::merge($local, $nodes);
    }

    private function freshSnapshotFor(string $peer): ?HealthSnapshot
    {
        $snapshot = $this->repository->find($peer);

        if ($snapshot === null || $snapshot->ageInSeconds(Carbon::now()->toDateTimeImmutable()) > $this->settings->peerMaxAgeSeconds()) {
            return null;
        }

        return $snapshot;
    }

    private function missingSnapshotFor(string $peer): HealthSnapshot
    {
        $label = $this->settings->labelFor($peer);
        $window = $this->settings->peerMaxAgeSeconds();

        return new HealthSnapshot(Carbon::now()->toDateTimeImmutable(), [
            new Finding(
                "{$peer}-snapshot",
                "{$label} report",
                Observation::unhealthy(
                    'Report missing',
                    "The {$label} server has not stored a health snapshot within the last {$window} seconds.",
                    ['max_age_seconds' => $window],
                ),
            ),
        ]);
    }
}
