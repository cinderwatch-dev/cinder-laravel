<?php

namespace Cinderwatch\Laravel\Tracing;

use Cinderwatch\Laravel\Facades\Cinder;
use Cinderwatch\Support\TelemetryAttributeKeys;
use Cinderwatch\Support\Time;
use Cinderwatch\Tracing\SpanCategory;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use WeakMap;

/**
 * Start times are keyed weakly by the request object, because the "sent" and
 * "received" hooks fire separately.
 */
class OutboundHttpSpans
{
    /** Status recorded when no response arrived (DNS, TLS, timeout...). */
    public const NO_RESPONSE_STATUS = 503;

    /** @var WeakMap<object, int>|null */
    private static ?WeakMap $startedAt = null;

    public static function begin(object $request, string $url): void
    {
        if (self::targetsCinder($url)) {
            return;
        }

        self::$startedAt ??= new WeakMap;
        self::$startedAt[$request] = Time::unixNano();
    }

    public static function end(object $request, string $url, string $method, int $statusCode): void
    {
        $endedAt = Time::unixNano();
        $startedAt = self::$startedAt[$request] ?? $endedAt;
        unset(self::$startedAt[$request]);

        self::record($url, $method, $statusCode, $startedAt, $endedAt);
    }

    public static function record(string $url, string $method, int $statusCode, int $startedAt, int $endedAt): void
    {
        if (! config('cinder.report_traces') || self::targetsCinder($url)) {
            return;
        }

        $host = parse_url($url, PHP_URL_HOST);

        Cinder::tracer()->recordCompleted(
            'HTTP - '.(is_string($host) && $host !== '' ? $host : $url),
            [
                TelemetryAttributeKeys::SpanKind => SpanCategory::OutboundHttp->value,
                'url.full' => $url,
                'http.request.method' => strtoupper($method),
                'http.response.status_code' => $statusCode,
            ],
            $startedAt,
            $endedAt,
        );
    }

    /**
     * For Guzzle clients that bypass the `Http` facade: `$stack->push(OutboundHttpSpans::guzzleMiddleware())`.
     *
     * @return callable(callable): callable
     */
    public static function guzzleMiddleware(): callable
    {
        return fn (callable $handler): callable => function (RequestInterface $request, array $options) use ($handler) {
            $url = (string) $request->getUri();
            $startedAt = Time::unixNano();

            return $handler($request, $options)->then(
                function (ResponseInterface $response) use ($url, $request, $startedAt): ResponseInterface {
                    self::record($url, $request->getMethod(), $response->getStatusCode(), $startedAt, Time::unixNano());

                    return $response;
                },
                function (mixed $reason) use ($url, $request, $startedAt): PromiseInterface {
                    self::record($url, $request->getMethod(), self::NO_RESPONSE_STATUS, $startedAt, Time::unixNano());

                    return Create::rejectionFor($reason);
                },
            );
        };
    }

    /**
     * Telemetry exports to Cinder itself must never be traced.
     */
    public static function targetsCinder(string $url): bool
    {
        $cinderHost = parse_url((string) config('cinder.base_url', ''), PHP_URL_HOST);
        $targetHost = parse_url($url, PHP_URL_HOST);

        return is_string($cinderHost) && $cinderHost !== ''
            && is_string($targetHost) && strcasecmp($cinderHost, $targetHost) === 0;
    }
}
