<?php

namespace Cinderwatch\Laravel\Tracing;

use Cinderwatch\CinderClient;
use Cinderwatch\Support\TelemetryAttributeKeys;
use Cinderwatch\Support\Time;
use Cinderwatch\Tracing\SpanCategory;
use Illuminate\Database\Events\QueryExecuted;

/**
 * `QueryExecuted` fires after the query finished, so the span is back-dated.
 */
class QuerySpanListener
{
    public function __construct(
        private readonly CinderClient $client,
    ) {}

    public function handle(QueryExecuted $event): void
    {
        $tracer = $this->client->tracer();

        if (! config('cinder.report_traces') || ! $tracer->isRecording()) {
            return;
        }

        $endedAt = Time::unixNano();

        $tracer->recordCompleted(
            'Query - '.$event->sql,
            [
                TelemetryAttributeKeys::SpanKind => SpanCategory::DatabaseQuery->value,
                'db.query.text' => $event->sql,
                'db.system.name' => $event->connection->getDriverName(),
                TelemetryAttributeKeys::DbConnection => $event->connectionName,
            ],
            $endedAt - (int) round($event->time * 1_000_000),
            $endedAt,
        );
    }
}
