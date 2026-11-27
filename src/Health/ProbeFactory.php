<?php

namespace Cinderwatch\Laravel\Health;

use Cinderwatch\Health\AliasedProbe;
use Cinderwatch\Health\Probe;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Probes are resolved through the container, so framework services are
 * injected and the configured values fill the named constructor parameters.
 */
class ProbeFactory
{
    public function __construct(
        private readonly Container $container,
    ) {}

    /**
     * @param  array<array-key, mixed>  $definitions
     * @return list<Probe>
     */
    public function fromDefinitions(array $definitions): array
    {
        $probes = [];

        foreach ($definitions as $key => $value) {
            $probe = is_string($key)
                ? $this->fromKeyedEntry($key, $value)
                : $this->fromListEntry($value);

            if ($probe !== null) {
                $probes[] = $probe;
            }
        }

        return $probes;
    }

    private function fromKeyedEntry(string $class, mixed $value): ?Probe
    {
        if ($value === false || $value === null) {
            return null;
        }

        return $this->build($class, is_array($value) ? $value : []);
    }

    private function fromListEntry(mixed $value): Probe
    {
        if (is_string($value)) {
            return $this->build($value, []);
        }

        if (is_array($value) && is_string($value['probe'] ?? null)) {
            $probe = $this->build($value['probe'], is_array($value['with'] ?? null) ? $value['with'] : []);

            if (is_string($value['id'] ?? null) && $value['id'] !== '') {
                $probe = new AliasedProbe($probe, $value['id'], is_string($value['title'] ?? null) ? $value['title'] : null);
            }

            return $probe;
        }

        throw new InvalidArgumentException('Each cinder.health.probes entry must be a probe class name or an array with a "probe" key.');
    }

    /**
     * @param  array<array-key, mixed>  $arguments
     */
    private function build(string $class, array $arguments): Probe
    {
        if (! is_a($class, Probe::class, true)) {
            throw new InvalidArgumentException("[{$class}] does not implement ".Probe::class.'.');
        }

        /** @var array<string, mixed> $arguments */
        return $this->container->make($class, $arguments);
    }
}
