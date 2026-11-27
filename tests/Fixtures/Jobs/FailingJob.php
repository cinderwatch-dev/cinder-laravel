<?php

namespace Cinderwatch\Laravel\Tests\Fixtures\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

class FailingJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        throw new RuntimeException('Job failed');
    }
}
