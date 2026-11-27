<?php

namespace Cinderwatch\Laravel\Tracing;

use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;

class HttpClientTraceListener
{
    public function handleRequestSending(RequestSending $event): void
    {
        OutboundHttpSpans::begin($event->request, $event->request->url());
    }

    public function handleResponseReceived(ResponseReceived $event): void
    {
        OutboundHttpSpans::end($event->request, $event->request->url(), $event->request->method(), $event->response->status());
    }

    public function handleConnectionFailed(ConnectionFailed $event): void
    {
        OutboundHttpSpans::end($event->request, $event->request->url(), $event->request->method(), OutboundHttpSpans::NO_RESPONSE_STATUS);
    }
}
