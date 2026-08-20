<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Lms\DestinoGrabacion;
use App\Models\Lms\Grabacion;
use App\Models\Lms\IntegracionVideo;
use App\Services\Google\TokenDeServicio;
use App\Services\Grabaciones\Destinos;
use App\Support\ProveedoresVideoCatalogo;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Baja una grabación del proveedor y la sube al destino de la escuela.
 *
 * ── Va en la cola, y no puede ser de otra forma ────────────────────────────
 * Una clase de dos horas pesa cientos de megas. Hacerlo dentro de la petición
 * del webhook significaría que Zoom espera minutos por su respuesta —y reintenta
 * el aviso creyendo que no llegó, lo que dispara una segunda descarga del mismo
 * archivo—. El webhook contesta al instante y esto ocurre después.
 *
 * ── Se descarga a DISCO por trozos, no a memoria ───────────────────────────
 * `Http::get()->body()` traería el video entero a memoria y tumbaría el proceso
 * con el límite de PHP. Se escribe a un temporal conforme llega, y ese temporal
 * es lo que recibe el destino.
 *
 * ── El temporal se borra SIEMPRE ───────────────────────────────────────────
 * En `finally`, incluso cuando la subida falla. Sin eso, cada reintento de cada
 * clase deja un video de medio giga en el disco del servidor y la partición se
 * llena en un semestre —fallando además todo lo demás que escribe ahí—.
 */
class ArchivarGrabacion implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Tres intentos con espera creciente.
     *
     * Lo que falla aquí suele ser de red o un servicio ocupado, y eso se cura
     * esperando. Insistir de inmediato sólo repite el mismo corte; esperar cinco
     * minutos entre intentos da tiempo a que el otro lado vuelva.
     */
    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300];

    /** Media hora: una grabación grande tarda, y matarla a los 60s no archiva. */
    public int $timeout = 1800;

    public function __construct(
        public readonly string $tenantId,
        public readonly int $grabacionId,
        /** URL de descarga. Caduca, por eso viaja con el trabajo y no se relee. */
        public readonly string $urlOrigen,
        /** Token para bajarla, cuando el proveedor lo exige (Zoom). */
        public readonly ?string $token = null,
    ) {}

    public function handle(Destinos $destinos): void
    {
        /*
         * La cola no sabe de escuelas: un job que se encoló en `demo` se
         * ejecuta en un proceso que no tiene tenant puesto. Sin esto, la
         * consulta iría a la base equivocada — o a ninguna.
         */
        tenancy()->initialize(config('tenancy.tenant_model')::find($this->tenantId));

        $grabacion = Grabacion::find($this->grabacionId);

        if ($grabacion === null || $grabacion->estaArchivada()) {
            // Ya no está, o alguien la archivó antes: no hay nada que hacer y no
            // es un error. Zoom reenvía sus avisos.
            return;
        }

        $destino = DestinoGrabacion::activo();

        if ($destino === null) {
            $this->anotarFallo($grabacion, 'La escuela no tiene destino de archivado encendido.');

            return;
        }

        $grabacion->update(['estado' => Grabacion::ARCHIVANDO, 'intentos' => $grabacion->intentos + 1]);

        $temporal = tempnam(sys_get_temp_dir(), 'grabacion-');

        try {
            $this->descargar($temporal, $this->tokenPara($grabacion));

            $guardado = $destinos->para($destino)->subir(
                $temporal,
                $grabacion->nombre,
                $this->carpetaDe($grabacion),
            );

            $grabacion->update([
                'estado' => Grabacion::ARCHIVADA,
                'destino' => $destino->clave,
                'ruta_destino' => $guardado->ruta,
                'url_destino' => $guardado->url,
                'bytes' => $guardado->bytes ?? $grabacion->bytes,
                'error' => null,
                'archivada_en' => now(),
            ]);
        } catch (Throwable $e) {
            $this->anotarFallo($grabacion, $e->getMessage());

            // Se relanza para que la cola reintente con su espera. El estado ya
            // quedó anotado, así que la pantalla lo dice aunque se acaben los
            // intentos.
            throw $e;
        } finally {
            if (is_string($temporal) && file_exists($temporal)) {
                @unlink($temporal);
            }
        }
    }

    /**
     * Con qué credencial se baja este archivo.
     *
     * Zoom manda un `download_token` en su aviso y viaja con el trabajo. Meet no
     * manda nada: la grabación está en Drive y hay que pedir un token AHORA —el
     * de la consulta sirve para preguntar, no para bajar, y de todos modos
     * habría caducado esperando en la cola—.
     */
    private function tokenPara(Grabacion $grabacion): ?string
    {
        if ($this->token !== null) {
            return $this->token;
        }

        if ($grabacion->origen !== ProveedoresVideoCatalogo::MEET) {
            return null;
        }

        $comoQuien = $grabacion->clase?->cuenta?->identificador;

        if ($comoQuien === null) {
            throw new \RuntimeException('La clase de Meet no tiene cuenta organizadora: no hay a nombre de quién bajar la grabación.');
        }

        /*
         * Lectura y no escritura: aquí sólo se BAJA lo que Meet dejó. Y tiene
         * que ser `drive.readonly` y no `drive.file` —el alcance que usa el
         * destino para subir—, porque ese archivo no lo creó esta app y
         * `drive.file` no lo alcanza.
         */
        return app(TokenDeServicio::class)->para(
            (string) (IntegracionVideo::para(ProveedoresVideoCatalogo::MEET)
                ->credencialesArray()['cuenta_servicio_json'] ?? ''),
            $comoQuien,
            TokenDeServicio::DRIVE_LECTURA,
        );
    }

    /** Escribe la descarga en el temporal conforme llega. */
    private function descargar(string $temporal, ?string $token): void
    {
        $peticion = Http::timeout(1800);

        if ($token !== null) {
            $peticion = $peticion->withToken($token);
        }

        $respuesta = $peticion->sink($temporal)->get($this->urlOrigen);

        if (! $respuesta->successful()) {
            throw new \RuntimeException(
                "El proveedor no entregó la grabación (HTTP {$respuesta->status()}).",
            );
        }

        if (! file_exists($temporal) || filesize($temporal) === 0) {
            // Un archivo de cero bytes se subiría sin error y dejaría una clase
            // «archivada» que al abrirla no tiene nada.
            throw new \RuntimeException('La grabación se descargó vacía.');
        }
    }

    /** «Sistemas Operativos · 2026-2» — dónde se busca a mano si hace falta. */
    private function carpetaDe(Grabacion $grabacion): string
    {
        $clase = $grabacion->clase;
        $materia = $clase?->materia?->planMateria?->asignatura?->nombre ?? 'Materia';
        $ciclo = $clase?->materia?->grupo?->ciclo?->clave ?? '';

        // Sin caracteres que rompan una ruta en Windows ni en Dropbox.
        return trim(preg_replace('/[\\\\\/:*?"<>|]+/', '-', trim("{$materia} {$ciclo}")));
    }

    private function anotarFallo(Grabacion $grabacion, string $motivo): void
    {
        Log::warning('No se pudo archivar una grabación', [
            'grabacion' => $grabacion->id,
            'motivo' => $motivo,
        ]);

        $grabacion->update([
            'estado' => Grabacion::FALLIDA,
            // Recortado: un volcado de Guzzle de 8 KB en una columna que se
            // pinta en pantalla no ayuda a nadie.
            'error' => mb_substr($motivo, 0, 500),
        ]);
    }
}
