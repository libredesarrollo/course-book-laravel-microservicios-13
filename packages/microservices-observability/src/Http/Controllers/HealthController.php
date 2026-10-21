<?php

namespace Demo\Microservices\Observability\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * Health check del servicio.
 *
 * Vive en el paquete de observabilidad porque responde a la misma pregunta
 * en todos los microservicios: ¿estoy vivo? Si cada servicio escribiera el
 * suyo, acabarían respondiendo de forma distinta y el balanceador tendría
 * que conocer las particularidades de cada uno.
 *
 * El payload es estático a propósito: un health check no debe depender de
 * la base de datos, porque justo el caso interesante es el de la base de
 * datos caída y no puede ser un 500 sin distinguir nada. Si un servicio
 * necesita comprobar dependencias, puede extender este controller y añadir
 * sus propias comprobaciones.
 */
class HealthController extends Controller
{
    public function index(): JsonResponse
    {
        return new JsonResponse([
            'status' => 'ok',
            'service' => config('app.name'),
            'timestamp' => Carbon::now()->toIso8601String(),
        ]);
    }
}
