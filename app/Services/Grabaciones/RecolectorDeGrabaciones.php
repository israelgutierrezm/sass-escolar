<?php

declare(strict_types=1);

namespace App\Services\Grabaciones;

use App\Configuracion\Ajustes;
use App\Configuracion\CatalogoAjustes;
use App\Jobs\ArchivarGrabacion;
use App\Models\Lms\DestinoGrabacion;
use App\Models\Lms\Grabacion;
use App\Models\Lms\Videoconferencia;
use Illuminate\Support\Facades\Log;

/**
 * Registra las grabaciones que avisa un proveedor y las manda a archivar.
 *
 * ── Registrar y archivar son dos pasos, y por eso hay tabla ────────────────
 * Se anota primero la fila —con lo que el proveedor dijo— y luego se encola la
 * copia. Si se hiciera todo en el aviso, un fallo de red dejaría la grabación
 * sin rastro en Acadion: nadie sabría que existió, y el enlace de Zoom caduca.
 * Con la fila, lo que falla se ve y se reintenta.
 *
 * ── Si se publican solas lo decide la ESCUELA ──────────────────────────────
 * `visible_alumnos` sale del ajuste «Publicar las grabaciones en cuanto
 * llegan», y se copia a la fila al anotarla. Por omisión va apagado —una clase
 * grabada trae caras y voces de menores— pero la escuela puede encenderlo, y
 * entonces cada grabación archivada le aparece sola al grupo.
 *
 * ── Idempotente por (origen, id_externo) ───────────────────────────────────
 * Zoom reenvía su aviso si no se le contesta rápido, y la consulta de Meet vuelve
 * a pasar cada tanto. Sin esa llave única, la misma clase se archivaría tres
 * veces y se pagaría tres veces el almacenamiento.
 */
class RecolectorDeGrabaciones
{
    /**
     * Anota los archivos de una clase y encola lo que haya que copiar.
     *
     * @param  array<int, array{id: string, tipo: string, nombre: string, bytes: ?int, url: string, ya_archivado?: array{destino: string, ruta: string, url: string}}>  $archivos
     * @return int cuántos se encolaron de nuevo
     */
    public function registrar(
        Videoconferencia $clase,
        string $origen,
        array $archivos,
        ?string $token = null,
    ): int {
        $destino = DestinoGrabacion::activo();

        /*
         * Si la escuela quiere que se publiquen solas.
         *
         * Se lee AL ANOTAR y se guarda en la fila, en vez de consultarse cada
         * vez que alguien mira: así cambiar la regla no publica ni esconde de
         * golpe las grabaciones que ya existían. Publicar de un plumazo un
         * semestre de clases con menores dentro no puede ser el efecto de
         * mover un interruptor.
         */
        $publicarSolas = app(Ajustes::class)->bool(CatalogoAjustes::VIDEO_PUBLICAR_GRABACIONES);

        $nuevos = 0;

        foreach ($archivos as $archivo) {
            $existente = Grabacion::query()
                ->where('origen', $origen)
                ->where('id_externo', $archivo['id'])
                ->first();

            /*
             * Lo que ya está guardado o ya va en camino NO se vuelve a encolar.
             *
             * Zoom reenvía su aviso y la consulta de Meet vuelve a pasar: sin
             * esto, cada repetición pondría a otro trabajador a bajar el mismo
             * video de seiscientos megas. Sólo se reintenta lo FALLIDO, que es
             * justamente lo que necesita otra oportunidad — de lo pendiente ya
             * se encarga la cola con sus propios reintentos.
             */
            if ($existente !== null && $existente->estado !== Grabacion::FALLIDA) {
                continue;
            }

            $grabacion = $existente ?? Grabacion::create([
                'videoconferencia_id' => $clase->id,
                'origen' => $origen,
                'id_externo' => $archivo['id'],
                'tipo' => $archivo['tipo'],
                'nombre' => $archivo['nombre'],
                'bytes' => $archivo['bytes'],
                'estado' => Grabacion::PENDIENTE,
                'visible_alumnos' => $publicarSolas,
            ]);

            /*
             * Lo que YA está donde tiene que estar no se copia.
             *
             * Es el caso de Meet cuando la escuela archiva en Drive: Google
             * dejó el archivo ahí al grabar. Copiarlo del mismo Drive al mismo
             * Drive sería pagar dos veces el mismo archivo y duplicar un video
             * de menores sin ningún motivo.
             */
            if (isset($archivo['ya_archivado'])) {
                $grabacion->update([
                    'estado' => Grabacion::ARCHIVADA,
                    'destino' => $archivo['ya_archivado']['destino'],
                    'ruta_destino' => $archivo['ya_archivado']['ruta'],
                    'url_destino' => $archivo['ya_archivado']['url'],
                    'error' => null,
                    'archivada_en' => now(),
                ]);

                $nuevos++;

                continue;
            }

            /*
             * Sin destino encendido se ANOTA igual y no se encola.
             *
             * Es a propósito: así la escuela ve que hubo grabación y puede
             * encender el archivado; si no se anotara, el aviso se perdería y
             * con él la única señal de que esa clase se grabó. Lo que no se
             * puede es recuperarla después —el enlace de Zoom caduca a los
             * pocos días—, y eso se dice en la pantalla.
             */
            if ($destino === null) {
                $grabacion->update([
                    'estado' => Grabacion::FALLIDA,
                    'error' => 'No había destino de archivado encendido cuando llegó el aviso.',
                ]);

                continue;
            }

            ArchivarGrabacion::dispatch(
                (string) tenant('id'),
                $grabacion->id,
                $archivo['url'],
                $token,
            );

            $nuevos++;
        }

        if ($nuevos > 0) {
            Log::info('Grabaciones encoladas para archivar', [
                'clase' => $clase->id,
                'origen' => $origen,
                'archivos' => $nuevos,
            ]);
        }

        return $nuevos;
    }

    /**
     * Qué clase de archivo es, según cómo lo llama Zoom.
     *
     * Se traduce a un vocabulario propio en vez de guardar el de Zoom: el día
     * que Meet mande los suyos, `MP4` y `M4A` no significarían nada, y la
     * pantalla tendría que conocer los dos catálogos.
     */
    public static function tipoDesdeZoom(string $tipoZoom): string
    {
        return match (strtoupper($tipoZoom)) {
            'MP4' => 'video',
            'M4A' => 'audio',
            'CHAT' => 'chat',
            'TRANSCRIPT', 'CC', 'TIMELINE' => 'transcripcion',
            default => 'otro',
        };
    }
}
