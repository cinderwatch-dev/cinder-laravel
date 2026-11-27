<?php

use Cinderwatch\CinderClient;
use Cinderwatch\Laravel\Tests\Fixtures\ResyncsKernelMiddlewareProvider;
use Cinderwatch\Laravel\Tracing\TracedViewEngine;
use Cinderwatch\Laravel\Tracing\TraceRequests;
use Cinderwatch\Transport\ArrayTransport;
use Illuminate\Container\Container;
use Illuminate\Contracts\View\Engine;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\View\Engines\EngineResolver;

function viewFixtures(string $namespace = ''): string
{
    $directory = sys_get_temp_dir().'/cinder-views-'.bin2hex(random_bytes(4));

    mkdir($directory.'/orders', 0777, true);
    mkdir($directory.'/partials');
    file_put_contents($directory.'/orders/show.blade.php', 'Order @include(\''.$namespace.'partials.row\')');
    file_put_contents($directory.'/partials/row.blade.php', 'row');

    return $directory;
}

function plainEngine(): Engine
{
    return new class implements Engine
    {
        public function get($path, array $data = []): string
        {
            return 'rendered';
        }
    };
}

beforeEach(function () {
    $this->transport = new ArrayTransport;
    $this->rebootWith(['cinder.report_traces' => true, 'cinder.trace_sample_rate' => 1.0]);
    $this->client = $this->traceInto($this->transport);
});

it('keeps request tracing when another provider re-syncs the kernel middleware while booting', function () {
    $this->rebootWith(['cinder.report_traces' => true], [ResyncsKernelMiddlewareProvider::class]);
    $client = $this->traceInto($this->transport);

    expect(app('router')->getMiddlewareGroups()['web'])->toContain(TraceRequests::class);

    Route::middleware('web')->get('/dashboard', fn () => 'ok');

    $this->get('/dashboard')->assertOk();
    $client->flush();

    expect($this->spanAttribute($this->spanOfKind($this->transport, 'route'), 'cinder.entry.route'))->toBe('/dashboard');
})->group('tracing');

it('records rendered views as nested spans named after the view', function () {
    View::addLocation(viewFixtures());

    $root = $this->client->tracer()->open('Request - /orders');
    $html = view('orders.show')->render();
    $this->client->tracer()->close($root);
    $this->client->flush();

    $spans = collect($this->sentSpans($this->transport));
    $page = $spans->first(fn (array $span) => $this->spanAttribute($span, 'view.name') === 'orders.show');
    $partial = $spans->first(fn (array $span) => $this->spanAttribute($span, 'view.name') === 'partials.row');

    expect($html)->toBe('Order row')
        ->and($page['name'])->toBe('View - orders.show')
        ->and($this->spanAttribute($page, 'cinder.span.kind'))->toBe('view')
        ->and($this->spanAttribute($page, 'view.file'))->toEndWith('orders/show.blade.php')
        ->and($partial['parentSpanId'])->toBe($page['spanId']);
})->group('tracing');

it('names namespaced views with their namespace', function () {
    View::addNamespace('shop', viewFixtures('shop::'));

    $root = $this->client->tracer()->open('Request - /orders');
    view('shop::orders.show')->render();
    view('shop::orders.show')->render();
    $this->client->tracer()->close($root);
    $this->client->flush();

    expect(collect($this->sentSpans($this->transport))->pluck('name')->countBy()->all())->toMatchArray([
        'View - shop::orders.show' => 2,
        'View - shop::partials.row' => 2,
    ]);
})->group('tracing');

it('falls back to the file name for views outside the view paths', function () {
    $file = sys_get_temp_dir().'/cinder-loose-'.bin2hex(random_bytes(4)).'.txt';
    $engine = new TracedViewEngine(plainEngine(), app());

    $root = $this->client->tracer()->open('Request - /');
    $engine->get($file);
    $this->client->tracer()->close($root);
    $this->client->flush();

    expect(collect($this->sentSpans($this->transport))->pluck('name'))->toContain('View - '.basename($file));
})->group('tracing');

it('falls back to the file name when the view factory has no file finder', function () {
    $container = new Container;
    $container->instance(CinderClient::class, $this->client);
    $container->instance('view', new stdClass);
    $engine = new TracedViewEngine(plainEngine(), $container);

    $root = $this->client->tracer()->open('Request - /');
    $engine->get('/views/emails/welcome.blade.php');
    $this->client->tracer()->close($root);
    $this->client->flush();

    expect(collect($this->sentSpans($this->transport))->pluck('name'))->toContain('View - welcome.blade.php');
})->group('tracing');

it('renders views without recording spans outside a sampled trace', function () {
    View::addLocation(viewFixtures());

    expect(view('orders.show')->render())->toBe('Order row');

    $this->client->flush();

    expect($this->sentSpans($this->transport))->toBeEmpty();
})->group('tracing');

it('only wraps registered engines that are not wrapped yet', function () {
    $resolver = new EngineResolver;
    $resolver->register('php', fn () => plainEngine());

    TracedViewEngine::decorate($resolver, app());
    $wrapped = $resolver->resolve('php');
    TracedViewEngine::decorate($resolver, app());

    expect($resolver->resolve('php'))->toBe($wrapped)->toBeInstanceOf(TracedViewEngine::class)
        ->and(fn () => $resolver->resolve('blade'))->toThrow(InvalidArgumentException::class);
})->group('tracing');

it('leaves the view engines alone when view tracing is turned off', function () {
    $this->rebootWith(['cinder.report_traces' => true, 'cinder.trace.views' => false]);

    expect(app('view.engine.resolver')->resolve('blade'))->not->toBeInstanceOf(TracedViewEngine::class);
})->group('tracing');

it('still hands the blade compiler to code that asks the engine for it', function () {
    $engine = app('view.engine.resolver')->resolve('blade');

    expect($engine)->toBeInstanceOf(TracedViewEngine::class)
        ->and($engine->getCompiler())->toBe(app('blade.compiler'));
})->group('tracing');
