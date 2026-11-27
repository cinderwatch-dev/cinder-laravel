<?php

namespace Cinderwatch\Laravel\Tracing;

use Saloon\Config;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Http\PendingRequest;
use Saloon\Http\Response;

class SaloonOutgoingHttpTracer
{
    private static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered || ! class_exists(Config::class)) {
            return;
        }

        self::$registered = true;

        Config::globalMiddleware()
            ->onRequest(function (PendingRequest $pending): void {
                OutboundHttpSpans::begin($pending, $pending->getUrl());
            }, name: 'cinder-outgoing-http-request')
            ->onResponse(function (Response $response): void {
                $pending = $response->getPendingRequest();

                OutboundHttpSpans::end($pending, $pending->getUrl(), $pending->getMethod()->value, $response->status());
            }, name: 'cinder-outgoing-http-response')
            ->onFatalException(function (FatalRequestException $exception): void {
                $pending = $exception->getPendingRequest();

                OutboundHttpSpans::end($pending, $pending->getUrl(), $pending->getMethod()->value, OutboundHttpSpans::NO_RESPONSE_STATUS);
            }, name: 'cinder-outgoing-http-fatal');
    }
}
