<?php

namespace App\Console\Commands;

use App\Services\Jwt\JwtKeys;
use Illuminate\Console\Command;

/**
 * Genera el par de claves RS256 con el que el IdP firma los JWT.
 *
 * La primera vez que arrancas el modo JWT hay que correrlo: sin claves, el
 * emisor muere con un error claro en vez de entregar tokens sin firma.
 * Con --force regenera el par, lo que invalida todos los JWT en circulación
 * (los RP refetch la JWKS cuando les llega un kid desconocido — la rotación
 * de este sistema es exactamente eso).
 */
final class GenerateJwtKeys extends Command
{
    protected $signature = 'user-service:jwt-keys {--force : Regenera el par aunque ya exista}';

    protected $description = 'Genera el par de claves RS256 del IdP en storage/app/jwt';

    public function handle(JwtKeys $jwtKeys): int
    {
        $privatePath = (string) config('jwt.private_key');
        $publicPath = (string) config('jwt.public_key');

        if (! $this->option('force') && (is_file($privatePath) || is_file($publicPath))) {
            $this->error('Ya existe un par de claves en:');
            $this->line("  {$privatePath}");
            $this->line("  {$publicPath}");
            $this->line('Regenerarlo invalida todos los JWT vivos. Repite con --force si es lo que quieres.');

            return self::FAILURE;
        }

        $key = openssl_pkey_new([
            'digest_alg' => 'sha256',
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($key === false) {
            $this->error('openssl_pkey_new falló: revisa tu instalación de OpenSSL.');

            return self::FAILURE;
        }

        openssl_pkey_export($key, $privatePem);

        $details = openssl_pkey_get_details($key);

        $this->ensureDirectory(dirname($privatePath));

        file_put_contents($privatePath, $privatePem);
        chmod($privatePath, 0600);

        file_put_contents($publicPath, $details['key']);
        chmod($publicPath, 0644);

        $this->info('Par de claves RS256 generado:');
        $this->line("  privada: {$privatePath} (nunca sale de user-service)");
        $this->line("  pública: {$publicPath} (Se publica vía /api/jwks.json)");
        $this->line("  kid:     {$jwtKeys->kid()}");

        $envPath = $this->laravel->basePath('.env');

        $this->comment('Pistas:');
        $this->comment('  - El par NO hace falta en task/billing: ellos solo usan la JWKS.');
        $this->comment('  - Si mueves el par de sitio, declara JWT_PRIVATE_KEY y JWT_PUBLIC_KEY en .env');
        $this->comment('  - Regenerar el par rota las claves: los tokens con el kid viejo dejan de valer');

        return self::SUCCESS;
    }

    private function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new \RuntimeException("No se pudo crear el directorio de claves: {$directory}");
        }
    }
}
