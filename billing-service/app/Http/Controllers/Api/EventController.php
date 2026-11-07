<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\CreateInvoiceFromEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * El receptor del webhook de eventos.
 *
 * Le llega lo que el task-service emite por su outbox. El middleware
 * `events.verify` ya verificó firma, frescura y anti-replay; este controller
 * hace UNA cosa: encolar el trabajo y responder ya. La factura puede
 * tardar; el 202 no.
 *
 * Los tipos que este service (como subscriptor) no registró se ACK-ean
 * igual con 204: el emisor no debe quedarse reintentando a mitad de un
 * evento que su suscriptor jamás pidió.
 */
class EventController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $eventId = (string) $request->attributes->get('event_id');
        $type = (string) $request->attributes->get('event_type');
        $payload = (array) $request->attributes->get('event_payload');

        Log::info('Evento recibido', [
            'event_id' => $eventId,
            'type' => $type,
        ]);

        if ($type === 'task.completed') {
            CreateInvoiceFromEvent::dispatch($eventId, $payload)->onQueue('invoices');
        }

        return new JsonResponse([
            'received' => true,
            'event_id' => $eventId,
        ], $type === 'task.completed' ? Response::HTTP_ACCEPTED : Response::HTTP_NO_CONTENT);
    }
}
