<?php

namespace App\Services;

use App\Models\SsoTicket;
use App\Models\User;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SsoTicketIssuer
{
    public function issue(User $user, string $client, string $redirectUri): string
    {
        $clients = config('sso.clients', []);

        if (! is_array($clients) || ! array_key_exists($client, $clients)) {
            throw new HttpException(400, 'Cliente SSO no autorizado.');
        }

        $allowed = $clients[$client];

        if (is_string($allowed)) {
            $allowed = [$allowed];
        }

        if (! is_array($allowed) || ! in_array($redirectUri, $allowed, true)) {
            throw new HttpException(400, 'redirect_uri no autorizado para este cliente.');
        }

        SsoTicket::query()
            ->where('user_id', $user->id)
            ->where('client', $client)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->delete();

        $code = Str::random(64);

        SsoTicket::query()->create([
            'code_hash' => hash('sha256', $code),
            'user_id' => $user->id,
            'client' => $client,
            'expires_at' => now()->addSeconds((int) config('sso.ticket_ttl', 60)),
            'used_at' => null,
        ]);

        return $code;
    }
}
