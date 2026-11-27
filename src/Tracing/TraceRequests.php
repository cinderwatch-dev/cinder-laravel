<?php

namespace Cinderwatch\Laravel\Tracing;

use Cinderwatch\CinderClient;
use Cinderwatch\Support\TelemetryAttributeKeys;
use Cinderwatch\Tracing\SpanCategory;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;

class TraceRequests
{
    public function __construct(
        private readonly CinderClient $client,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('cinder.report_traces')) {
            return $next($request);
        }

        $route = $request->route();
        $path = '/'.ltrim($request->path(), '/');
        $template = $route instanceof Route ? '/'.ltrim($route->uri(), '/') : null;

        $tracer = $this->client->tracer();
        $span = $tracer->open('Request - '.($template ?? $path), [
            TelemetryAttributeKeys::SpanKind => SpanCategory::WebRequest->value,
            TelemetryAttributeKeys::EntryKind => 'web',
            TelemetryAttributeKeys::EntryName => $path,
            TelemetryAttributeKeys::EntryHandler => $route instanceof Route ? $route->getActionName() : null,
            TelemetryAttributeKeys::EntryRoute => $template,
            'http.request.method' => strtoupper($request->method()),
            'http.route' => $template,
            'url.full' => $request->fullUrl(),
        ]);

        try {
            /** @var Response $response */
            $response = $next($request);
            $span->annotate(['http.response.status_code' => $response->getStatusCode()]);

            return $response;
        } finally {
            $tracer->close($span);
        }
    }
}
