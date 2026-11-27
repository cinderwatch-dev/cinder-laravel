<?php

namespace Cinderwatch\Laravel\Tracing;

class TraceIgnore
{
    public static function shouldIgnoreJob(string $class): bool
    {
        return in_array($class, config('cinder.trace.ignore_jobs', []), true);
    }

    public static function shouldIgnoreCommand(?string $command): bool
    {
        if ($command === null || $command === '') {
            return true;
        }

        foreach (config('cinder.trace.ignore_commands', []) as $pattern) {
            if ($pattern === $command) {
                return true;
            }

            if (str_ends_with((string) $pattern, '*') && str_starts_with($command, rtrim((string) $pattern, '*'))) {
                return true;
            }
        }

        return false;
    }
}
