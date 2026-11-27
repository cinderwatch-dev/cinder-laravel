<?php

namespace Cinderwatch\Laravel\Tracing;

use Cinderwatch\Laravel\Facades\Cinder;
use Cinderwatch\Support\TelemetryAttributeKeys;
use Cinderwatch\Support\Time;
use Cinderwatch\Tracing\SpanCategory;
use WeakMap;

use function Livewire\on;

/**
 * Spans are recorded once the component is destroyed rather than left open,
 * so a component that throws can never keep the request trace open.
 */
class LivewireTracer
{
    /** @var WeakMap<object, array{0: int, 1: string}> */
    private WeakMap $started;

    public function __construct()
    {
        $this->started = new WeakMap;
    }

    public static function livewireInstalled(): bool
    {
        return function_exists('Livewire\on') && class_exists('Livewire\EventBus');
    }

    public function register(): void
    {
        on('mount', fn (object $component) => $this->start($component, 'mount'));
        on('hydrate', fn (object $component) => $this->start($component, 'update'));
        on('destroy', fn (object $component) => $this->finish($component));
    }

    public function start(object $component, string $phase): void
    {
        if (! Cinder::tracer()->isRecording()) {
            return;
        }

        $this->started[$component] = [Time::unixNano(), $phase];
    }

    public function finish(object $component): void
    {
        if (! isset($this->started[$component])) {
            return;
        }

        [$startedAt, $phase] = $this->started[$component];
        unset($this->started[$component]);

        $name = method_exists($component, 'getName') ? (string) $component->getName() : $component::class;

        Cinder::tracer()->recordCompleted(
            'Livewire - '.$name,
            [
                TelemetryAttributeKeys::SpanKind => SpanCategory::Livewire->value,
                'livewire.component.name' => $name,
                'livewire.component.class' => $component::class,
                'livewire.component.id' => method_exists($component, 'getId') ? (string) $component->getId() : null,
                'livewire.lifecycle' => $phase,
            ],
            $startedAt,
            Time::unixNano(),
        );
    }
}
