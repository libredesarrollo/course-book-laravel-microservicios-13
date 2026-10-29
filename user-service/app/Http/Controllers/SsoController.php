<?php

namespace App\Http\Controllers;

use App\Models\ApiToken;
use App\Models\SsoTicket;
use App\Services\SsoTicketIssuer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class SsoController extends Controller
{
    public function authorize(Request $request, SsoTicketIssuer $issuer): RedirectResponse|View
    {
        if (! Auth::check()) {
            return redirect()->route('login', $request->only(['client', 'redirect_uri']));
        }

        $validated = $request->validate([
            'client' => ['required', 'string'],
            'redirect_uri' => ['required', 'string'],
        ]);

        $user = $request->user();
        $code = $issuer->issue($user, $validated['client'], $validated['redirect_uri']);

        $separator = str_contains($validated['redirect_uri'], '?') ? '&' : '?';

        Log::info('idp.sso.authorized', [
            'user_id' => $user->id,
            'client' => $validated['client'],
            'request_id' => $request->attributes->get('request_id'),
        ]);

        return redirect()->away($validated['redirect_uri'].$separator.'code='.urlencode($code));
    }

    public function exchange(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string'],
            'client' => ['required', 'string'],
        ]);

        $ticket = SsoTicket::query()
            ->with('user')
            ->where('code_hash', hash('sha256', $validated['code']))
            ->first();

        if (! $ticket || ! $ticket->isValid()) {
            return response()->json(['error' => 'Invalid or expired SSO code.'], 400);
        }

        if ($ticket->client !== $validated['client']) {
            return response()->json(['error' => 'Invalid SSO code client.'], 400);
        }

        $deviceName = $ticket->client.'-sso';

        $issued = ApiToken::issueFor(
            $ticket->user,
            $deviceName,
            now()->addMinutes((int) config('tokens.ttl_minutes')),
        );

        $ticket->update(['used_at' => now()]);

        Log::info('idp.sso.exchanged', [
            'user_id' => $ticket->user_id,
            'client' => $ticket->client,
            'token_id' => $issued['model']->id,
            'request_id' => $request->attributes->get('request_id'),
        ]);

        return response()->json([
            'token' => $issued['token'],
            'token_type' => 'Bearer',
            'expires_at' => $issued['model']->expires_at?->toIso8601String(),
            'user' => [
                'id' => $ticket->user->id,
                'name' => $ticket->user->name,
                'email' => $ticket->user->email,
                'created_at' => $ticket->user->created_at?->toIso8601String(),
                'updated_at' => $ticket->user->updated_at?->toIso8601String(),
            ],
        ], 201);
    }
}
