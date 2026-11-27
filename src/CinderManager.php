<?php

namespace Cinderwatch\Laravel;

use Cinderwatch\CinderClient;
use Cinderwatch\Errors\ReportEnricher;
use Cinderwatch\Health\Inspector;
use Cinderwatch\Health\Probe;
use Cinderwatch\Support\TelemetryAttributeKeys;
use Cinderwatch\Tracing\Span;
use Cinderwatch\Tracing\SpanCategory;
use Cinderwatch\Tracing\Tracer;
use Closure;
use Throwable;

class CinderManager
{
    public function __construct(
        private readonly CinderClient $client,
        private readonly Inspector $inspector,
    ) {}

    public function client(): CinderClient
    {
        return $this->client;
    }

    public function health(): Inspector
    {
        return $this->inspector;
    }

    public function tracer(): Tracer
    {
        return $this->client->tracer();
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  list<string>|null  $fingerprint  Overrides grouping for this report.
     * @return array<string, mixed>|null
     */
    public function report(Throwable $throwable, array $context = [], ?bool $handled = null, ?array $fingerprint = null): ?array
    {
        return $this->client->report($throwable, $context, $handled, $fingerprint);
    }

    /**
     * @param  Closure(Throwable): bool  $predicate
     */
    public function reportOnlyWhen(Closure $predicate): void
    {
        $this->client->reportOnlyWhen($predicate);
    }

    /**
     * Return null from the resolver to keep the default grouping.
     *
     * @param  Closure(Throwable): (list<string>|null)  $resolver
     */
    public function fingerprintUsing(Closure $resolver): void
    {
        $this->client->fingerprintUsing($resolver);
    }

    /**
     * @param  Closure(array<string, mixed>, Throwable): array<string, mixed>  $filter
     */
    public function beforeSend(Closure $filter): void
    {
        $this->client->beforeSend($filter);
    }

    public function addEnricher(ReportEnricher $enricher): void
    {
        $this->client->addEnricher($enricher);
    }

    /**
     * Record the callback as a custom span, nested under whatever is being traced right now.
     *
     * @template TReturn
     *
     * @param  Closure(Span): TReturn  $callback
     * @param  array<string, mixed>  $attributes
     * @return TReturn
     */
    public function trace(string $name, Closure $callback, array $attributes = []): mixed
    {
        $span = $this->tracer()->open($name, [
            TelemetryAttributeKeys::SpanKind => SpanCategory::Custom->value,
            ...$attributes,
        ]);

        try {
            return $callback($span);
        } finally {
            $this->tracer()->close($span);
        }
    }

    /**
     * Send a log entry straight to Cinder, without going through a log channel.
     *
     * @param  array<string, mixed>  $context
     */
    public function log(string $message, string $level = 'info', array $context = []): void
    {
        $this->client->logs()->write($message, $level, $context);
    }

    public function registerProbe(Probe ...$probes): void
    {
        $this->inspector->register(...$probes);
    }

    /**
     * Replace every registered probe.
     *
     * @param  iterable<Probe>  $probes
     */
    public function probes(iterable $probes): void
    {
        $this->inspector->replace($probes);
    }

    public function flush(): void
    {
        $this->client->flush();
    }
}
