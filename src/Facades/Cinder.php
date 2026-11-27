<?php

namespace Cinderwatch\Laravel\Facades;

use Cinderwatch\CinderClient;
use Cinderwatch\Health\Inspector;
use Cinderwatch\Laravel\CinderManager;
use Cinderwatch\Tracing\Tracer;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Support\Facades\Facade;
use Throwable;

/**
 * @method static CinderClient client()
 * @method static Inspector health()
 * @method static Tracer tracer()
 * @method static array<string, mixed>|null report(\Throwable $throwable, array<string, mixed> $context = [], ?bool $handled = null, list<string>|null $fingerprint = null)
 * @method static void reportOnlyWhen(\Closure $predicate)
 * @method static void fingerprintUsing(\Closure $resolver)
 * @method static void beforeSend(\Closure $filter)
 * @method static void addEnricher(\Cinderwatch\Errors\ReportEnricher $enricher)
 * @method static mixed trace(string $name, \Closure $callback, array<string, mixed> $attributes = [])
 * @method static void log(string $message, string $level = 'info', array<string, mixed> $context = [])
 * @method static void registerProbe(\Cinderwatch\Health\Probe ...$probes)
 * @method static void probes(iterable<\Cinderwatch\Health\Probe> $probes)
 * @method static void flush()
 *
 * @see CinderManager
 */
class Cinder extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return CinderManager::class;
    }

    /**
     * Call from `withExceptions()` in `bootstrap/app.php`.
     */
    public static function reportExceptions(Exceptions $exceptions): void
    {
        $exceptions->report(function (Throwable $exception): void {
            static::report($exception);
        });
    }
}
