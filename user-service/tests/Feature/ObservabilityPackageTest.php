<?php

use Demo\Microservices\Observability\Http\Middleware\AttachLogContext;
use Demo\Microservices\Observability\Http\Middleware\EnsureRequestId;
use Demo\Microservices\Observability\MicroservicesObservabilityServiceProvider;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Log;

/*
| El paquete de observabilidad se consume por alias ('request.id',
| 'log.context') en lugar de por clase. Estos tests fijan ese contrato: si
| el provider deja de registrar los alias, la app que lo consume deja de
| tenerlos y el fallo aparece aquí y no en producción.
*/

it('registers the middleware aliases owned by the package', function () {
    $aliases = app('router')->getMiddleware();

    expect($aliases)->toHaveKey(MicroservicesObservabilityServiceProvider::ALIAS_REQUEST_ID)
        ->and($aliases[MicroservicesObservabilityServiceProvider::ALIAS_REQUEST_ID])->toBe(EnsureRequestId::class)
        ->and($aliases)->toHaveKey(MicroservicesObservabilityServiceProvider::ALIAS_LOG_CONTEXT)
        ->and($aliases[MicroservicesObservabilityServiceProvider::ALIAS_LOG_CONTEXT])->toBe(AttachLogContext::class);
});

it('applies the correlation id middleware to the api group', function () {
    // Los grupos se sincronizan al Router cuando se resuelve el kernel HTTP.
    app(Kernel::class);

    $groups = app('router')->getMiddlewareGroups();

    expect($groups['api'])->toContain('request.id')
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
