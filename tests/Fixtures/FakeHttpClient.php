<?php

namespace Cinderwatch\Laravel\Tests\Fixtures;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class FakeHttpClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var list<ResponseInterface> */
    private array $responses = [];

    public function addResponse(int $status, string $body = ''): self
    {
        $this->responses[] = new Response($status, [], $body);

        return $this;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        return array_shift($this->responses) ?? new Response(202);
    }

    public function lastRequest(): ?RequestInterface
    {
        return end($this->requests) ?: null;
    }
}
