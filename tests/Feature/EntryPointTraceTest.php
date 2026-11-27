<?php

use Cinderwatch\Laravel\Tests\Fixtures\Jobs\FailingJob;
use Cinderwatch\Laravel\Tests\Fixtures\Jobs\IgnoredJob;
use Cinderwatch\Laravel\Tests\Fixtures\Jobs\TracedJob;
use Cinderwatch\Laravel\Tracing\CommandTraceListener;
use Cinderwatch\Laravel\Tracing\QuerySpanListener;
use Cinderwatch\Laravel\Tracing\TraceRequests;
use Cinderwatch\Transport\ArrayTransport;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

function runCommandThrough(CommandTraceListener $listener, string $command, int $exitCode = 0): void
{
    $input = new ArrayInput([]);
    $output = new BufferedOutput;

    $listener->handleStarting(new CommandStarting($command, $input, $output));
    $listener->handleFinished(new CommandFinished($command, $input, $output, $exitCode));
}

it('records a trace when a queue job runs', function () {
    $transport = new ArrayTransport;
    $client = $this->traceInto($transport);

    dispatch_sync(new TracedJob);
    $client->flush();

    $spans = $this->sentSpans($transport);

    expect($spans)->toHaveCount(1)
        ->and($spans[0]['name'])->toBe('Job - '.TracedJob::class)
        ->and($this->spanAttribute($spans[0], 'cinder.span.kind'))->toBe('job')
        ->and($this->spanAttribute($spans[0], 'cinder.entry.kind'))->toBe('queue')
        ->and($this->spanAttribute($spans[0], 'cinder.entry.name'))->toBe(TracedJob::class)
        ->and($this->spanAttribute($spans[0], 'cinder.job.class'))->toBe(TracedJob::class)
        ->and($this->spanAttribute($spans[0], 'cinder.job.name'))->toBe(TracedJob::class)
        ->and($this->spanAttribute($spans[0], 'messaging.system'))->toBe('laravel')
        ->and($this->spanAttribute($spans[0], 'cinder.job.connection'))->toBe('sync')
        ->and($this->spanAttribute($spans[0], 'messaging.destination.name'))->toBe('sync')
        ->and($this->spanAttribute($spans[0], 'cinder.job.attempt'))->toBe(1)
        ->and($this->spanAttribute($spans[0], 'cinder.job.succeeded'))->toBeTrue();
})->group('tracing');

it('marks the job span as failed when the job throws', function () {
    $transport = new ArrayTransport;
    $client = $this->traceInto($transport);

    expect(fn () => dispatch_sync(new FailingJob))->toThrow(RuntimeException::class, 'Job failed');

    $client->flush();

    $span = $this->spanOfKind($transport, 'job');

    expect($span['name'])->toBe('Job - '.FailingJob::class)
        ->and($this->spanAttribute($span, 'cinder.job.succeeded'))->toBeFalse();
})->group('tracing');

it('does not trace ignored queue jobs', function () {
    config(['cinder.trace.ignore_jobs' => [IgnoredJob::class]]);

    $transport = new ArrayTransport;
    $client = $this->traceInto($transport);

    dispatch_sync(new IgnoredJob);
    $client->flush();

    expect($this->sentSpans($transport))->toBeEmpty();
})->group('tracing');

it('does not trace queue jobs when tracing is off', function () {
    $transport = new ArrayTransport;
    $client = $this->traceInto($transport);
    config(['cinder.report_traces' => false]);

    dispatch_sync(new TracedJob);
    $client->flush();

    expect($this->sentSpans($transport))->toBeEmpty();
})->group('tracing');

it('records a trace when an artisan command runs', function () {
    $transport = new ArrayTransport;
    $client = $this->traceInto($transport);

    runCommandThrough(app(CommandTraceListener::class), 'cinder:test', exitCode: 1);
    $client->flush();

    $command = $this->spanOfKind($transport, 'command');

    expect($command['name'])->toBe('Command - cinder:test')
        ->and($this->spanAttribute($command, 'cinder.entry.kind'))->toBe('cli')
        ->and($this->spanAttribute($command, 'cinder.entry.name'))->toBe('php artisan cinder:test')
        ->and($this->spanAttribute($command, 'cinder.entry.handler'))->toBe('cinder:test')
        ->and($this->spanAttribute($command, 'process.command'))->toBe('cinder:test')
        ->and($this->spanAttribute($command, 'process.exit_code'))->toBe(1);
})->group('tracing');

it('does not trace ignored artisan commands', function () {
    $transport = new ArrayTransport;
    $client = $this->traceInto($transport);

    runCommandThrough(app(CommandTraceListener::class), 'queue:work');
    $client->flush();

    expect($this->sentSpans($transport))->toBeEmpty();
})->group('tracing');

it('ignores a finished command it never started', function () {
    $transport = new ArrayTransport;
    $client = $this->traceInto($transport);

    app(CommandTraceListener::class)->handleFinished(
        new CommandFinished('cinder:test', new ArrayInput([]), new BufferedOutput, 0),
    );
    $client->flush();

    expect($this->sentSpans($transport))->toBeEmpty();
})->group('tracing');

it('labels request spans with the route template and handler, and query spans with the sql', function () {
    $transport = new ArrayTransport;
    $client = $this->traceInto($transport);

    config(['database.default' => 'testing', 'database.connections.testing' => ['driver' => 'sqlite', 'database' => ':memory:']]);
    app('events')->listen(QueryExecuted::class, QuerySpanListener::class);

    Route::middleware(TraceRequests::class)->get('/orders/{order}', function () {
        DB::select('select 1');

        return 'ok';
    });

    $this->get('/orders/42')->assertOk();
    $client->flush();

    $route = $this->spanOfKind($transport, 'route');
    $query = $this->spanOfKind($transport, 'query');

    expect($route['name'])->toBe('Request - /orders/{order}')
        ->and($this->spanAttribute($route, 'cinder.entry.route'))->toBe('/orders/{order}')
        ->and($this->spanAttribute($route, 'http.route'))->toBe('/orders/{order}')
        ->and($this->spanAttribute($route, 'cinder.entry.handler'))->toBe('Closure')
        ->and($this->spanAttribute($route, 'cinder.entry.name'))->toBe('/orders/42')
        ->and($this->spanAttribute($route, 'http.request.method'))->toBe('GET')
        ->and($this->spanAttribute($route, 'http.response.status_code'))->toBe(200)
        ->and($query['name'])->toBe('Query - select 1')
        ->and($query['parentSpanId'])->toBe($route['spanId'])
        ->and($this->spanAttribute($query, 'db.query.text'))->toBe('select 1')
        ->and($this->spanAttribute($query, 'db.system.name'))->toBe('sqlite')
        ->and($this->spanAttribute($query, 'cinder.db.connection'))->toBe('testing');
})->group('tracing');

it('passes requests straight through when tracing is off', function () {
    $transport = new ArrayTransport;
    $client = $this->traceInto($transport);
    config(['cinder.report_traces' => false]);

    Route::middleware(TraceRequests::class)->get('/untraced', fn () => 'ok');

    $this->get('/untraced')->assertOk();
    $client->flush();

    expect($this->sentSpans($transport))->toBeEmpty();
})->group('tracing');

it('does not record queries outside a sampled trace', function () {
    $transport = new ArrayTransport;
    $client = $this->traceInto($transport);

    app(QuerySpanListener::class)->handle(new QueryExecuted('select 1', [], 1.5, DB::connection()));
    $client->flush();

    expect($this->sentSpans($transport))->toBeEmpty();
})->group('tracing');
