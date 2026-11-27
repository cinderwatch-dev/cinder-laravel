<?php

use Cinderwatch\Laravel\Tracing\OutboundHttpSpans;
use Cinderwatch\Transport\ArrayTransport;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

it('records external http spans for the laravel http client during a sampled trace', function () {
    $transport = new ArrayTransport;
    $client = $this->traceInto($transport);
    $entry = $client->tracer()->open('Request - /probe');

    Http::fake(['https://api.example.com/*' => Http::response(['ok' => true], 201)]);
    Http::get('https://api.example.com/widgets');

    $client->tracer()->close($entry);
    $client->flush();

    $span = $this->spanOfKind($transport, 'http_client');

    expect($span['name'])->toBe('HTTP - api.example.com')
        ->and($this->spanAttribute($span, 'url.full'))->toBe('https://api.example.com/widgets')
        ->and($this->spanAttribute($span, 'http.request.method'))->toBe('GET')
        ->and($this->spanAttribute($span, 'http.response.status_code'))->toBe(201);
})->group('tracing');

it('records a failed connection with the no response status', function () {
    $transport = new ArrayTransport;
    $client = $this->traceInto($transport);
    $entry = $client->tracer()->open('Request - /probe');

    Http::fake(['https://down.example.com/*' => Http::failedConnection()]);

    expect(fn () => Http::post('https://down.example.com/ping'))->toThrow(ConnectionException::class);

    $client->tracer()->close($entry);
    $client->flush();

    $span = $this->spanOfKind($transport, 'http_client');

    expect($this->spanAttribute($span, 'http.request.method'))->toBe('POST')
        ->and($this->spanAttribute($span, 'http.response.status_code'))->toBe(OutboundHttpSpans::NO_RESPONSE_STATUS);
})->group('tracing');

it('does not record calls to the cinder ingest host', function () {
    $transport = new ArrayTransport;
    $client = $this->traceInto($transport);
    $entry = $client->tracer()->open('Request - /probe');

    Http::fake(['https://cinder.test/*' => Http::response(null, 202)]);
    Http::post('https://cinder.test/api/v1/traces', ['resourceSpans' => []]);

    $client->tracer()->close($entry);
    $client->flush();

    expect($this->spanOfKind($transport, 'http_client'))->toBeNull();
})->group('tracing');

it('does not record outbound http without an active trace', function () {
    $transport = new ArrayTransport;
    $client = $this->traceInto($transport);

    Http::fake(['https://api.example.com/*' => Http::response('ok')]);
    Http::get('https://api.example.com/idle');

    $client->flush();

    expect($this->sentSpans($transport))->toBeEmpty();
})->group('tracing');

it('records requests sent through the guzzle middleware', function () {
    $transport = new ArrayTransport;
    $client = $this->traceInto($transport);
    $entry = $client->tracer()->open('Request - /probe');

    $stack = HandlerStack::create(new MockHandler([
        new PsrResponse(204),
        new ConnectException('Timed out', new PsrRequest('GET', 'https://slow.example.com/report')),
    ]));
    $stack->push(OutboundHttpSpans::guzzleMiddleware());
    $guzzle = new Client(['handler' => $stack]);

    $guzzle->delete('https://api.example.com/widgets/1');

    expect(fn () => $guzzle->get('https://slow.example.com/report'))->toThrow(ConnectException::class, 'Timed out');

    $client->tracer()->close($entry);
    $client->flush();

    $spans = collect($this->sentSpans($transport))
        ->filter(fn (array $span) => $this->spanAttribute($span, 'cinder.span.kind') === 'http_client')
        ->values();

    expect($spans)->toHaveCount(2)
        ->and($spans[0]['name'])->toBe('HTTP - api.example.com')
        ->and($this->spanAttribute($spans[0], 'http.request.method'))->toBe('DELETE')
        ->and($this->spanAttribute($spans[0], 'http.response.status_code'))->toBe(204)
        ->and($spans[1]['name'])->toBe('HTTP - slow.example.com')
        ->and($this->spanAttribute($spans[1], 'http.response.status_code'))->toBe(OutboundHttpSpans::NO_RESPONSE_STATUS);
})->group('tracing');

it('names a span after the url when it has no host', function () {
    $transport = new ArrayTransport;
    $client = $this->traceInto($transport);
    $entry = $client->tracer()->open('Request - /probe');

    OutboundHttpSpans::record('/relative/path', 'get', 200, 1, 2);

    $client->tracer()->close($entry);
    $client->flush();

    expect($this->spanOfKind($transport, 'http_client')['name'])->toBe('HTTP - /relative/path');
})->group('tracing');
