<?php

use Cinderwatch\CinderClient;
use Cinderwatch\Config\CinderConfig;
use Cinderwatch\Laravel\CinderServiceProvider;
use Cinderwatch\Laravel\Errors\RequestContextEnricher;
use Cinderwatch\Laravel\Facades\Cinder;
use Cinderwatch\Support\DeployedRevision;
use Cinderwatch\Transport\ArrayTransport;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Support\Facades\Route;

function runningOverHttp(): void
{
    (fn () => $this->isRunningInConsole = false)->call(app());
}

it('reports exceptions through the cinder client', function () {
    $transport = new ArrayTransport;
    $this->bindClient($transport);

    Cinder::report(new RuntimeException('laravel boom'));

    $payload = $transport->sent[0]['payload'];

    expect($transport->sent)->toHaveCount(1)
        ->and($payload['exception']['message'])->toBe('laravel boom')
        ->and($payload['exception']['type'])->toBe(RuntimeException::class)
        ->and($payload['sdk'])->toBe(['name' => 'cinderwatch/cinder-laravel', 'version' => CinderServiceProvider::SDK_VERSION])
        ->and($payload['runtime']['root_path'])->toBe(base_path())
        ->and($payload['context']['cinder.framework.name'])->toBe('laravel')
        ->and($payload['context']['cinder.framework.version'])->toBe(app()->version());
})->group('errors');

it('reports the laravel package as the telemetry sdk', function () {
    expect(app(CinderConfig::class)->resourceAttributes())->toMatchArray([
        'telemetry.sdk.name' => 'cinderwatch/cinder-laravel',
        'telemetry.sdk.version' => CinderServiceProvider::SDK_VERSION,
        'cinder.framework.name' => 'laravel',
    ]);
})->group('errors');

it('builds the client from the cinder config', function () {
    $config = Cinder::client()->config();

    expect(Cinder::client())->toBe(app(CinderClient::class))
        ->and($config->key)->toBe('test-key')
        ->and($config->baseUrl)->toBe('https://cinder.test');
})->group('errors');

it('only reports exceptions the predicate lets through', function () {
    $transport = new ArrayTransport;
    $this->bindClient($transport);

    Cinder::reportOnlyWhen(fn (Throwable $e) => ! $e instanceof LogicException);

    expect(Cinder::report(new LogicException('ignored')))->toBeNull()
        ->and(Cinder::report(new RuntimeException('kept')))->not->toBeNull()
        ->and($transport->sent)->toHaveCount(1);
})->group('errors');

it('groups reports by a custom fingerprint', function () {
    $transport = new ArrayTransport;
    $this->bindClient($transport);

    Cinder::fingerprintUsing(fn (Throwable $e) => $e instanceof LogicException ? ['logic'] : null);

    Cinder::report(new LogicException('one'));
    Cinder::report(new RuntimeException('two'), fingerprint: ['checkout', 'declined']);

    expect($transport->sent[0]['payload']['fingerprint'])->toBe(['logic'])
        ->and($transport->sent[1]['payload']['fingerprint'])->toBe(['checkout', 'declined']);
})->group('errors');

it('registers a reporting callback on the exceptions configuration', function () {
    $transport = new ArrayTransport;
    $this->bindClient($transport);

    $handler = app(ExceptionHandler::class);
    Cinder::reportExceptions(new Exceptions($handler));

    $handler->report(new RuntimeException('via handler'));

    expect($transport->sent)->toHaveCount(1)
        ->and($transport->sent[0]['payload']['exception']['message'])->toBe('via handler');
})->group('errors');

it('adds request and route context to errors raised during a web request', function () {
    $transport = new ArrayTransport;
    $this->bindClient($transport)->addEnricher(app(RequestContextEnricher::class));
    runningOverHttp();

    Route::post('/orders/{order}', function () {
        Cinder::report(new RuntimeException('order failed'));

        return 'ok';
    });

    $this->post('/orders/42')->assertOk();

    $context = $transport->sent[0]['payload']['context'];

    expect($context['http.request.method'])->toBe('POST')
        ->and($context['url.path'])->toBe('/orders/42')
        ->and($context['cinder.entry.kind'])->toBe('web')
        ->and($context['cinder.entry.name'])->toBe('/orders/42')
        ->and($context['cinder.entry.route'])->toBe('/orders/{order}')
        ->and($context['cinder.entry.handler'])->toBe('Closure')
        ->and($context)->not->toHaveKey('user.id');
})->group('errors');

it('adds the signed in user to errors raised during a web request', function () {
    runningOverHttp();
    $this->actingAs(new GenericUser(['id' => 7, 'email' => 'jane@example.com']));

    $attributes = app(RequestContextEnricher::class)->attributesFor(new RuntimeException);

    expect($attributes['user.id'])->toBe('7')
        ->and($attributes['user.email'])->toBe('jane@example.com');
})->group('errors');

it('leaves the email out when the user has none', function () {
    runningOverHttp();
    $this->actingAs(new GenericUser(['id' => 7]));

    expect(app(RequestContextEnricher::class)->attributesFor(new RuntimeException))
        ->toMatchArray(['user.id' => '7', 'user.email' => null]);
})->group('errors');

it('skips the user when the auth guard cannot be resolved', function () {
    runningOverHttp();
    config(['auth.defaults.guard' => 'missing']);

    expect(app(RequestContextEnricher::class)->attributesFor(new RuntimeException))
        ->toHaveKey('url.path')
        ->not->toHaveKey('user.id');
})->group('errors');

it('adds no request context to console errors', function () {
    expect(app(RequestContextEnricher::class)->attributesFor(new RuntimeException))->toBe([]);
})->group('errors');

it('uses the configured deployed commit over the detected one', function () {
    $this->rebootWith([
        'cinder.vcs.revision' => 'deadbeef',
        'cinder.vcs.branch' => 'release',
        'cinder.vcs.message' => 'Ship it',
    ]);

    $config = app(CinderConfig::class);

    expect([$config->vcsRevision, $config->vcsBranch, $config->vcsMessage])->toBe(['deadbeef', 'release', 'Ship it']);
})->group('errors');

it('detects the deployed commit unless detection is turned off', function () {
    $detected = DeployedRevision::resolve(base_path());

    expect(app(CinderConfig::class)->vcsRevision)->toBe($detected->revision);

    $this->rebootWith(['cinder.vcs.detect' => false]);

    expect(app(CinderConfig::class)->vcsRevision)->toBeNull();
})->group('errors');
