<?php

namespace Cinderwatch\Laravel\Logs;

use Cinderwatch\Laravel\Facades\Cinder;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;

class CinderLogHandler extends AbstractProcessingHandler
{
    public function __construct(int|string|Level $level = Level::Debug, bool $bubble = true)
    {
        parent::__construct($level, $bubble);
    }

    protected function write(LogRecord $record): void
    {
        if (! config('cinder.report_logs') || ! config('cinder.key')) {
            return;
        }

        Cinder::log(
            $record->message,
            $record->level->getName(),
            $record->context,
        );
    }
}
