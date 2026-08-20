<?php

declare(strict_types=1);

namespace App\Services\Grabaciones;

use App\Exceptions\AvisoParaElUsuario;
use App\Models\Lms\DestinoGrabacion;
use App\Support\CacheExterno;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Google Drive, con la misma cuenta de servicio que ya usa Meet.
 *
 * ── Subida RESUMABLE, no simple ────────────────────────────────────────────
 * Drive acepta subir el archivo de un tirón (`uploadType=media`) y para una
 * grabación de clase eso es una mala idea: son cientos de megas por una conexión
 * que puede cortarse, y un corte obliga a empezar de cero. La resumable pide
 * primero una URL de sesión y luego manda el contenido contra ella, que es lo
 * que Google recomienda a partir de 5 MB — y una clase de una hora nunca baja de
 * ahí.
 *
 * ── El archivo se manda por TROZOS ─────────────────────────────────────────
 * Leer el video entero a memoria para subirlo tumbaría el proceso: PHP tiene un
 * límite de memoria y un video de dos horas lo pasa. Se abre el archivo y se
 * empuja de a poco.
 */
class DestinoDrive implements Destino
{
    private const AMBITO = 'https://www.googleapis.com/auth/drive.file';

    public function __construct(private readonly DestinoGrabacion $config) {}

    public function subir(string $rutaLocal, string $nombre, string $carpeta): ArchivoArchivado
    {
        $credenciales = $this->config->credencialesArray();
        $token = $this->token();
        $bytes = filesize($rutaLocal);

        // 1. Pedir la sesión. Aquí van los metadatos, no el contenido.
        $sesion = Http::withToken($token)
            ->timeout(30)
            ->post('https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&supportsAllDrives=true', [
                'name' => $nombre,
                'parents' => [$credenciales['carpeta_id']],
                // La subcarpeta se dice en la descripción y no se crea: crear
                // carpetas exige listarlas primero para no duplicarlas, y eso es
                // una llamada más por cada grabación. La carpeta que importa —la
                // de la escuela— ya la eligió el administrador.
                'description' => "Grabación de clase · {$carpeta}",
            ]);

        $this->exigirExito($sesion, 'No se pudo iniciar la subida a Drive.');

        $destino = $sesion->header('Location');

        AvisoParaElUsuario::si(
            blank($destino),
            502,
            'Drive aceptó la petición pero no devolvió a dónde subir. Vuelve a intentarlo.',
        );

        // 2. Empujar el contenido contra la URL de sesión.
        $manejador = fopen($rutaLocal, 'rb');

        AvisoParaElUsuario::si($manejador === false, 500, 'No se pudo leer el archivo descargado.');

        $subida = Http::withToken($token)
            ->withHeaders(['Content-Length' => (string) $bytes])
            // Sin límite de tiempo corto: una grabación grande tarda minutos, y
            // cortarla a los treinta segundos haría que no se archivara nunca
            // ninguna clase larga —justo las que interesa guardar—.
            ->timeout(1800)
            ->withBody($manejador, 'application/octet-stream')
            ->put($destino);

        if (is_resource($manejador)) {
            fclose($manejador);
        }

        $this->exigirExito($subida, 'No se pudo subir la grabación a Drive.');

        $id = $subida->json('id');

        return new ArchivoArchivado(
            ruta: (string) $id,
            // El enlace de siempre de Drive. Quién lo puede abrir lo deciden los
            // permisos de la carpeta: Acadion no los toca, porque compartir una
            // clase con menores dentro no es algo que deba hacer un archivador.
            url: $id ? "https://drive.google.com/file/d/{$id}/view" : null,
            bytes: $bytes ?: null,
        );
    }

    /**
     * Token de Drive, actuando como la cuenta que se configuró.
     *
     * Misma mecánica que en Meet —cuenta de servicio con delegación— pero con
     * OTRO alcance: `drive.file`, que sólo alcanza a los archivos que la propia
     * app crea. Es deliberado: con el alcance completo de Drive, una credencial
     * filtrada abriría todo el Drive de la escuela.
     */
    private function token(): string
    {
        $json = $this->cuentaDeServicio();
        $comoQuien = $this->config->credencialesArray()['como_quien'];

        $llave = 'drive.token.'.md5((string) tenant('id').$json['client_email'].$comoQuien);

        $token = CacheExterno::recordar($llave, 50, function () use ($json, $comoQuien) {
            $respuesta = Http::asForm()->timeout(20)->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $this->firmarJwt($json, $comoQuien),
            ]);

            if (! $respuesta->successful()) {
                Log::warning('Google no entregó token para Drive', [
                    'estado' => $respuesta->status(),
                    'cuerpo' => $respuesta->body(),
                ]);

                return null;
            }

            return $respuesta->json('access_token');
        });

        AvisoParaElUsuario::si(
            blank($token),
            502,
            "Google no aceptó las credenciales para actuar como «{$comoQuien}». Revisa que la cuenta de servicio "
            .'tenga delegación en todo el dominio con el alcance de Drive.',
        );

        return $token;
    }

    /** @param  array<string, mixed>  $json */
    private function firmarJwt(array $json, string $comoQuien): string
    {
        $ahora = time();

        $sinFirmar = $this->base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']))
            .'.'.$this->base64Url(json_encode([
                'iss' => $json['client_email'],
                'sub' => $comoQuien,
                'scope' => self::AMBITO,
                'aud' => 'https://oauth2.googleapis.com/token',
                'iat' => $ahora,
                'exp' => $ahora + 3600,
            ]));

        $llave = openssl_pkey_get_private($json['private_key']);

        AvisoParaElUsuario::si(
            $llave === false,
            422,
            'La llave privada de la cuenta de servicio no se pudo leer. Pega el archivo JSON completo.',
        );

        openssl_sign($sinFirmar, $firma, $llave, OPENSSL_ALGO_SHA256);

        return $sinFirmar.'.'.$this->base64Url($firma);
    }

    private function base64Url(string $dato): string
    {
        return rtrim(strtr(base64_encode($dato), '+/', '-_'), '=');
    }

    /** @return array<string, mixed> */
    private function cuentaDeServicio(): array
    {
        $json = json_decode((string) ($this->config->credencialesArray()['cuenta_servicio_json'] ?? ''), true);

        AvisoParaElUsuario::si(
            ! is_array($json) || blank($json['client_email'] ?? null) || blank($json['private_key'] ?? null),
            422,
            'El JSON de la cuenta de servicio no es válido: tiene que traer `client_email` y `private_key`.',
        );

        return $json;
    }

    private function exigirExito($respuesta, string $mensaje): void
    {
        if ($respuesta->successful()) {
            return;
        }

        $detalle = $respuesta->json('error.message') ?? $respuesta->body();

        Log::warning('Drive respondió con error', ['estado' => $respuesta->status(), 'cuerpo' => $respuesta->body()]);

        AvisoParaElUsuario::lanzar(502, trim("{$mensaje} Google dijo: {$detalle}"));
    }
}
