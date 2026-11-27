<?php

namespace Cinderwatch\Laravel\Tracing;

use Cinderwatch\Laravel\Facades\Cinder;
use Cinderwatch\Support\TelemetryAttributeKeys;
use Cinderwatch\Tracing\Span;
use Cinderwatch\Tracing\SpanCategory;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;

class JobTraceListener
{
    /** @var array<string, Span> */
    private array $running = [];

    public function handleProcessing(JobProcessing $event): void
    {
        if (! config('cinder.report_traces')) {
            return;
        }

        $class = $event->job->resolveName();

        if (TraceIgnore::shouldIgnoreJob($class)) {
            return;
        }

        $this->running[$this->key($event->job)] = Cinder::tracer()->open('Job - '.$class, [
            TelemetryAttributeKeys::SpanKind => SpanCategory::QueuedJob->value,
            TelemetryAttributeKeys::EntryKind => 'queue',
            TelemetryAttributeKeys::EntryName => $class,
            TelemetryAttributeKeys::EntryHandler => $class,
            TelemetryAttributeKeys::JobClass => $class,
            TelemetryAttributeKeys::JobName => $class,
            TelemetryAttributeKeys::MessagingSystem => 'laravel',
            TelemetryAttributeKeys::JobConnection => $event->connectionName,
            TelemetryAttributeKeys::MessagingDestination => $event->job->getQueue(),
            TelemetryAttributeKeys::JobAttempt => $event->job->attempts(),
        ]);
    }

    public function handleProcessed(JobProcessed $event): void
    {
        $this->complete($event->job, succeeded: true);
    }

    public function handleFailed(JobFailed $event): void
    {
        $this->complete($event->job, succeeded: false);
    }

    private function complete(Job $job, bool $succeeded): void
    {
        $key = $this->key($job);
        $span = $this->running[$key] ?? null;

        if ($span === null) {
            return;
        }

        unset($this->running[$key]);

        $span->annotate([TelemetryAttributeKeys::JobSucceeded => $succeeded]);
        Cinder::tracer()->close($span);
    }

    private function key(Job $job): string
    {
        return (string) ($job->uuid() ?? $job->getJobId());
    }
}
