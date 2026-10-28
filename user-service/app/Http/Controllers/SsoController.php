<?php

namespace App\Http\Controllers;

use App\Models\ApiToken;
use App\Models\SsoTicket;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SsoController extends Controller
{
    public function authorize(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'client' => ['required', 'string', 'max:50'],
            'redirect' => ['nullable', 'string', 'max:500'],
        ]);

        /** @var User $user */
        $user = $request->attributes->get('user');
        /** @var ApiToken $token */
        $token = $request->attributes->get('api_token');

        if (! $token || $token->isExpired()) {
            return response()->json(['error' => 'Unauthenticated.'], 401);
        }

        $plainCode = Str::random(64);
        $codeHash = hash('sha256', $plainCode);

        SsoTicket::create([
            'code_hash' => $codeHash,
            'user_id' => $user->id,
            'token_id' => $token->id,
            'client' => $validated['client'],
            'expires_at' => now()->addSeconds(60),
        ]);

        Log::info('SSO ticket issued', [
            'user_id' => $user->id,
            'client' => $validated['client'],
        ]);

        return response()->json([
            'code' => $plainCode,
            'expires_in' => 60,
        ]);
    }

    public function exchange(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string'],
        ]);

        $codeHash = hash('sha256', $validated['code']);
        $ticket = SsoTicket::query()->where('code_hash', $codeHash)->first();

        if (! $ticket || ! $ticket->isValid()) {
            return response()->json(['error' => 'Invalid or expired code.'], 400);
        }

        $tokenModel = $ticket->token;

        if (! $tokenModel || $tokenModel->isExpired()) {
            $ticket->forceFill(['used_at' => now()])->save();

            return response()->json(['error' => 'Invalid or expired code.'], 400);
        }

        $ticket->forceFill(['used_at' => now()])->save();

        $deviceName = $ticket->client === 'billing' ? 'billing-sso' : $ticket->client.'-sso';

        $issued = ApiToken::issueFor(
            $ticket->user,
            $deviceName,
            now()->addMinutes((int) config('tokens.ttl_minutes')),
        );

        Log::info('SSO token exchanged', [
            'user_id' => $ticket->user_id,
            'client' => $ticket->client,
            'token_id' => $issued['model']->id,
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
