<?php

namespace Cinderwatch\Laravel\Tracing;

use Cinderwatch\Laravel\Facades\Cinder;
use Cinderwatch\Support\TelemetryAttributeKeys;
use Cinderwatch\Tracing\SpanCategory;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\View\Engine;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\Factory;
use Illuminate\View\FileViewFinder;
use InvalidArgumentException;

/**
 * The wrapped engine must stay in `$engine`: Laravel's exception renderer
 * reads that property to reach the compiler engine behind a decorator.
 */
class TracedViewEngine implements Engine
{
    private const ENGINES = ['file', 'php', 'blade'];

    /** @var array<string, string> */
    private static array $names = [];

    public function __construct(
        protected Engine $engine,
        private readonly Container $container,
    ) {}

    public static function decorate(EngineResolver $resolver, Container $container): void
    {
        foreach (self::ENGINES as $name) {
            try {
                $engine = $resolver->resolve($name);
            } catch (InvalidArgumentException) {
                continue;
            }

            if ($engine instanceof self) {
                continue;
            }

            $traced = new self($engine, $container);
            $resolver->register($name, fn (): self => $traced);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function get($path, array $data = []): string
    {
        $tracer = Cinder::tracer();

        if (! $tracer->isRecording()) {
            return $this->engine->get($path, $data);
        }

        $name = $this->viewName($path);
        $span = $tracer->open('View - '.$name, [
            TelemetryAttributeKeys::SpanKind => SpanCategory::View->value,
            'view.name' => $name,
            'view.file' => $path,
        ]);

        try {
            return $this->engine->get($path, $data);
        } finally {
            $tracer->close($span);
        }
    }

    /**
     * Passes `getCompiler()` and friends through to the real engine.
     *
     * @param  array<int, mixed>  $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        return $this->engine->{$method}(...$arguments);
    }

    /**
     * Works out `orders.show` or `mail::message` from the finder's paths and namespaces.
     */
    private function viewName(string $path): string
    {
        if (isset(self::$names[$path])) {
            return self::$names[$path];
        }

        $finder = $this->finder();

        if ($finder === null) {
            return self::$names[$path] = basename($path);
        }

        $roots = array_map(fn (string $root): array => [null, $root], $finder->getPaths());

        foreach ($finder->getHints() as $namespace => $hintedRoots) {
            foreach ((array) $hintedRoots as $root) {
                $roots[] = [$namespace, $root];
            }
        }

        $name = null;
        $file = (string) realpath($path) ?: $path;

        foreach ($roots as [$namespace, $root]) {
            $root = rtrim((string) realpath($root) ?: $root, '/\\').DIRECTORY_SEPARATOR;

            if (! str_starts_with($file, $root)) {
                continue;
            }

            $relative = str_replace(['/', '\\'], '.', $this->withoutExtension($finder, substr($file, strlen($root))));
            $name = $namespace === null ? $relative : $namespace.'::'.$relative;

            break;
        }

        return self::$names[$path] = $name ?? $this->withoutExtension($finder, basename($path));
    }

    private function finder(): ?FileViewFinder
    {
        $factory = $this->container->make('view');
        $finder = $factory instanceof Factory ? $factory->getFinder() : null;

        return $finder instanceof FileViewFinder ? $finder : null;
    }

    private function withoutExtension(FileViewFinder $finder, string $file): string
    {
        foreach ($finder->getExtensions() as $extension) {
            if (str_ends_with($file, '.'.$extension)) {
                return substr($file, 0, -strlen($extension) - 1);
            }
        }

        return $file;
    }
}
