<?php

use Cinderwatch\Laravel\Tests\Fixtures\LivewireComponent;
use Cinderwatch\Laravel\Tracing\LivewireTracer;
use Cinderwatch\Transport\ArrayTransport;
use Livewire\EventBus;

require_once __DIR__.'/../Fixtures/Stubs/livewire.php';

beforeEach(function () {
    EventBus::$listeners = [];

    $this->transport = new ArrayTransport;
    $this->rebootWith(['cinder.report_traces' => true]);
    $this->client = $this->traceInto($this->transport);
});

it('listens to the livewire lifecycle when livewire is installed', function () {
    expect(LivewireTracer::livewireInstalled())->toBeTrue()
        ->and(array_keys(EventBus::$listeners))->toBe(['mount', 'hydrate', 'destroy']);
})->group('tracing');

it('does not listen to livewire when livewire tracing is turned off', function () {
    EventBus::$listeners = [];

    $this->rebootWith(['cinder.report_traces' => true, 'cinder.trace.livewire' => false]);

    expect(EventBus::$listeners)->toBe([]);
})->group('tracing');

it('records a livewire component from mount until it is destroyed', function () {
    $component = new LivewireComponent;

    $root = $this->client->tracer()->open('Request - /orders');
    EventBus::trigger('mount', $component);
    EventBus::trigger('destroy', $component);
    $this->client->tracer()->close($root);
    $this->client->flush();

    $span = $this->spanOfKind($this->transport, 'livewire');

    expect($span['name'])->toBe('Livewire - orders.table')
        ->and($span['parentSpanId'])->toBe($root->spanId)
        ->and($this->spanAttribute($span, 'livewire.component.name'))->toBe('orders.table')
        ->and($this->spanAttribute($span, 'livewire.component.class'))->toBe(LivewireComponent::class)
        ->and($this->spanAttribute($span, 'livewire.component.id'))->toBe('abc123')
        ->and($this->spanAttribute($span, 'livewire.lifecycle'))->toBe('mount');
})->group('tracing');

it('records a hydrated component as an update', function () {
    $component = new LivewireComponent;

    $root = $this->client->tracer()->open('Request - /livewire/update');
    EventBus::trigger('hydrate', $component);
    EventBus::trigger('destroy', $component);
    $this->client->tracer()->close($root);
    $this->client->flush();

    expect($this->spanAttribute($this->spanOfKind($this->transport, 'livewire'), 'livewire.lifecycle'))->toBe('update');
})->group('tracing');

it('names components without livewire helpers after their class', function () {
    $component = new stdClass;

    $root = $this->client->tracer()->open('Request - /');
    EventBus::trigger('mount', $component);
    EventBus::trigger('destroy', $component);
    $this->client->tracer()->close($root);
    $this->client->flush();

    $span = $this->spanOfKind($this->transport, 'livewire');

    expect($span['name'])->toBe('Livewire - stdClass')
        ->and($this->spanAttribute($span, 'livewire.component.id'))->toBeNull();
})->group('tracing');

it('does not record components outside a sampled trace', function () {
    $component = new LivewireComponent;

    EventBus::trigger('mount', $component);
    EventBus::trigger('destroy', $component);
    $this->client->flush();

    expect($this->sentSpans($this->transport))->toBeEmpty();
})->group('tracing');

it('does not record a component that was never started', function () {
    $root = $this->client->tracer()->open('Request - /');
    EventBus::trigger('destroy', new LivewireComponent);
    $this->client->tracer()->close($root);
    $this->client->flush();

    expect(collect($this->sentSpans($this->transport))->pluck('name')->all())->toBe(['Request - /']);
})->group('tracing');
