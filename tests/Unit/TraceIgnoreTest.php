<?php

use Cinderwatch\Laravel\Tracing\TraceIgnore;

it('ignores commands by exact name or trailing wildcard', function (?string $command, bool $ignored) {
    config(['cinder.trace.ignore_commands' => ['queue:work', 'horizon:*']]);

    expect(TraceIgnore::shouldIgnoreCommand($command))->toBe($ignored);
})->with([
    'exact match' => ['queue:work', true],
    'wildcard match' => ['horizon:supervisor', true],
    'no match' => ['migrate', false],
    'prefix without wildcard' => ['queue:work-extra', false],
    'unnamed command' => [null, true],
    'empty command' => ['', true],
])->group('tracing');

it('ignores jobs listed by class name', function () {
    config(['cinder.trace.ignore_jobs' => ['App\Jobs\IngestTelemetry']]);

    expect(TraceIgnore::shouldIgnoreJob('App\Jobs\IngestTelemetry'))->toBeTrue()
        ->and(TraceIgnore::shouldIgnoreJob('App\Jobs\SendInvoice'))->toBeFalse();
})->group('tracing');
