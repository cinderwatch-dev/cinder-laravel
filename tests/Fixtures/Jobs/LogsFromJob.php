<?php

namespace Cinderwatch\Laravel\Tests\Fixtures\Jobs;

use Cinderwatch\CinderClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class LogsFromJob implements ShouldQueue
{
    use Queueable;

    public function handle(CinderClient $client): void
    {
        $client->logs()->write('queue telemetry probe', 'info');
    }
}
