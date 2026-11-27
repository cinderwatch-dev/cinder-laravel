<?php

namespace Cinderwatch\Laravel\Health\Probes;

use Cinderwatch\Health\Observation;
use Cinderwatch\Health\Probe;
use Illuminate\Database\DatabaseManager;
use Throwable;

class DatabaseConnectionProbe implements Probe
{
    public function __construct(
        private readonly DatabaseManager $databases,
        private readonly ?string $connection = null,
    ) {}

    public function id(): string
    {
        return $this->connection === null ? 'database' : "database-{$this->connection}";
    }

    public function title(): string
    {
        return $this->connection === null ? 'Database' : "Database ({$this->connection})";
    }

    public function examine(): Observation
    {
        $connection = $this->connection ?? $this->databases->getDefaultConnection();
        $facts = ['connection' => $connection];

        try {
            $this->databases->connection($connection)->getPdo();
        } catch (Throwable $throwable) {
            return Observation::unhealthy('Unreachable', "Connecting to `{$connection}` failed: {$throwable->getMessage()}", $facts);
        }

        return Observation::healthy($connection, $facts);
    }
}
