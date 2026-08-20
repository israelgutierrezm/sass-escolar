<?php

declare(strict_types=1);

namespace App\Services\Grabaciones;

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
     * @param  array<int, array{id: string, tipo: string, nombre: string, bytes: ?int, url: string}>  $archivos
     * @return int cuántos se encolaron de nuevo
     */
    public function registrar(
        Videoconferencia $clase,
        string $origen,
        array $archivos,
        ?string $token = null,
    ): int {
        $destino = DestinoGrabacion::activo();

        $nuevos = 0;

        foreach ($archivos as $archivo) {
            $existente = Grabacion::query()
                ->where('origen', $origen)
                ->where('id_externo', $archivo['id'])
                ->first();

            if ($existente !== null && $existente->estaArchivada()) {
                // Ya está guardada: el aviso repetido no vuelve a bajarla.
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
            ]);

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
