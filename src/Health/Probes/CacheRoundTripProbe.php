<?php

namespace Cinderwatch\Laravel\Health\Probes;

use Cinderwatch\Health\Observation;
use Cinderwatch\Health\Probe;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Throwable;

class CacheRoundTripProbe implements Probe
{
    public function __construct(
        private readonly CacheFactory $caches,
        private readonly ?string $store = null,
    ) {}

    public function id(): string
    {
        return $this->store === null ? 'cache' : "cache-{$this->store}";
    }

    public function title(): string
    {
        return $this->store === null ? 'Cache' : "Cache ({$this->store})";
    }

    public function examine(): Observation
    {
        $storeName = $this->store ?? (string) config('cache.default');
        $facts = ['store' => $storeName];
        $key = 'cinder:probe:'.bin2hex(random_bytes(8));
        $token = bin2hex(random_bytes(8));

        try {
            $cache = $this->caches->store($this->store);
            $cache->put($key, $token, 10);
            $readBack = $cache->get($key);
            $cache->forget($key);
        } catch (Throwable $throwable) {
            return Observation::unhealthy('Unavailable', "Cache store `{$storeName}` threw: {$throwable->getMessage()}", $facts);
        }

        if ($readBack !== $token) {
            return Observation::unhealthy('Round trip failed', "A value written to cache store `{$storeName}` could not be read back.", $facts);
        }

        return Observation::healthy($storeName, $facts);
    }
}
