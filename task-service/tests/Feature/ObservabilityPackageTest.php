<?php

use Demo\Microservices\Observability\Http\Middleware\AttachLogContext;
use Demo\Microservices\Observability\Http\Middleware\EnsureRequestId;
use Demo\Microservices\Observability\MicroservicesObservabilityServiceProvider;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Log;

/*
| El task-service consume el mismo paquete de observabilidad que el
| user-service. Estos tests comprueban que el contrato del paquete (los alias)
| se cumple igual aquí, y que el middleware se aplica tanto al grupo web
| (UI) como al api.
*/

it('registers the middleware aliases owned by the package', function () {
    $aliases = app('router')->getMiddleware();

    expect($aliases)->toHaveKey(MicroservicesObservabilityServiceProvider::ALIAS_REQUEST_ID)
        ->and($aliases[MicroservicesObservabilityServiceProvider::ALIAS_REQUEST_ID])->toBe(EnsureRequestId::class)
        ->and($aliases)->toHaveKey(MicroservicesObservabilityServiceProvider::ALIAS_LOG_CONTEXT)
        ->and($aliases[MicroservicesObservabilityServiceProvider::ALIAS_LOG_CONTEXT])->toBe(AttachLogContext::class);
});

it('applies the correlation id middleware to the web and api groups', function () {
    // Los grupos se sincronizan al Router cuando se resuelve el kernel HTTP.
    app(Kernel::class);

    $groups = app('router')->getMiddlewareGroups();

    expect($groups['web'])->toContain('request.id')
        ->and($groups['web'])->toContain('log.context')
        ->and($groups['api'])->toContain('request.id')
        ->and($groups['api'])->toContain('log.context');
});

it('returns a request id in the response headers', function () {
    $response = $this->getJson('/api/health');

    $response->assertOk()->assertHeader('X-Request-Id');
});

it('keeps the request id sent by the caller', function () {
    $response = $this->withHeader('X-Request-Id', 'traza-123')->getJson('/api/health');

    $response->assertOk()->assertHeader('X-Request-Id', 'traza-123');
});

it('adds the request id to the log context', function () {
    Log::spy();

    $this->getJson('/api/health');

    Log::shouldHaveReceived('withContext')
        ->withArgs(fn (array $context) => ($context['request_id'] ?? null) !== null
            && ($context['service'] ?? null) === config('app.name')
            && array_key_exists('ip', $context)
            && array_key_exists('method', $context)
            && array_key_exists('path', $context));
});

it('answers the health check with the same payload in every service', function () {
    $this->getJson('/api/health')
        ->assertOk()
        ->assertJson([
            'status' => 'ok',
            'service' => config('app.name'),
        ])
        ->assertJsonStructure(['status', 'service', 'timestamp']);
});
