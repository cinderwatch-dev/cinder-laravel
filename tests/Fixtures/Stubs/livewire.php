<?php

/*
 * A minimal stand-in for Livewire's event bus, so the tracer can be tested
 * (and analysed) without installing Livewire.
 */

namespace Livewire {
    if (! class_exists(EventBus::class)) {
        class EventBus
        {
            /** @var array<string, list<callable>> */
            public static array $listeners = [];

            public static function trigger(string $name, object $component): void
            {
                foreach (self::$listeners[$name] ?? [] as $listener) {
                    $listener($component);
                }
            }
        }
    }

    if (! function_exists('Livewire\on')) {
        function on(string $name, callable $callback): void
        {
            EventBus::$listeners[$name][] = $callback;
        }
    }
}
