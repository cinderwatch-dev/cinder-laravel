<?php

namespace Cinderwatch\Laravel\Tests\Fixtures\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class IgnoredJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        //
    }
}
