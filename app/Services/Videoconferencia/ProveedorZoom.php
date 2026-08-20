<?php

declare(strict_types=1);

namespace App\Services\Videoconferencia;

use App\Exceptions\AvisoParaElUsuario;
use App\Models\Lms\CuentaVideo;
use App\Models\Lms\IntegracionVideo;
use App\Models\Lms\Videoconferencia;
use App\Support\CacheExterno;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Zoom, con una app «Server-to-Server OAuth».
 *
 * ── Por qué Server-to-Server y no la OAuth de siempre ──────────────────────
 * La OAuth normal exige que cada docente autorice la app con su cuenta de Zoom y
 * vuelva a hacerlo cuando caduque el refresh token. Aquí las licencias son de la
 * ESCUELA y quien programa es el docente desde Acadion: pedirle a cada uno que
 * se conecte a Zoom convertiría «programar una clase» en un trámite y dejaría al
 * sistema sin poder crear nada cuando alguien no lo hiciera. Con S2S la escuela
 * autoriza una vez y las reuniones se crean a nombre del usuario que se le diga.
 *
 * ── El token se cachea, y hace falta ──────────────────────────────────────
 * Vive una hora y pedirlo en cada creación duplicaría las llamadas y el tiempo
 * que el docente espera frente al formulario. Se guarda en el almacén de
 * servicios externos —el de archivo, porque la caché del tenant no admite
 * etiquetas— con la ESCUELA y las credenciales dentro de la llave: dos escuelas
 * con sus propias cuentas de Zoom no pueden compartir token, y si alguien
 * cambia el `client_secret` el viejo deja de encontrarse solo.
 */
class ProveedorZoom implements Proveedor
{
    private ?IntegracionVideo $integracion = null;

    /** Devuelve una copia atada a esta configuración. */
    public function con(IntegracionVideo $integracion): self
    {
        $copia = clone $this;
        $copia->integracion = $integracion;

        return $copia;
    }

    public function crear(
        CuentaVideo $cuenta,
        string $titulo,
        CarbonInterface $inicio,
        int $minutos,
    ): SesionCreada {
        $respuesta = $this->http()->post(
            "https://api.zoom.us/v2/users/{$cuenta->identificador}/meetings",
            [
                'topic' => mb_substr($titulo, 0, 200),
                // 2 = reunión programada con fecha y hora.
                'type' => 2,
                // Zoom quiere ISO 8601. Se manda en UTC con la Z explícita y
                // aparte el huso, porque mandar la hora local sin decir cuál es
                // como se termina con una clase creada tres horas antes.
                'start_time' => $inicio->clone()->utc()->format('Y-m-d\TH:i:s\Z'),
                'duration' => $minutos,
                'timezone' => config('app.timezone'),
                'settings' => [
                    /*
                     * Que puedan entrar antes que el docente. Sin esto, el
                     * alumno puntual ve «esperando al anfitrión» y no sabe si se
                     * equivocó de enlace, de hora o de día.
                     */
                    'join_before_host' => true,
                    'waiting_room' => false,
                    'approval_type' => 2,
                ],
            ],
        );

        $this->exigirExito($respuesta, 'No se pudo crear la reunión en Zoom.');

        $datos = $respuesta->json();

        return new SesionCreada(
            meetingId: (string) ($datos['id'] ?? ''),
            urlInvitado: (string) ($datos['join_url'] ?? ''),
            // `start_url` abre como anfitrión sin pedir contraseña: es una llave,
            // y sólo la ve quien da la clase.
            urlAnfitrion: $datos['start_url'] ?? null,
        );
    }

    public function cancelar(Videoconferencia $sesion): void
    {
        if (blank($sesion->meeting_id)) {
            return;
        }

        $respuesta = $this->http()->delete("https://api.zoom.us/v2/meetings/{$sesion->meeting_id}");

        /*
         * Un 404 aquí es éxito: la reunión ya no está, que es lo que se quería.
         * Tratarlo como error dejaría clases imposibles de cancelar en Acadion
         * porque alguien las borró antes desde Zoom.
         */
        if ($respuesta->status() === 404 || $respuesta->successful()) {
            return;
        }

        $this->exigirExito($respuesta, 'No se pudo cancelar la reunión en Zoom.');
    }

    /** Cliente con el token puesto. */
    private function http()
    {
        return Http::withToken($this->token())
            ->acceptJson()
            ->timeout(20);
    }

    /**
     * El token de la escuela, cacheado 50 minutos (vive 60).
     *
     * Se guarda con diez minutos de margen: pedirlo justo al filo dejaría
     * peticiones saliendo con un token que caduca en vuelo, y el docente vería
     * fallar la clase sin motivo aparente.
     */
    private function token(): string
    {
        $credenciales = $this->credenciales();

        $llave = 'zoom.token.'.md5(
            (string) tenant('id')
            .$credenciales['account_id']
            .$credenciales['client_id']
            .$credenciales['client_secret'],
        );

        $token = CacheExterno::recordar($llave, 50, function () use ($credenciales) {
            $respuesta = Http::asForm()
                ->withBasicAuth($credenciales['client_id'], $credenciales['client_secret'])
                ->timeout(20)
                ->post('https://zoom.us/oauth/token', [
                    'grant_type' => 'account_credentials',
                    'account_id' => $credenciales['account_id'],
                ]);

            if (! $respuesta->successful()) {
                Log::warning('Zoom no entregó token', ['estado' => $respuesta->status(), 'cuerpo' => $respuesta->body()]);

                // null y no una excepción: `CacheExterno` no guarda los fallos,
                // así que el siguiente intento vuelve a pedirlo.
                return null;
            }

            return $respuesta->json('access_token');
        });

        AvisoParaElUsuario::si(
            blank($token),
            502,
            'Zoom no aceptó las credenciales de la escuela. Revísalas en Plataforma › Clases en línea.',
        );

        return $token;
    }

    /** @return array<string, string> */
    private function credenciales(): array
    {
        AvisoParaElUsuario::si($this->integracion === null, 500, 'El proveedor de Zoom se usó sin configurar.');

        return $this->integracion->credencialesArray();
    }

    /**
     * Que la respuesta haya salido bien, y si no, decir POR QUÉ.
     *
     * Zoom explica sus negativas en el cuerpo —«user does not exist», «license
     * required»— y esa frase es la que necesita quien está configurando. Un
     * «error al crear la reunión» a secas obliga a abrir los registros del
     * servidor para saber que faltó comprar una licencia.
     */
    private function exigirExito(Response $respuesta, string $mensaje): void
    {
        if ($respuesta->successful()) {
            return;
        }

        $detalle = $respuesta->json('message') ?? $respuesta->body();

        Log::warning('Zoom respondió con error', [
            'estado' => $respuesta->status(),
            'cuerpo' => $respuesta->body(),
        ]);

        AvisoParaElUsuario::lanzar(502, trim("{$mensaje} Zoom dijo: {$detalle}"));
    }
}
