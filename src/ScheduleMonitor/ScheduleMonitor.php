<?php

namespace Cinderwatch\Laravel\ScheduleMonitor;

use Cinderwatch\ScheduleMonitor\HeartbeatEvent;
use Cinderwatch\ScheduleMonitor\ScheduleClient;
use Cinderwatch\ScheduleMonitor\ScheduledTask;
use DateTimeZone;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Cache;

/**
 * The only state is one cache entry mapping task keys to the ids Cinder
 * returned on the last sync.
 */
class ScheduleMonitor
{
    private const TASK_IDS_CACHE_KEY = 'cinder:schedule-monitor:task-ids';

    /** Upper bound on remembered tasks, so a runaway schedule cannot bloat the cache. */
    private const MAX_TRACKED_TASKS = 500;

    private const DEFAULT_GRACE_MINUTES = 5;

    private const MAX_ERROR_LENGTH = 1000;

    public function __construct(
        private readonly ?ScheduleClient $client = null,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('cinder.schedule_monitor.enabled')
            && filled(config('cinder.schedule_monitor.api_token'));
    }

    public function client(): ScheduleClient
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $apiUrl = config('cinder.schedule_monitor.api_url')
            ?: rtrim((string) config('cinder.base_url'), '/').'/api/v1';

        return new ScheduleClient(
            apiToken: (string) config('cinder.schedule_monitor.api_token'),
            apiBaseUrl: (string) $apiUrl,
        );
    }

    public function graceMinutes(): int
    {
        $configured = config('cinder.schedule_monitor.grace_minutes') ?? self::DEFAULT_GRACE_MINUTES;

        return max(0, (int) $configured);
    }

    /**
     * @return list<array{key: string, id: string, heartbeat_url: string}>
     */
    public function sync(Schedule $schedule): array
    {
        $tasks = [];

        foreach (array_slice($schedule->events(), 0, self::MAX_TRACKED_TASKS) as $event) {
            $task = $this->describe($event);
            $tasks[$task->key] = $task;
        }

        $registered = $this->client()->sync(array_values($tasks));
        $taskIds = [];

        foreach ($registered as $task) {
            $taskIds[$task['key']] = $task['id'];
        }

        $this->cache()->forever(self::TASK_IDS_CACHE_KEY, array_slice($taskIds, 0, self::MAX_TRACKED_TASKS, true));

        return $registered;
    }

    public function handleStarting(ScheduledTaskStarting $event): void
    {
        $this->heartbeat($event->task, HeartbeatEvent::Started);
    }

    public function handleFinished(ScheduledTaskFinished $event): void
    {
        $exitCode = $event->task->exitCode;

        // A non-zero exit is followed by ScheduledTaskFailed, which reports it.
        if ($exitCode !== null && $exitCode !== 0 && ! $event->task->runInBackground) {
            return;
        }

        $this->heartbeat($event->task, HeartbeatEvent::Succeeded, [
            'duration_ms' => (int) round($event->runtime * 1000),
            'memory_bytes' => memory_get_peak_usage(true),
            'exit_code' => $exitCode ?? 0,
        ]);
    }

    public function handleFailed(ScheduledTaskFailed $event): void
    {
        $runtime = $event->runtime ?? null;
        $exitCode = $event->task->exitCode;

        $this->heartbeat($event->task, HeartbeatEvent::Failed, [
            'duration_ms' => is_numeric($runtime) ? (int) round((float) $runtime * 1000) : null,
            'memory_bytes' => memory_get_peak_usage(true),
            'exit_code' => $exitCode !== null && $exitCode !== 0 ? $exitCode : 1,
            'error' => mb_substr($event->exception->getMessage(), 0, self::MAX_ERROR_LENGTH),
        ]);
    }

    private function describe(Event $event): ScheduledTask
    {
        $timezone = $event->timezone ?? config('app.timezone', 'UTC');

        return ScheduledTask::cron(
            key: $this->taskKey($event),
            expression: $event->expression,
            timezone: $timezone instanceof DateTimeZone ? $timezone->getName() : (string) $timezone,
            graceMinutes: $this->graceMinutes(),
            summary: $this->summary($event),
        );
    }

    /**
     * @param  array{duration_ms?: int|null, memory_bytes?: int|null, exit_code?: int|null, error?: string|null}  $metrics
     */
    private function heartbeat(Event $event, HeartbeatEvent $type, array $metrics = []): void
    {
        if (! $this->enabled()) {
            return;
        }

        $taskIds = $this->cache()->get(self::TASK_IDS_CACHE_KEY);
        $taskId = is_array($taskIds) ? ($taskIds[$this->taskKey($event)] ?? null) : null;

        if (is_string($taskId) && $taskId !== '') {
            $this->client()->heartbeat($taskId, $type, $metrics);
        }
    }

    /**
     * A stable key for a scheduled event: the command with the PHP binary and
     * `artisan` stripped, else its description (job class for `$schedule->job()`),
     * else a hash of the closure's schedule.
     */
    private function taskKey(Event $event): string
    {
        if (is_string($event->command) && $event->command !== '') {
            $command = str_replace([PHP_BINARY, "'artisan'", '"artisan"', 'artisan'], '', $event->command);

            return trim((string) preg_replace('/\s+/', ' ', str_replace(["''", '""'], '', $command)));
        }

        if (is_string($event->description) && $event->description !== '') {
            return $event->description;
        }

        return 'closure:'.sha1($event->expression.'|'.$event->mutexName());
    }

    /**
     * The event's own description, else the description of the Artisan command it runs.
     */
    private function summary(Event $event): ?string
    {
        if (is_string($event->description) && $event->description !== '' && $event->description !== $this->taskKey($event)) {
            return $event->description;
        }

        if (! is_string($event->command) || $event->command === '') {
            return null;
        }

        $name = strtok($this->taskKey($event), ' ');

        $command = is_string($name) ? (app(ConsoleKernel::class)->all()[$name] ?? null) : null;

        $description = $command?->getDescription();

        return is_string($description) && $description !== '' ? $description : null;
    }

    private function cache(): CacheRepository
    {
        return Cache::store();
    }
}
