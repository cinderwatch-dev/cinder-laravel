<?php

use Cinderwatch\Laravel\ScheduleMonitor\ScheduleMonitor;
use Cinderwatch\Laravel\Tests\Fixtures\FakeHttpClient;
use Cinderwatch\ScheduleMonitor\ScheduleClient;
use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;

const SYNCED_TASK_URL = 'https://cinder.test/api/v1/heartbeats/9b2f6c1e-5d4a-4f3b-8e7d-2a1c0b9f8e7d';

function scheduleMonitorWith(FakeHttpClient $http): ScheduleMonitor
{
    Artisan::command('inspire', fn () => null)->purpose('Display an inspiring quote');

    config([
        'cinder.schedule_monitor.enabled' => true,
        'cinder.schedule_monitor.api_token' => 'token',
        'cinder.schedule_monitor.grace_minutes' => 5,
    ]);

    $factory = new HttpFactory;

    return new ScheduleMonitor(new ScheduleClient('token', 'https://cinder.test/api/v1', $http, $factory, $factory));
}

function syncedMonitor(FakeHttpClient $http, Schedule $schedule): ScheduleMonitor
{
    $http->addResponse(200, (string) file_get_contents(__DIR__.'/../Fixtures/schedule-sync-response.json'));

    $monitor = scheduleMonitorWith($http);
    $monitor->sync($schedule);

    return $monitor;
}

/**
 * @return array{0: string, 1: array<string, mixed>}
 */
function lastHeartbeat(FakeHttpClient $http): array
{
    $request = $http->lastRequest();

    return [(string) $request->getUri(), json_decode((string) $request->getBody(), true)];
}

it('syncs scheduled commands using the schedule payload', function () {
    $http = new FakeHttpClient;
    $schedule = new Schedule('UTC');
    $schedule->command('inspire')->everyMinute();

    $http->addResponse(200, (string) file_get_contents(__DIR__.'/../Fixtures/schedule-sync-response.json'));

    $registered = scheduleMonitorWith($http)->sync($schedule);

    $fixture = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/schedule-sync-request.json'), true);
    $request = $http->lastRequest();

    expect($request->getMethod())->toBe('PUT')
        ->and((string) $request->getUri())->toBe('https://cinder.test/api/v1/schedule')
        ->and($request->getHeaderLine('Authorization'))->toBe('Bearer token')
        ->and(json_decode((string) $request->getBody(), true))->toBe($fixture)
        ->and($registered)->toBe([['key' => 'inspire', 'id' => '9b2f6c1e-5d4a-4f3b-8e7d-2a1c0b9f8e7d', 'heartbeat_url' => SYNCED_TASK_URL]]);
})->group('schedule-monitor');

it('keys jobs and closures by their description and uses the event description as summary', function () {
    $http = new FakeHttpClient;
    $http->addResponse(200, '{"tasks":[]}');

    $schedule = new Schedule('Europe/London');
    $schedule->command('inspire')->hourly()->description('Morning wisdom')->timezone('Europe/London');
    $schedule->call(fn () => null)->daily()->description('Prune reports');
    $schedule->call(fn () => null)->weekly();
    $schedule->exec('echo hi')->daily();

    scheduleMonitorWith($http)->sync($schedule);

    $tasks = json_decode((string) $http->lastRequest()->getBody(), true)['tasks'];

    expect($tasks)->toHaveCount(4)
        ->and($tasks[0])->toMatchArray(['key' => 'inspire', 'summary' => 'Morning wisdom', 'cron' => '0 * * * *', 'timezone' => 'Europe/London'])
        ->and($tasks[1])->toMatchArray(['key' => 'Prune reports', 'summary' => null, 'cron' => '0 0 * * *', 'interval_minutes' => null])
        ->and($tasks[2]['key'])->toStartWith('closure:')
        ->and($tasks[3])->toMatchArray(['key' => 'echo hi', 'summary' => null]);
})->group('schedule-monitor');

it('builds its client from the cinder config', function () {
    config([
        'cinder.base_url' => 'https://cinder.example/',
        'cinder.schedule_monitor.api_token' => 'from-config',
        'cinder.schedule_monitor.api_url' => null,
    ]);

    $client = (new ScheduleMonitor)->client();

    expect((fn () => [$this->apiToken, $this->apiBaseUrl])->call($client))
        ->toBe(['from-config', 'https://cinder.example/api/v1/']);

    config(['cinder.schedule_monitor.api_url' => 'https://ingest.example/api/v1']);

    expect((fn () => $this->apiBaseUrl)->call((new ScheduleMonitor)->client()))->toBe('https://ingest.example/api/v1/');
})->group('schedule-monitor');

it('needs a token to be enabled', function () {
    config(['cinder.schedule_monitor.enabled' => true, 'cinder.schedule_monitor.api_token' => '']);

    expect((new ScheduleMonitor)->enabled())->toBeFalse();

    config(['cinder.schedule_monitor.api_token' => 'token']);

    expect((new ScheduleMonitor)->enabled())->toBeTrue();
})->group('schedule-monitor');

it('never uses a negative grace period', function () {
    config(['cinder.schedule_monitor.grace_minutes' => -3]);

    expect((new ScheduleMonitor)->graceMinutes())->toBe(0);

    config(['cinder.schedule_monitor.grace_minutes' => null]);

    expect((new ScheduleMonitor)->graceMinutes())->toBe(5);
})->group('schedule-monitor');

it('sends a started heartbeat when a task starts', function () {
    $http = new FakeHttpClient;
    $schedule = new Schedule('UTC');
    $event = $schedule->command('inspire')->everyMinute();

    syncedMonitor($http, $schedule)->handleStarting(new ScheduledTaskStarting($event));

    expect(lastHeartbeat($http))->toBe([SYNCED_TASK_URL, ['event' => 'started']])
        ->and($http->lastRequest()->hasHeader('Authorization'))->toBeFalse();
})->group('schedule-monitor');

it('sends a succeeded heartbeat with run metrics when a task finishes', function () {
    $http = new FakeHttpClient;
    $schedule = new Schedule('UTC');
    $event = $schedule->command('inspire')->everyMinute();
    $event->exitCode = 0;

    syncedMonitor($http, $schedule)->handleFinished(new ScheduledTaskFinished($event, 1.5));

    [$url, $body] = lastHeartbeat($http);

    expect($url)->toBe(SYNCED_TASK_URL)
        ->and(array_keys($body))->toBe(['event', 'duration_ms', 'memory_bytes', 'exit_code'])
        ->and($body['event'])->toBe('succeeded')
        ->and($body['duration_ms'])->toBe(1500)
        ->and($body['memory_bytes'])->toBeInt()
        ->and($body['exit_code'])->toBe(0);
})->group('schedule-monitor');

it('leaves a non-zero exit to the failed heartbeat', function () {
    $http = new FakeHttpClient;
    $schedule = new Schedule('UTC');
    $event = $schedule->command('inspire')->everyMinute();
    $event->exitCode = 2;

    $monitor = syncedMonitor($http, $schedule);
    $monitor->handleFinished(new ScheduledTaskFinished($event, 1.0));

    expect($http->lastRequest()->getMethod())->toBe('PUT');

    $monitor->handleFailed(new ScheduledTaskFailed($event, new RuntimeException('Scheduled command failed with exit code [2].'), 1.25));

    [$url, $body] = lastHeartbeat($http);

    expect($url)->toBe(SYNCED_TASK_URL)
        ->and($body['event'])->toBe('failed')
        ->and($body['duration_ms'])->toBe(1250)
        ->and($body['exit_code'])->toBe(2)
        ->and($body['error'])->toBe('Scheduled command failed with exit code [2].');
})->group('schedule-monitor');

it('reports an exception thrown by the task as exit code one', function () {
    $http = new FakeHttpClient;
    $schedule = new Schedule('UTC');
    $event = $schedule->command('inspire')->everyMinute();

    syncedMonitor($http, $schedule)->handleFailed(new ScheduledTaskFailed($event, new RuntimeException('Boom')));

    [, $body] = lastHeartbeat($http);

    expect($body)->toMatchArray(['event' => 'failed', 'exit_code' => 1, 'error' => 'Boom'])
        ->and($body)->not->toHaveKey('duration_ms');
})->group('schedule-monitor');

it('sends no heartbeat for tasks it does not know', function () {
    $http = new FakeHttpClient;
    $schedule = new Schedule('UTC');
    $schedule->command('inspire')->everyMinute();
    $unknown = (new Schedule('UTC'))->command('migrate')->daily();

    syncedMonitor($http, $schedule)->handleStarting(new ScheduledTaskStarting($unknown));

    expect($http->requests)->toHaveCount(1);
})->group('schedule-monitor');

it('sends no heartbeat once monitoring is turned off', function () {
    $http = new FakeHttpClient;
    $schedule = new Schedule('UTC');
    $event = $schedule->command('inspire')->everyMinute();

    $monitor = syncedMonitor($http, $schedule);
    config(['cinder.schedule_monitor.enabled' => false]);
    $monitor->handleStarting(new ScheduledTaskStarting($event));

    expect($http->requests)->toHaveCount(1);
})->group('schedule-monitor');

it('listens to scheduled task events when enabled', function () {
    expect(app('events')->hasListeners(ScheduledTaskStarting::class))->toBeFalse();

    $this->rebootWith(['cinder.schedule_monitor.enabled' => true]);

    expect(app('events')->hasListeners(ScheduledTaskStarting::class))->toBeTrue()
        ->and(app('events')->hasListeners(ScheduledTaskFinished::class))->toBeTrue()
        ->and(app('events')->hasListeners(ScheduledTaskFailed::class))->toBeTrue();
})->group('schedule-monitor');
