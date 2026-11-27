<?php

namespace Cinderwatch\Laravel\Commands;

use Cinderwatch\Laravel\Facades\Cinder;
use Exception;
use Illuminate\Console\Command;

class TestCommand extends Command
{
    protected $signature = 'cinder:test';

    protected $description = 'Send a test exception to Cinder';

    public function handle(): int
    {
        if (! config('cinder.key')) {
            $this->error('Cinder is not configured. Set CINDER_KEY.');

            return self::FAILURE;
        }

        $report = Cinder::report(new Exception('Cinder test exception from '.config('app.name')));

        if ($report === null) {
            $this->warn('The test exception was filtered and not sent.');

            return self::FAILURE;
        }

        $this->info('Test exception sent to Cinder.');

        return self::SUCCESS;
    }
}
