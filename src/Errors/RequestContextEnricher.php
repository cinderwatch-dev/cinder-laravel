<?php

namespace Cinderwatch\Laravel\Errors;

use Cinderwatch\Errors\ReportEnricher;
use Cinderwatch\Support\TelemetryAttributeKeys;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Throwable;

class RequestContextEnricher implements ReportEnricher
{
    public function __construct(
        private readonly Application $app,
    ) {}

    public function attributesFor(Throwable $throwable): array
    {
        if ($this->app->runningInConsole() || ! $this->app->bound('request')) {
            return [];
        }

        /** @var Request $request */
        $request = $this->app->make('request');
        $route = $request->route();

        return [
            'http.request.method' => $request->method(),
            'url.full' => $request->fullUrl(),
            'url.path' => '/'.ltrim($request->path(), '/'),
            'user_agent.original' => (string) $request->userAgent(),
            TelemetryAttributeKeys::EntryKind => 'web',
            TelemetryAttributeKeys::EntryName => '/'.ltrim($request->path(), '/'),
            ...($route instanceof Route ? $this->routeAttributes($route) : []),
            ...$this->userAttributes(),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function routeAttributes(Route $route): array
    {
        return [
            TelemetryAttributeKeys::EntryHandler => $route->getActionName(),
            TelemetryAttributeKeys::EntryRoute => '/'.ltrim($route->uri(), '/'),
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function userAttributes(): array
    {
        try {
            $user = $this->app->make(AuthFactory::class)->guard()->user();
        } catch (Throwable) {
            return [];
        }

        if ($user === null) {
            return [];
        }

        $email = data_get($user, 'email');

        return [
            'user.id' => (string) $user->getAuthIdentifier(),
            'user.email' => is_string($email) ? $email : null,
        ];
    }
}
