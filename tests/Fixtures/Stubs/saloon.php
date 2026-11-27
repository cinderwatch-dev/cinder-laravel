<?php

/*
 * A minimal stand-in for the parts of saloonphp/saloon the tracer touches,
 * so it can be tested (and analysed) without installing Saloon.
 */

namespace Saloon\Enums {
    if (! enum_exists(Method::class)) {
        enum Method: string
        {
            case GET = 'GET';
            case POST = 'POST';
        }
    }
}

namespace Saloon\Http {
    use Saloon\Enums\Method;

    if (! class_exists(PendingRequest::class)) {
        class PendingRequest
        {
            public function __construct(
                private string $url,
                private Method $method = Method::GET,
            ) {}

            public function getUrl(): string
            {
                return $this->url;
            }

            public function getMethod(): Method
            {
                return $this->method;
            }
        }
    }

    if (! class_exists(Response::class)) {
        class Response
        {
            public function __construct(
                private PendingRequest $pendingRequest,
                private int $status,
            ) {}

            public function getPendingRequest(): PendingRequest
            {
                return $this->pendingRequest;
            }

            public function status(): int
            {
                return $this->status;
            }
        }
    }
}

namespace Saloon\Exceptions\Request {
    use Exception;
    use Saloon\Http\PendingRequest;

    if (! class_exists(FatalRequestException::class)) {
        class FatalRequestException extends Exception
        {
            public function __construct(private PendingRequest $pendingRequest)
            {
                parent::__construct('Connection refused');
            }

            public function getPendingRequest(): PendingRequest
            {
                return $this->pendingRequest;
            }
        }
    }
}

namespace Saloon\Helpers {
    if (! class_exists(MiddlewarePipeline::class)) {
        class MiddlewarePipeline
        {
            /** @var array<string, callable> */
            public array $onRequest = [];

            /** @var array<string, callable> */
            public array $onResponse = [];

            /** @var array<string, callable> */
            public array $onFatalException = [];

            public function onRequest(callable $callable, ?string $name = null): static
            {
                $this->onRequest[(string) $name] = $callable;

                return $this;
            }

            public function onResponse(callable $callable, ?string $name = null): static
            {
                $this->onResponse[(string) $name] = $callable;

                return $this;
            }

            public function onFatalException(callable $callable, ?string $name = null): static
            {
                $this->onFatalException[(string) $name] = $callable;

                return $this;
            }
        }
    }
}

namespace Saloon {
    use Saloon\Helpers\MiddlewarePipeline;

    if (! class_exists(Config::class)) {
        class Config
        {
            private static ?MiddlewarePipeline $globalMiddleware = null;

            public static function globalMiddleware(): MiddlewarePipeline
            {
                return self::$globalMiddleware ??= new MiddlewarePipeline;
            }

            public static function resetMiddleware(): void
            {
                self::$globalMiddleware = new MiddlewarePipeline;
            }
        }
    }
}
