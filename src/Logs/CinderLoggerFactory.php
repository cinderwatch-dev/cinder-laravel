<?php

namespace Cinderwatch\Laravel\Logs;

use Monolog\Logger;

class CinderLoggerFactory
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __invoke(array $config): Logger
    {
        $logger = new Logger('cinder');
        $logger->pushHandler(new CinderLogHandler($config['level'] ?? 'debug'));

        return $logger;
    }
}
