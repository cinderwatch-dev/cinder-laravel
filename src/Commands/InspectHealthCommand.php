<?php

namespace Cinderwatch\Laravel\Commands;

use Cinderwatch\Health\Condition;
use Cinderwatch\Health\Finding;
use Cinderwatch\Health\Inspector;
use Cinderwatch\Laravel\Health\HealthSettings;
use Cinderwatch\Laravel\Health\SchedulerHeartbeat;
use Cinderwatch\Laravel\Health\SnapshotRepository;
use Illuminate\Console\Command;

class InspectHealthCommand extends Command
{
    protected $signature = 'cinder:health
        {--strict : Exit non-zero when any probe is degraded, unhealthy or errored}';

    protected $description = 'Run the Cinder health probes and store the snapshot for this node';

    public function handle(
        Inspector $inspector,
        SnapshotRepository $repository,
        SchedulerHeartbeat $heartbeat,
        HealthSettings $settings,
    ): int {
        if ($settings->recordsSchedulerHeartbeat()) {
            $heartbeat->beat();
        }

        $snapshot = $inspector->inspect();
        $repository->put($snapshot);

        if ($snapshot->findings === []) {
            $this->components->warn('No health probes are registered. Add some to cinder.health.probes.');
        } else {
            $this->table(
                ['Probe', 'Condition', 'Summary'],
                array_map(fn (Finding $finding): array => [
                    $finding->probeTitle,
                    self::decorate($finding->condition()),
                    $finding->observation->summary,
                ], $snapshot->findings),
            );
        }

        $this->line(sprintf('Snapshot stored for node <comment>%s</comment>.', $settings->nodeName()));

        return $this->option('strict') && $snapshot->needsAttention() ? self::FAILURE : self::SUCCESS;
    }

    private static function decorate(Condition $condition): string
    {
        $colour = match ($condition) {
            Condition::Healthy => 'green',
            Condition::Degraded, Condition::NotApplicable => 'yellow',
            Condition::Unhealthy, Condition::Errored => 'red',
        };

        return "<fg={$colour}>{$condition->describe()}</>";
    }
}
