<?php

namespace Cinderwatch\Laravel\Health;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Cache;

class HealthSettings
{
    private const DEFAULT_NODE = 'default';

    public function __construct(
        private readonly ConfigRepository $config,
    ) {}

    public function endpointEnabled(): bool
    {
        return (bool) $this->config->get('cinder.health.endpoint.enabled', false);
    }

    public function endpointPath(): string
    {
        return (string) $this->config->get('cinder.health.endpoint.path', '/cinder/v1/health');
    }

    public function endpointSecret(): string
    {
        return (string) $this->config->get('cinder.health.endpoint.secret', '');
    }

    public function inspectsOnRequest(): bool
    {
        return (bool) $this->config->get('cinder.health.endpoint.inspect_on_request', true);
    }

    /**
     * @return array<array-key, mixed>
     */
    public function probeDefinitions(): array
    {
        $definitions = $this->config->get('cinder.health.probes', []);

        return is_array($definitions) ? $definitions : [];
    }

    public function cache(): CacheRepository
    {
        $store = $this->config->get('cinder.health.storage.cache_store');

        return Cache::store(is_string($store) && $store !== '' ? $store : null);
    }

    public function snapshotKey(?string $node = null): string
    {
        return $this->keyPrefix().':snapshot:'.($node ?? $this->nodeName());
    }

    public function heartbeatKey(): string
    {
        return $this->keyPrefix().':scheduler-heartbeat';
    }

    private function keyPrefix(): string
    {
        $prefix = $this->config->get('cinder.health.storage.key_prefix');

        return is_string($prefix) && $prefix !== '' ? $prefix : 'cinder:health';
    }

    public function recordsSchedulerHeartbeat(): bool
    {
        return (bool) $this->config->get('cinder.health.scheduler_heartbeat.record', true);
    }

    public function nodeName(): string
    {
        $name = $this->config->get('cinder.health.node.name');

        return is_string($name) && $name !== '' ? $name : self::DEFAULT_NODE;
    }

    public function labelFor(?string $node = null): string
    {
        $node ??= $this->nodeName();

        if ($node === $this->nodeName()) {
            $ownLabel = $this->config->get('cinder.health.node.label');

            if (is_string($ownLabel) && $ownLabel !== '') {
                return $ownLabel;
            }
        }

        $labels = $this->config->get('cinder.health.peers.labels', []);

        if (is_array($labels) && is_string($labels[$node] ?? null) && $labels[$node] !== '') {
            return $labels[$node];
        }

        return $node === self::DEFAULT_NODE ? 'Application' : ucfirst($node);
    }

    /**
     * @return list<string>
     */
    public function peerNames(): array
    {
        $peers = $this->config->get('cinder.health.peers.names', []);

        if (is_string($peers)) {
            $peers = explode(',', $peers);
        }

        if (! is_array($peers)) {
            return [];
        }

        $own = $this->nodeName();
        $names = [];

        foreach ($peers as $peer) {
            if (is_string($peer) && ($peer = trim($peer)) !== '' && $peer !== $own) {
                $names[$peer] = $peer;
            }
        }

        return array_values($names);
    }

    public function peerMaxAgeSeconds(): int
    {
        return max(30, (int) $this->config->get('cinder.health.peers.max_age_seconds', 180));
    }
}
