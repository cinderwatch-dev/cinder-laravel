<?php

namespace Cinderwatch\Laravel\Tests\Fixtures;

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Support\ServiceProvider;

/**
 * Stands in for Sanctum and Filament, which re-sync the HTTP kernel's
 * middleware groups onto the router while they boot.
 */
class ResyncsKernelMiddlewareProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->make(HttpKernel::class)->prependToMiddlewarePriority(self::class);
    }
}
