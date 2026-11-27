<?php

namespace Cinderwatch\Laravel\Commands;

use Cinderwatch\Laravel\ScheduleMonitor\ScheduleMonitor;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Throwable;

class ScheduleSyncCommand extends Command
{
    protected $signature = 'cinder:schedule-sync';

    protected $description = 'Replace the scheduled tasks Cinder monitors with this application\'s schedule';

    public function handle(Schedule $schedule, ScheduleMonitor $monitor): int
    {
        if (! $monitor->enabled()) {
            $this->warn('Cinder schedule monitoring is not configured.');

            return self::FAILURE;
        }

        try {
            $registered = $monitor->sync($schedule);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Synced %d scheduled tasks with Cinder.', count($registered)));

        return self::SUCCESS;
    }
}
