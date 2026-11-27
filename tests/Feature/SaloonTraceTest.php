<?php

use Cinderwatch\Laravel\Tracing\OutboundHttpSpans;
use Cinderwatch\Laravel\Tracing\SaloonOutgoingHttpTracer;
use Cinderwatch\Transport\ArrayTransport;
use Saloon\Config;
use Saloon\Enums\Method;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Http\PendingRequest;
use Saloon\Http\Response;

require_once __DIR__.'/../Fixtures/Stubs/saloon.php';

beforeEach(function () {
    Config::resetMiddleware();
    (fn () => self::$registered = false)->bindTo(null, SaloonOutgoingHttpTracer::class)();

    SaloonOutgoingHttpTracer::register();
});

it('registers the saloon global middleware once', function () {
    SaloonOutgoingHttpTracer::register();

    $pipeline = Config::globalMiddleware();

    expect(array_keys($pipeline->onRequest))->toBe(['cinder-outgoing-http-request'])
        ->and(array_keys($pipeline->onResponse))->toBe(['cinder-outgoing-http-response'])
        ->and(array_keys($pipeline->onFatalException))->toBe(['cinder-outgoing-http-fatal']);
})->group('tracing');

it('records saloon requests and fatal errors as outbound http spans', function () {
    $transport = new ArrayTransport;
    $client = $this->traceInto($transport);
    $entry = $client->tracer()->open('Request - /probe');
    $pipeline = Config::globalMiddleware();

    $sent = new PendingRequest('https://api.example.com/invoices', Method::POST);
    $pipeline->onRequest['cinder-outgoing-http-request']($sent);
    $pipeline->onResponse['cinder-outgoing-http-response'](new Response($sent, 201));

    $failed = new PendingRequest('https://down.example.com/ping');
    $pipeline->onRequest['cinder-outgoing-http-request']($failed);
    $pipeline->onFatalException['cinder-outgoing-http-fatal'](new FatalRequestException($failed));

    $client->tracer()->close($entry);
    $client->flush();

    $spans = collect($this->sentSpans($transport))
        ->filter(fn (array $span) => $this->spanAttribute($span, 'cinder.span.kind') === 'http_client')
        ->values();

    expect($spans)->toHaveCount(2)
        ->and($this->spanAttribute($spans[0], 'url.full'))->toBe('https://api.example.com/invoices')
        ->and($this->spanAttribute($spans[0], 'http.request.method'))->toBe('POST')
        ->and($this->spanAttribute($spans[0], 'http.response.status_code'))->toBe(201)
        ->and($this->spanAttribute($spans[1], 'url.full'))->toBe('https://down.example.com/ping')
        ->and($this->spanAttribute($spans[1], 'http.response.status_code'))->toBe(OutboundHttpSpans::NO_RESPONSE_STATUS);
})->group('tracing');
