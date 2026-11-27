<?php

namespace Cinderwatch\Laravel\Health\Probes;

use Cinderwatch\Health\Observation;
use Cinderwatch\Health\Probe;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Throwable;

class QueueConnectionProbe implements Probe
{
    public function __construct(
        private readonly QueueFactory $queues,
        private readonly ?string $connection = null,
        private readonly ?string $queue = null,
    ) {}

    public function id(): string
    {
        return $this->connection === null ? 'queue' : "queue-{$this->connection}";
    }

    public function title(): string
    {
        return $this->connection === null ? 'Queue' : "Queue ({$this->connection})";
    }

    public function examine(): Observation
    {
        $connectionName = $this->connection ?? (string) config('queue.default');
        $facts = ['connection' => $connectionName];

        try {
            $pending = $this->queues->connection($this->connection)->size($this->queue);
        } catch (Throwable $throwable) {
            return Observation::unhealthy('Unreachable', "Queue connection `{$connectionName}` threw: {$throwable->getMessage()}", $facts);
        }

        return Observation::healthy($connectionName, [...$facts, 'pending_jobs' => $pending]);
    }
}
