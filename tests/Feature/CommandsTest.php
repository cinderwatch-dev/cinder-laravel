<?php

use Cinderwatch\Laravel\Facades\Cinder;
use Cinderwatch\Laravel\ScheduleMonitor\ScheduleMonitor;
use Cinderwatch\Laravel\Tests\Fixtures\FakeHttpClient;
use Cinderwatch\ScheduleMonitor\ScheduleClient;
use Cinderwatch\Transport\ArrayTransport;
use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Console\Scheduling\Schedule;

function fakeScheduleApi(FakeHttpClient $http): void
{
    config(['cinder.schedule_monitor.enabled' => true, 'cinder.schedule_monitor.api_token' => 'token']);

    $factory = new HttpFactory;

    app()->instance(ScheduleMonitor::class, new ScheduleMonitor(
        new ScheduleClient('token', 'https://cinder.test/api/v1', $http, $factory, $factory),
    ));
}

it('sends a test exception to cinder', function () {
    $transport = new ArrayTransport;
    $this->bindClient($transport);

    $this->artisan('cinder:test')
        ->expectsOutput('Test exception sent to Cinder.')
        ->assertSuccessful();

    expect($transport->sent[0]['payload']['exception']['message'])->toBe('Cinder test exception from '.config('app.name'));
})->group('commands');

it('fails the test command when no key is configured', function () {
    config(['cinder.key' => null]);

    $this->artisan('cinder:test')
        ->expectsOutput('Cinder is not configured. Set CINDER_KEY.')
        ->assertFailed();
})->group('commands');

it('fails the test command when the exception is filtered out', function () {
    $transport = new ArrayTransport;
    $this->bindClient($transport);
    Cinder::reportOnlyWhen(fn () => false);

    $this->artisan('cinder:test')
        ->expectsOutput('The test exception was filtered and not sent.')
        ->assertFailed();

    expect($transport->sent)->toBeEmpty();
})->group('commands');

it('syncs the application schedule with cinder', function () {
    $http = (new FakeHttpClient)->addResponse(200, (string) file_get_contents(__DIR__.'/../Fixtures/schedule-sync-response.json'));
    fakeScheduleApi($http);
    app(Schedule::class)->command('inspire')->everyMinute();

    $this->artisan('cinder:schedule-sync')
        ->expectsOutput('Synced 1 scheduled tasks with Cinder.')
        ->assertSuccessful();

    expect((string) $http->lastRequest()->getUri())->toBe('https://cinder.test/api/v1/schedule');
})->group('commands');

it('fails the schedule sync when cinder rejects it', function () {
    fakeScheduleApi((new FakeHttpClient)->addResponse(422));

    $this->artisan('cinder:schedule-sync')
        ->expectsOutput('Cinder PUT /schedule failed with HTTP 422.')
        ->assertFailed();
})->group('commands');

it('fails the schedule sync when monitoring is not configured', function () {
    $this->artisan('cinder:schedule-sync')
        ->expectsOutput('Cinder schedule monitoring is not configured.')
        ->assertFailed();
})->group('commands');
