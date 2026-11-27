<?php

namespace Cinderwatch\Laravel\Health;

use Cinderwatch\Health\HealthSnapshot;
use Cinderwatch\Health\Wire\SnapshotSerializer;

class SnapshotRepository
{
    public function __construct(
        private readonly HealthSettings $settings,
        private readonly SnapshotSerializer $serializer,
    ) {}

    public function put(HealthSnapshot $snapshot, ?string $node = null): void
    {
        $this->settings->cache()->forever(
            $this->settings->snapshotKey($node),
            $this->serializer->encode($snapshot),
        );
    }

    public function find(?string $node = null): ?HealthSnapshot
    {
        return $this->serializer->decode(
            $this->settings->cache()->get($this->settings->snapshotKey($node)),
        );
    }
}
