<?php

declare(strict_types=1);

namespace App\Services\Lms;

use App\Models\Lms\Actividad;
use App\Models\Lms\ActividadVista;
use App\Models\Lms\Entrega;
use App\Models\Lms\EntregaArchivo;
use App\Models\ControlEscolar\Inscripcion;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Lo que el alumno HACE en el aula: entregar una actividad y marcar/desmarcar
 * una lectura como terminada.
 *
 * ── Una sola verdad ────────────────────────────────────────────────────────
 * La escritura vivía en `EntregaController` y `AulaController` (la web); la app
 * la necesita igual. La regla —el candado del prerrequisito, que sólo se entrega
 * lo que se entrega y sólo mientras esté abierto, que reentregar reemplaza e
 * invalida la calificación, que una lectura no se «completa» entregándola— vive
 * aquí y la usan los dos portales.
 *
 * El candado del prerrequisito se comprueba SIEMPRE en el servidor: esconder el
 * botón no basta, el POST llega igual.
 */
class EntregaDeActividad
{
    public function __construct(
        private readonly Prerequisitos $prerequisitos,
        private readonly CalificadorPorRubrica $rubrica,
    ) {}

    /**
     * Entrega (o reentrega) una actividad. Devuelve el error para mostrar —o
     * `null`— y la entrega resultante (con `tarde` ya decidido).
     *
     * @param  array<int, UploadedFile>  $archivos
     * @return array{error: ?string, entrega: ?Entrega}
     */
    public function entregar(Actividad $actividad, Inscripcion $inscripcion, ?string $contenido, array $archivos): array
    {
        if (! $actividad->tipo->seEntrega()) {
            return ['error' => 'Esta actividad es de lectura: no hay nada que entregar.', 'entrega' => null];
        }

        if (! $actividad->abierta()) {
            return ['error' => 'La entrega de esta actividad está cerrada.', 'entrega' => null];
        }

        // El candado de avance: no se entrega una actividad que su prerrequisito
        // mantiene cerrada. Lanza (403) —no se esconde con un botón—.
        $this->prerequisitos->exigirDesbloqueada($actividad, $inscripcion->id);

        // Hay trabajos de una sola oportunidad. Se comprueba aquí y no sólo en la
        // pantalla: lo que está en juego es que alguien reemplace su trabajo tras
        // leer la retroalimentación del docente o la calificación de un compañero.
        $yaEntregada = Entrega::query()
            ->where('actividad_id', $actividad->id)
            ->where('inscripcion_id', $inscripcion->id)
            ->whereNotNull('entregada_en')
            ->exists();

        if ($yaEntregada && ! $actividad->permite_reentrega) {
            return ['error' => 'Esta actividad admite una sola entrega, y la tuya ya está registrada.', 'entrega' => null];
        }

        if (blank($contenido) && $archivos === []) {
            return ['error' => 'Escribe una respuesta o adjunta al menos un archivo.', 'entrega' => null];
        }

        // Reentregar REEMPLAZA: hay un renglón por alumno y actividad.
        $entrega = Entrega::actualizarOReviver(
            ['actividad_id' => $actividad->id, 'inscripcion_id' => $inscripcion->id],
            [
                'contenido' => $contenido,
                'estado' => Entrega::ENTREGADA,
                'entregada_en' => now(),
                // Se decide AHORA: si la fecha de cierre se mueve después, el dato
                // de que llegó tarde se habría perdido.
                'tarde' => $actividad->cierra_en !== null && now()->gt($actividad->cierra_en),
                // Reentregar invalida la calificación anterior: se califica lo que
                // hay, no lo que hubo.
                'calificacion' => null,
                'retroalimentacion' => null,
                'calificada_por' => null,
                'calificada_en' => null,
            ],
        );

        // El desglose de la rúbrica explicaba un trabajo que ya no está.
        $this->rubrica->olvidar($entrega);

        foreach ($archivos as $archivo) {
            $ruta = $archivo->store("entregas/{$entrega->id}", 'local');

            EntregaArchivo::create([
                'entrega_id' => $entrega->id,
                'ruta' => $ruta,
                'nombre' => $archivo->getClientOriginalName(),
                'bytes' => $archivo->getSize(),
                'mime' => $archivo->getMimeType(),
            ]);
        }

        return ['error' => null, 'entrega' => $entrega];
    }

    /**
     * «Ya la terminé», dicho por el alumno sobre una LECTURA. Devuelve el error
     * —marcar como hecha una tarea sin entregarla sería mentirse— o `null`.
     */
    public function completarLectura(Actividad $actividad, Inscripcion $inscripcion): ?string
    {
        if ($actividad->tipo->seEntrega()) {
            return 'Esta actividad se completa entregándola.';
        }

        $this->prerequisitos->exigirDesbloqueada($actividad, $inscripcion->id);

        ActividadVista::actualizarOReviver(
            ['actividad_id' => $actividad->id, 'inscripcion_id' => $inscripcion->id],
            ['vista_en' => now(), 'completada_en' => now()],
        );

        return null;
    }

    /** Deshacer el «ya la terminé»: se marca de más y hay que poder corregirlo. */
    public function descompletarLectura(Actividad $actividad, Inscripcion $inscripcion): void
    {
        ActividadVista::query()
            ->where('actividad_id', $actividad->id)
            ->where('inscripcion_id', $inscripcion->id)
            ->update(['completada_en' => null]);
    }
}
