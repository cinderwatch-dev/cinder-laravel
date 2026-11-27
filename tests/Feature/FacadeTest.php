<?php

use Cinderwatch\Config\CinderConfig;
use Cinderwatch\Errors\ReportEnricher;
use Cinderwatch\Health\Observation;
use Cinderwatch\Laravel\Facades\Cinder;
use Cinderwatch\Laravel\Tests\Fixtures\StubProbe;
use Cinderwatch\Tracing\Span;
use Cinderwatch\Transport\ArrayTransport;

it('scrubs reports with a before send filter', function () {
    $transport = new ArrayTransport;
    $this->bindClient($transport);

    Cinder::beforeSend(function (array $payload) {
        $payload['context']['scrubbed'] = true;

        return $payload;
    });

    Cinder::report(new RuntimeException('boom'));

    expect($transport->sent[0]['payload']['context']['scrubbed'])->toBeTrue();
})->group('facade');

it('adds enricher attributes to every report', function () {
    $transport = new ArrayTransport;
    $this->bindClient($transport);

    Cinder::addEnricher(new class implements ReportEnricher
    {
        public function attributesFor(Throwable $throwable): array
        {
            return ['tenant.id' => 42];
        }
    });

    Cinder::report(new RuntimeException('boom'));

    expect($transport->sent[0]['payload']['context']['tenant.id'])->toBe(42);
})->group('facade');

it('records a custom span around the callback and returns its result', function () {
    $transport = new ArrayTransport;
    $this->traceInto($transport);

    $result = Cinder::trace('Build invoices', function (Span $span) {
        $span->annotate(['invoices.count' => 3]);

        return 'built';
    }, ['order.id' => 7]);

    Cinder::flush();

    $span = $this->spanOfKind($transport, 'custom');

    expect($result)->toBe('built')
        ->and($span['name'])->toBe('Build invoices')
        ->and($this->spanAttribute($span, 'order.id'))->toBe(7)
        ->and($this->spanAttribute($span, 'invoices.count'))->toBe(3);
})->group('facade');

it('still closes the span when the callback throws', function () {
    $transport = new ArrayTransport;
    $this->traceInto($transport);

    expect(fn () => Cinder::trace('Explodes', fn () => throw new RuntimeException('boom')))
        ->toThrow(RuntimeException::class, 'boom');

    expect(Cinder::tracer()->hasActiveTrace())->toBeFalse();
})->group('facade');

it('sends a log entry straight to cinder', function () {
    $transport = new ArrayTransport;
    $this->bindClient($transport, new CinderConfig(
        key: 'test-key',
        baseUrl: 'https://cinder.test',
        reportLogs: true,
    ));

    Cinder::log('Import finished', 'warning', ['rows' => 1200]);
    Cinder::flush();

    expect($transport->sent)->toHaveCount(1)
        ->and($transport->sent[0]['url'])->toBe('https://cinder.test/api/v1/logs');
})->group('facade');

it('registers probes alongside the configured ones', function () {
    $before = count(Cinder::health()->probes());

    Cinder::registerProbe(new StubProbe(id: 'extra', observation: Observation::healthy('ok')));

    expect(Cinder::health()->probes())->toHaveCount($before + 1)
        ->and(array_map(fn ($probe) => $probe->id(), Cinder::health()->probes()))->toContain('extra');
})->group('facade');
