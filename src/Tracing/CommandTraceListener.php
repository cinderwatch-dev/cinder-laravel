<?php

namespace Cinderwatch\Laravel\Tracing;

use Cinderwatch\Laravel\Facades\Cinder;
use Cinderwatch\Support\TelemetryAttributeKeys;
use Cinderwatch\Tracing\Span;
use Cinderwatch\Tracing\SpanCategory;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;

/**
 * Commands can call other commands, so open spans are kept as a stack.
 */
class CommandTraceListener
{
    /** @var list<Span> */
    private array $running = [];

    public function handleStarting(CommandStarting $event): void
    {
        if (! config('cinder.report_traces') || TraceIgnore::shouldIgnoreCommand($event->command)) {
            return;
        }

        $command = (string) $event->command;

        $this->running[] = Cinder::tracer()->open('Command - '.$command, [
            TelemetryAttributeKeys::SpanKind => SpanCategory::ConsoleCommand->value,
            TelemetryAttributeKeys::EntryKind => 'cli',
            TelemetryAttributeKeys::EntryName => 'php artisan '.$command,
            TelemetryAttributeKeys::EntryHandler => $command,
            'process.command' => $command,
        ]);
    }

    public function handleFinished(CommandFinished $event): void
    {
        $span = array_pop($this->running);

        if ($span === null) {
            return;
        }

        $span->annotate(['process.exit_code' => $event->exitCode]);
        Cinder::tracer()->close($span);
    }
}
