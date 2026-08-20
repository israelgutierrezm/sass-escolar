<?php

declare(strict_types=1);

namespace App\Services\Grabaciones;

use App\Models\Lms\DestinoGrabacion;
use App\Models\Lms\IntegracionVideo;
use App\Models\Lms\Videoconferencia;
use App\Services\Google\TokenDeServicio;
use App\Support\DestinosGrabacionCatalogo;
use App\Support\ProveedoresVideoCatalogo;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Le pregunta a Google si una clase de Meet dejó grabación.
 *
 * ── Por qué hay que preguntar, y no esperar ────────────────────────────────
 * Zoom manda un webhook cuando la grabación está lista. Meet no tiene nada
 * equivalente, así que esto corre cada tanto sobre las clases ya terminadas.
 *
 * ── El camino no es directo: van DOS llamadas ──────────────────────────────
 * La API de Meet no sabe de eventos de Calendar, que es lo que Acadion creó. El
 * puente es el **código de reunión** —las tres sílabas del enlace,
 * `abc-defg-hij`— que sí identifica el espacio:
 *
 *   1. `conferenceRecords` filtrando por ese código → qué sesiones hubo ahí.
 *   2. `recordings` de cada sesión → los archivos.
 *
 * Se filtra por espacio y no por fecha porque un mismo enlace puede usarse
 * varias veces: filtrar por hora traería la clase de otro grupo que se dio en
 * ese rato.
 *
 * ── Si el destino es Drive, no se copia nada ───────────────────────────────
 * Es la asimetría que hace distinto a Meet: Google YA dejó el archivo en el
 * Drive de quien organizó. Copiarlo a otra carpeta del mismo Drive sería pagar
 * dos veces el mismo archivo y duplicar un video de menores sin motivo. Se
 * registra dónde está y se acabó. Con Dropbox o con el disco propio sí hay que
 * bajarlo, porque tiene que salir de Google.
 *
 * ── Lo que NO se ha podido comprobar ───────────────────────────────────────
 * El viaje de ida y vuelta contra Google. Esto está escrito contra la forma
 * documentada de la API v2 y probado con respuestas fingidas de esa forma; para
 * ejercitarlo de verdad hace falta un Workspace con grabación habilitada. Lo que
 * sí se procuró es que falle RUIDOSAMENTE —cada respuesta inesperada se registra
 * con su cuerpo— en vez de devolver una lista vacía, que es indistinguible de
 * «esta clase no se grabó» y dejaría a la escuela creyendo que todo va bien.
 */
class ConsultorDeGrabacionesMeet
{
    public function __construct(
        private readonly TokenDeServicio $tokens,
        private readonly RecolectorDeGrabaciones $recolector,
    ) {}

    /**
     * Busca las grabaciones de una clase y las manda a archivar.
     *
     * Devuelve cuántos archivos nuevos se registraron. Cero es la respuesta
     * normal: la mayoría de las clases no se graban.
     */
    public function revisar(Videoconferencia $clase): int
    {
        $codigo = $this->codigoDeReunion($clase->url_join);

        if ($codigo === null) {
            Log::info('Clase de Meet sin código de reunión legible', ['clase' => $clase->id]);

            return 0;
        }

        $integracion = IntegracionVideo::para(ProveedoresVideoCatalogo::MEET);
        $comoQuien = $clase->cuenta?->identificador;

        if ($comoQuien === null) {
            // Sin saber a nombre de quién preguntar, no se puede: las
            // grabaciones son del espacio de esa cuenta.
            Log::info('Clase de Meet sin cuenta organizadora', ['clase' => $clase->id]);

            return 0;
        }

        $token = $this->tokens->para(
            (string) ($integracion->credencialesArray()['cuenta_servicio_json'] ?? ''),
            $comoQuien,
            TokenDeServicio::MEET_LECTURA,
        );

        $archivos = [];

        foreach ($this->sesionesDe($token, $codigo) as $sesion) {
            foreach ($this->grabacionesDe($token, $sesion) as $grabacion) {
                $archivo = $this->comoArchivo($clase, $grabacion);

                if ($archivo !== null) {
                    $archivos[] = $archivo;
                }
            }
        }

        if ($archivos === []) {
            return 0;
        }

        /*
         * Sin token de descarga: el de Meet sirve para preguntar, no para bajar
         * de Drive —son alcances distintos— y además caducaría antes de que la
         * cola llegue al trabajo. Lo pide `ArchivarGrabacion` cuando le toca.
         */
        return $this->recolector->registrar($clase, ProveedoresVideoCatalogo::MEET, $archivos);
    }

    /**
     * Las sesiones que hubo en ese espacio.
     *
     * @return array<int, string> nombres de conferenceRecord
     */
    private function sesionesDe(string $token, string $codigo): array
    {
        $respuesta = Http::withToken($token)->acceptJson()->timeout(30)
            ->get('https://meet.googleapis.com/v2/conferenceRecords', [
                // La comilla doble es parte de la sintaxis del filtro de Google.
                'filter' => 'space.meeting_code = "'.$codigo.'"',
            ]);

        if (! $respuesta->successful()) {
            // Ruidoso a propósito: una lista vacía es indistinguible de «no se
            // grabó», y así la escuela creería que todo va bien.
            Log::warning('Meet no devolvió las sesiones de un espacio', [
                'codigo' => $codigo,
                'estado' => $respuesta->status(),
                'cuerpo' => $respuesta->body(),
            ]);

            return [];
        }

        return collect($respuesta->json('conferenceRecords', []))
            ->pluck('name')
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Los archivos de una sesión.
     *
     * @return array<int, array<string, mixed>>
     */
    private function grabacionesDe(string $token, string $sesion): array
    {
        $respuesta = Http::withToken($token)->acceptJson()->timeout(30)
            ->get("https://meet.googleapis.com/v2/{$sesion}/recordings");

        if (! $respuesta->successful()) {
            Log::warning('Meet no devolvió las grabaciones de una sesión', [
                'sesion' => $sesion,
                'estado' => $respuesta->status(),
                'cuerpo' => $respuesta->body(),
            ]);

            return [];
        }

        return $respuesta->json('recordings', []);
    }

    /**
     * Traduce una grabación de Meet al vocabulario de Acadion.
     *
     * Devuelve null cuando todavía no sirve: Google la anuncia desde que empieza
     * a grabar, y hasta que el estado es `FILE_GENERATED` el archivo no existe.
     * Registrar antes dejaría una grabación «pendiente» que nunca se puede bajar.
     *
     * @param  array<string, mixed>  $grabacion
     * @return array<string, mixed>|null
     */
    private function comoArchivo(Videoconferencia $clase, array $grabacion): ?array
    {
        if (($grabacion['state'] ?? null) !== 'FILE_GENERATED') {
            return null;
        }

        $archivoDrive = $grabacion['driveDestination']['file'] ?? null;

        if (blank($archivoDrive)) {
            return null;
        }

        $fecha = $clase->inicio?->format('Y-m-d') ?? 'sin-fecha';
        $titulo = preg_replace('/[\\\\\/:*?"<>|]+/', '-', $clase->titulo);

        $archivo = [
            // El nombre del recurso es único y estable: es la llave que hace
            // idempotente volver a preguntar.
            'id' => (string) $grabacion['name'],
            'tipo' => 'video',
            'nombre' => mb_substr("{$fecha} {$titulo}", 0, 150).'.mp4',
            // Meet no dice el tamaño; lo pone el trabajo con lo que descargue.
            'bytes' => null,
            // Bajar de Drive por id, que es lo que acepta un token de servicio.
            // El `exportUri` es para un navegador con sesión, no para nosotros.
            'url' => "https://www.googleapis.com/drive/v3/files/{$archivoDrive}?alt=media&supportsAllDrives=true",
        ];

        /*
         * Y si la escuela archiva EN DRIVE, no hay nada que copiar: el archivo
         * ya está ahí. Se dice de dónde para que el recolector lo dé por
         * archivado en vez de encolar una copia del mismo Drive al mismo Drive.
         */
        $destino = DestinoGrabacion::activo();

        if ($destino?->clave === DestinosGrabacionCatalogo::DRIVE) {
            $archivo['ya_archivado'] = [
                'destino' => DestinosGrabacionCatalogo::DRIVE,
                'ruta' => (string) $archivoDrive,
                'url' => $grabacion['driveDestination']['exportUri']
                    ?? "https://drive.google.com/file/d/{$archivoDrive}/view",
            ];
        }

        return $archivo;
    }

    /**
     * El código de reunión que esconde el enlace.
     *
     * `https://meet.google.com/abc-defg-hij` → `abc-defg-hij`. Es lo único del
     * enlace que la API de Meet entiende, y por eso se extrae en vez de
     * guardarse aparte: si se guardara, habría dos verdades sobre la misma
     * reunión y una podría quedarse vieja.
     */
    private function codigoDeReunion(?string $enlace): ?string
    {
        if (blank($enlace)) {
            return null;
        }

        return preg_match('#meet\.google\.com/([a-z]{3}-[a-z]{4}-[a-z]{3})#i', $enlace, $coincide)
            ? strtolower($coincide[1])
            : null;
    }
}
