<?php

declare(strict_types=1);

namespace App\Services\Grabaciones;

use App\Exceptions\AvisoParaElUsuario;
use App\Models\Lms\DestinoGrabacion;
use App\Support\CacheExterno;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Dropbox.
 *
 * ── El token de acceso se PIDE cada vez, con el de refresco ────────────────
 * Desde 2021 los tokens de acceso de Dropbox caducan a las cuatro horas. Guardar
 * uno en la configuración deja el archivado funcionando la tarde en que se
 * configuró y roto para siempre después, sin más síntoma que «ya no se suben las
 * grabaciones» semanas más tarde. Por eso lo que se guarda es el REFRESH token y
 * el de acceso se saca de él y se cachea unas horas.
 *
 * ── Subida por SESIÓN cuando el archivo es grande ──────────────────────────
 * `files/upload` sólo acepta hasta 150 MB, y una clase de una hora los pasa sin
 * esfuerzo. Por encima hay que abrir una sesión y mandar el contenido por
 * trozos. Se usa la sesión SIEMPRE, incluso para archivos chicos: mantener dos
 * caminos significa que el que casi nunca se usa es el que está roto el día que
 * hace falta.
 */
class DestinoDropbox implements Destino
{
    /** 8 MB por trozo: ni tantas peticiones ni tanta memoria por vuelta. */
    private const TROZO = 8 * 1024 * 1024;

    public function __construct(private readonly DestinoGrabacion $config) {}

    public function subir(string $rutaLocal, string $nombre, string $carpeta): ArchivoArchivado
    {
        $token = $this->token();
        $bytes = filesize($rutaLocal);
        $ruta = $this->rutaDestino($nombre, $carpeta);

        $manejador = fopen($rutaLocal, 'rb');

        AvisoParaElUsuario::si($manejador === false, 500, 'No se pudo leer el archivo descargado.');

        try {
            $sesion = $this->abrirSesion($token, $manejador);
            $subido = $this->cerrarSesion($token, $manejador, $sesion, $ruta);
        } finally {
            if (is_resource($manejador)) {
                fclose($manejador);
            }
        }

        return new ArchivoArchivado(
            ruta: $subido['path_display'] ?? $ruta,
            // El enlace compartido se pide aparte y puede fallar por sí solo
            // (Dropbox lo rechaza si ya existe uno). No se deja que eso tire un
            // archivado que ya terminó: el archivo está, que es lo que importa.
            url: $this->enlaceCompartido($token, $subido['path_lower'] ?? $ruta),
            bytes: $bytes ?: null,
        );
    }

    /**
     * Abre la sesión con el primer trozo.
     *
     * @param  resource  $manejador
     */
    private function abrirSesion(string $token, $manejador): string
    {
        $primero = fread($manejador, self::TROZO) ?: '';

        $respuesta = Http::withToken($token)
            ->withHeaders([
                'Dropbox-API-Arg' => json_encode(['close' => false]),
                'Content-Type' => 'application/octet-stream',
            ])
            ->timeout(300)
            ->withBody($primero, 'application/octet-stream')
            ->post('https://content.dropboxapi.com/2/files/upload_session/start');

        $this->exigirExito($respuesta, 'No se pudo iniciar la subida a Dropbox.');

        return (string) $respuesta->json('session_id');
    }

    /**
     * Manda el resto por trozos y cierra con la ruta final.
     *
     * @param  resource  $manejador
     * @return array<string, mixed>
     */
    private function cerrarSesion(string $token, $manejador, string $sesion, string $ruta): array
    {
        $desplazamiento = ftell($manejador);

        while (! feof($manejador)) {
            $trozo = fread($manejador, self::TROZO);

            if ($trozo === false || $trozo === '') {
                break;
            }

            $respuesta = Http::withToken($token)
                ->withHeaders([
                    'Dropbox-API-Arg' => json_encode([
                        'cursor' => ['session_id' => $sesion, 'offset' => $desplazamiento],
                        'close' => false,
                    ]),
                    'Content-Type' => 'application/octet-stream',
                ])
                ->timeout(300)
                ->withBody($trozo, 'application/octet-stream')
                ->post('https://content.dropboxapi.com/2/files/upload_session/append_v2');

            $this->exigirExito($respuesta, 'Se cortó la subida a Dropbox.');

            $desplazamiento += strlen($trozo);
        }

        $respuesta = Http::withToken($token)
            ->withHeaders([
                'Dropbox-API-Arg' => json_encode([
                    'cursor' => ['session_id' => $sesion, 'offset' => $desplazamiento],
                    'commit' => [
                        'path' => $ruta,
                        // `add` con autorename: si ya existe uno con ese nombre,
                        // Dropbox le pone un sufijo en vez de pisarlo. Pisar
                        // sería perder la grabación de otra clase que se llamaba
                        // igual.
                        'mode' => 'add',
                        'autorename' => true,
                        'mute' => true,
                    ],
                ]),
                'Content-Type' => 'application/octet-stream',
            ])
            ->timeout(300)
            ->withBody('', 'application/octet-stream')
            ->post('https://content.dropboxapi.com/2/files/upload_session/finish');

        $this->exigirExito($respuesta, 'No se pudo cerrar la subida a Dropbox.');

        return $respuesta->json() ?? [];
    }

    /**
     * El enlace para compartir, si se puede.
     *
     * Devuelve null en vez de reventar: el archivo ya está subido y una clase
     * archivada sin enlace directo sigue siendo una clase archivada. Dropbox
     * rechaza crear un enlace que ya existe, y ese caso se resuelve preguntando
     * por el que hay.
     */
    private function enlaceCompartido(string $token, string $ruta): ?string
    {
        $respuesta = Http::withToken($token)->timeout(30)
            ->post('https://api.dropboxapi.com/2/sharing/create_shared_link_with_settings', ['path' => $ruta]);

        if ($respuesta->successful()) {
            return $respuesta->json('url');
        }

        // Ya tenía uno: se pide el existente.
        if ($respuesta->json('error.shared_link_already_exists') !== null) {
            $existente = Http::withToken($token)->timeout(30)
                ->post('https://api.dropboxapi.com/2/sharing/list_shared_links', ['path' => $ruta, 'direct_only' => true]);

            return $existente->json('links.0.url');
        }

        Log::warning('Dropbox no dio enlace compartido', ['cuerpo' => $respuesta->body()]);

        return null;
    }

    /** `/Clases/Sistemas Operativos 2026-2/clase.mp4` */
    private function rutaDestino(string $nombre, string $carpeta): string
    {
        $base = trim((string) ($this->config->credencialesArray()['carpeta'] ?? ''), '/');
        $partes = array_filter([$base, trim($carpeta, '/'), $nombre]);

        return '/'.implode('/', $partes);
    }

    /** Token de acceso, sacado del de refresco y cacheado 3 horas (vive 4). */
    private function token(): string
    {
        $credenciales = $this->config->credencialesArray();

        $llave = 'dropbox.token.'.md5((string) tenant('id').$credenciales['app_key'].$credenciales['refresh_token']);

        $token = CacheExterno::recordar($llave, 180, function () use ($credenciales) {
            $respuesta = Http::asForm()
                ->withBasicAuth($credenciales['app_key'], $credenciales['app_secret'])
                ->timeout(20)
                ->post('https://api.dropbox.com/oauth2/token', [
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $credenciales['refresh_token'],
                ]);

            if (! $respuesta->successful()) {
                Log::warning('Dropbox no entregó token', [
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
            'Dropbox no aceptó las credenciales. Revisa que el token guardado sea el de REFRESCO y no uno de acceso.',
        );

        return $token;
    }

    private function exigirExito($respuesta, string $mensaje): void
    {
        if ($respuesta->successful()) {
            return;
        }

        $detalle = $respuesta->json('error_summary') ?? $respuesta->body();

        Log::warning('Dropbox respondió con error', ['estado' => $respuesta->status(), 'cuerpo' => $respuesta->body()]);

        AvisoParaElUsuario::lanzar(502, trim("{$mensaje} Dropbox dijo: {$detalle}"));
    }
}
