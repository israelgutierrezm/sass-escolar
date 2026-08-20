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
use Illuminate\Support\Str;

/**
 * Google Meet, a través de Google Calendar.
 *
 * ── Meet NO tiene API de reuniones, y eso cambia el diseño ─────────────────
 * No hay un «crear reunión» como el de Zoom. Un enlace de Meet nace de un EVENTO
 * de Calendar al que se le pide una conferencia (`conferenceData.createRequest`),
 * y el enlace viene de vuelta en `hangoutLink`. Todo lo que sigue es
 * consecuencia de eso:
 *
 * - Hace falta **Google Workspace**. Con una cuenta de Gmail personal no se
 *   puede: la delegación en todo el dominio no existe fuera de Workspace. Se
 *   avisa en el catálogo, porque es la clase de requisito que se descubre a
 *   media configuración y ya con las credenciales pegadas.
 * - La autenticación es una **cuenta de servicio con delegación** que actúa EN
 *   NOMBRE de una cuenta del dominio (el `sub` del JWT). Por eso una «cuenta»
 *   aquí no es una licencia: es la identidad que organiza el evento.
 * - **No hay enlace de anfitrión aparte.** En Meet todos entran por el mismo
 *   enlace y el control lo da ser el organizador. `urlAnfitrion` va en null y no
 *   se inventa uno: duplicar el mismo enlace en los dos campos haría creer que
 *   hay algo que proteger donde no lo hay, y volvería inútil la comprobación de
 *   que al alumno no le llega el del docente.
 *
 * ── El JWT se firma a mano ─────────────────────────────────────────────────
 * Son veinte líneas con `openssl_sign` y evita traer el SDK entero de Google
 * —con sus dependencias— para pedir un token y crear un evento. Si algún día
 * hiciera falta más de Calendar, ese cálculo cambia.
 */
class ProveedorMeet implements Proveedor
{
    private const AMBITO = 'https://www.googleapis.com/auth/calendar.events';

    private ?IntegracionVideo $integracion = null;

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
        $fin = $inicio->clone()->addMinutes($minutos);

        $respuesta = Http::withToken($this->token($cuenta->identificador))
            ->acceptJson()
            ->timeout(20)
            // `conferenceDataVersion=1` es lo que autoriza a crear la
            // conferencia. Sin él, Google acepta el evento y lo devuelve SIN
            // enlace de Meet: no falla, sólo no sirve.
            ->post('https://www.googleapis.com/calendar/v3/calendars/primary/events?conferenceDataVersion=1', [
                'summary' => mb_substr($titulo, 0, 200),
                'start' => [
                    'dateTime' => $inicio->clone()->toIso8601String(),
                    'timeZone' => config('app.timezone'),
                ],
                'end' => [
                    'dateTime' => $fin->toIso8601String(),
                    'timeZone' => config('app.timezone'),
                ],
                'conferenceData' => [
                    'createRequest' => [
                        // Google exige un id de petición único; sirve para que
                        // un reintento no cree dos conferencias.
                        'requestId' => (string) Str::uuid(),
                        'conferenceSolutionKey' => ['type' => 'hangoutsMeet'],
                    ],
                ],
                /*
                 * Sin invitados. Se podrían agregar los correos del grupo, pero
                 * eso mandaría una invitación de Calendar a cada alumno cada vez
                 * que el docente programa una clase —y a los que no usan ese
                 * correo, a ninguna parte—. Quien entra lo hace por el botón de
                 * Acadion, que es donde ya sabemos quién es.
                 */
                'guestsCanModify' => false,
            ]);

        $this->exigirExito($respuesta, 'No se pudo crear la clase en Google Meet.');

        $enlace = $respuesta->json('hangoutLink');

        AvisoParaElUsuario::si(
            blank($enlace),
            502,
            'Google creó el evento pero no devolvió enlace de Meet. Revisa que la cuenta de servicio tenga '
            .'delegación en todo el dominio y que el dominio sea de Google Workspace.',
        );

        return new SesionCreada(
            meetingId: (string) $respuesta->json('id'),
            urlInvitado: $enlace,
            // En Meet no hay enlace de anfitrión: es el mismo para todos.
            urlAnfitrion: null,
        );
    }

    public function cancelar(Videoconferencia $sesion): void
    {
        if (blank($sesion->meeting_id) || $sesion->cuenta === null) {
            return;
        }

        $respuesta = Http::withToken($this->token($sesion->cuenta->identificador))
            ->timeout(20)
            ->delete("https://www.googleapis.com/calendar/v3/calendars/primary/events/{$sesion->meeting_id}");

        // 404 y 410 son éxito: el evento ya no está, que es lo que se quería.
        if (in_array($respuesta->status(), [404, 410], true) || $respuesta->successful()) {
            return;
        }

        $this->exigirExito($respuesta, 'No se pudo cancelar la clase en Google Meet.');
    }

    /**
     * Token para actuar EN NOMBRE de esa cuenta del dominio.
     *
     * Va por cuenta y no por escuela: el token lleva dentro a quién suplanta, y
     * reusar el de otra cuenta crearía los eventos en la agenda equivocada.
     */
    private function token(string $comoQuien): string
    {
        $credenciales = $this->credenciales();
        $json = $this->cuentaDeServicio($credenciales);

        $llave = 'meet.token.'.md5((string) tenant('id').$json['client_email'].$comoQuien);

        $token = CacheExterno::recordar($llave, 50, function () use ($json, $comoQuien) {
            $respuesta = Http::asForm()->timeout(20)->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $this->firmarJwt($json, $comoQuien),
            ]);

            if (! $respuesta->successful()) {
                Log::warning('Google no entregó token', [
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
            "Google no aceptó las credenciales para actuar como «{$comoQuien}». Revisa la cuenta de servicio "
            .'y que tenga delegación en todo el dominio con el alcance de Calendar.',
        );

        return $token;
    }

    /**
     * El JWT firmado con la llave privada de la cuenta de servicio.
     *
     * `sub` es la parte que hace la delegación: dice a nombre de QUIÉN se actúa.
     * Sin él, Google devuelve un token válido de la cuenta de servicio, que no
     * tiene calendario propio — el evento se crea en ninguna parte y no hay
     * error que lo diga.
     *
     * @param  array<string, mixed>  $json
     */
    private function firmarJwt(array $json, string $comoQuien): string
    {
        $ahora = time();

        $cabecera = ['alg' => 'RS256', 'typ' => 'JWT'];
        $cuerpo = [
            'iss' => $json['client_email'],
            'sub' => $comoQuien,
            'scope' => self::AMBITO,
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $ahora,
            // Google no acepta más de una hora.
            'exp' => $ahora + 3600,
        ];

        $sinFirmar = $this->base64Url(json_encode($cabecera)).'.'.$this->base64Url(json_encode($cuerpo));

        $llave = openssl_pkey_get_private($json['private_key']);

        AvisoParaElUsuario::si(
            $llave === false,
            422,
            'La llave privada de la cuenta de servicio no se pudo leer. Pega el archivo JSON completo, tal como lo '
            .'entrega Google, sin quitarle los saltos de línea.',
        );

        openssl_sign($sinFirmar, $firma, $llave, OPENSSL_ALGO_SHA256);

        return $sinFirmar.'.'.$this->base64Url($firma);
    }

    private function base64Url(string $dato): string
    {
        return rtrim(strtr(base64_encode($dato), '+/', '-_'), '=');
    }

    /**
     * El JSON de la cuenta de servicio, ya interpretado.
     *
     * Se guarda como texto y no como arreglo porque es lo que la escuela pega:
     * el archivo tal cual. Interpretarlo aquí permite decirle que está mal
     * cuando lo está, en vez de fallar más adelante con un error de Google que
     * no menciona el JSON.
     *
     * @param  array<string, mixed>  $credenciales
     * @return array<string, mixed>
     */
    private function cuentaDeServicio(array $credenciales): array
    {
        $json = json_decode((string) ($credenciales['cuenta_servicio_json'] ?? ''), true);

        AvisoParaElUsuario::si(
            ! is_array($json) || blank($json['client_email'] ?? null) || blank($json['private_key'] ?? null),
            422,
            'El JSON de la cuenta de servicio no es válido: tiene que traer `client_email` y `private_key`. '
            .'Pega el archivo completo que descargaste de Google Cloud.',
        );

        return $json;
    }

    /** @return array<string, string> */
    private function credenciales(): array
    {
        AvisoParaElUsuario::si($this->integracion === null, 500, 'El proveedor de Meet se usó sin configurar.');

        return $this->integracion->credencialesArray();
    }

    /** Que la respuesta haya salido bien, y si no, decir POR QUÉ. */
    private function exigirExito(Response $respuesta, string $mensaje): void
    {
        if ($respuesta->successful()) {
            return;
        }

        $detalle = $respuesta->json('error.message') ?? $respuesta->body();

        Log::warning('Google respondió con error', [
            'estado' => $respuesta->status(),
            'cuerpo' => $respuesta->body(),
        ]);

        AvisoParaElUsuario::lanzar(502, trim("{$mensaje} Google dijo: {$detalle}"));
    }
}
