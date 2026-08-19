<?php

declare(strict_types=1);

namespace App\Services\Lms;

use App\Models\Lms\Entrega;
use App\Models\Lms\EntregaRubrica;
use App\Models\Lms\RubricaCriterio;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Poner la calificación de una entrega eligiendo un nivel por criterio.
 *
 * ── Los puntos los pone el SERVIDOR, siempre ───────────────────────────────
 * De la petición sólo se cree qué nivel se eligió; cuánto vale ese nivel se lee
 * de la base. Confiar en el número que manda la pantalla sería dejar la
 * calificación de cualquier alumno a un renglón de la consola del navegador —y
 * ni siquiera haría falta mala fe: basta una pantalla desincronizada tras editar
 * la rúbrica—.
 *
 * ── Un criterio sin evaluar NO es un cero ──────────────────────────────────
 * Es la misma regla que ya rige la captura de calificaciones: un componente sin
 * capturar deja la nota incompleta y nunca se pondera como cero. Aquí igual —lo
 * que se lleva evaluado se guarda, pero la entrega no queda calificada hasta que
 * los criterios estén todos—. Si faltara uno y se promediara igual, el alumno
 * recibiría una nota más baja porque el docente se distrajo, y nada lo diría.
 *
 * ── La rúbrica es una ESCALA, no la nota ───────────────────────────────────
 * Una rúbrica de 20 puntos aplicada a una actividad sobre 10 no da 17: da 8.5.
 * La actividad manda —es lo que ya pondera dentro del componente— y la rúbrica
 * sólo dice en qué proporción se cumplió. Sin esa conversión, una misma rúbrica
 * no se podría reusar en dos actividades de distinto peso, que es justo para lo
 * que sirve tener un catálogo.
 */
class CalificadorPorRubrica
{
    public function __construct(private readonly CalculadorComponente $calculador) {}

    /**
     * Evalúa la entrega contra la rúbrica de su actividad.
     *
     * @param  array<int, array{criterio_id: int, nivel_id: int|null, comentario: string|null}>  $elegidos
     * @return array{completa: bool, obtenido: float, total: float, calificacion: float|null}
     */
    public function aplicar(Entrega $entrega, array $elegidos, ?string $retroalimentacion, int $usuarioId): array
    {
        $actividad = $entrega->actividad;
        $rubrica = $actividad?->rubrica;

        if ($rubrica === null) {
            throw new RuntimeException('Esta actividad no se califica con rúbrica.');
        }

        $rubrica->loadMissing('criterios.niveles');

        $total = $rubrica->total();

        if ($total <= 0) {
            // No debería llegar aquí —el catálogo rechaza las que suman cero—,
            // pero de pasar, dividir entre cero le pondría cero al grupo.
            throw new RuntimeException('Esa rúbrica suma cero puntos: no se puede calificar con ella.');
        }

        // Por criterio, para buscar en O(1) y de paso descartar lo que venga de
        // otra rúbrica: se recorre la rúbrica, no lo que mandó la petición.
        $porCriterio = [];

        foreach ($elegidos as $eleccion) {
            $porCriterio[(int) ($eleccion['criterio_id'] ?? 0)] = $eleccion;
        }

        $obtenido = 0.0;
        $sinEvaluar = 0;

        DB::transaction(function () use ($rubrica, $porCriterio, &$obtenido, &$sinEvaluar, $entrega, $retroalimentacion, $usuarioId, $total) {
            foreach ($rubrica->criterios as $criterio) {
                $eleccion = $porCriterio[$criterio->id] ?? null;
                $nivel = $this->nivelElegido($criterio, $eleccion['nivel_id'] ?? null);

                if ($nivel === null) {
                    $sinEvaluar++;
                } else {
                    $obtenido += (float) $nivel->puntos;
                }

                // `actualizarOReviver`: recalificar reemplaza el renglón, y el
                // único (entrega, criterio) no ve las filas borradas.
                EntregaRubrica::actualizarOReviver(
                    ['entrega_id' => $entrega->id, 'criterio_id' => $criterio->id],
                    [
                        'nivel_id' => $nivel?->id,
                        // De la base, no de la petición.
                        'puntos' => $nivel === null ? 0 : (float) $nivel->puntos,
                        'comentario' => $eleccion['comentario'] ?? null,
                    ],
                );
            }

            $this->asentar($entrega, $obtenido, $total, $sinEvaluar === 0, $retroalimentacion, $usuarioId);
        });

        return [
            'completa' => $sinEvaluar === 0,
            'obtenido' => round($obtenido, 2),
            'total' => $total,
            'calificacion' => $sinEvaluar === 0
                ? $this->aEscalaDeLaActividad($obtenido, $total, (float) $entrega->actividad->puntos)
                : null,
        ];
    }

    /**
     * Lo que se lleva evaluado, para volver a pintar el panel.
     *
     * @return array<int, array{criterio_id: int, nivel_id: int|null, puntos: float, comentario: string|null}>
     */
    public function evaluacionDe(Entrega $entrega): array
    {
        return $entrega->porRubrica()
            ->get()
            ->map(fn (EntregaRubrica $e) => [
                'criterio_id' => (int) $e->criterio_id,
                'nivel_id' => $e->nivel_id === null ? null : (int) $e->nivel_id,
                'puntos' => (float) $e->puntos,
                'comentario' => $e->comentario,
            ])
            ->values()
            ->all();
    }

    /**
     * Borra el desglose de una entrega.
     *
     * Se usa al reentregar: la evaluación explicaba un trabajo que ya no está, y
     * dejarla ahí haría que el alumno leyera «Ortografía: insuficiente» sobre el
     * texto corregido que acaba de subir.
     */
    public function olvidar(Entrega $entrega): void
    {
        $entrega->porRubrica()->delete();
    }

    /**
     * Los puntos de la rúbrica llevados a la escala de la actividad.
     *
     * Se redondea a dos decimales y no a los del plan: esto es la nota de UNA
     * actividad, insumo del componente; el redondeo de la calificación final lo
     * hace el calculador con la regla del plan, y redondear dos veces arrastra
     * el error.
     */
    public function aEscalaDeLaActividad(float $obtenido, float $totalRubrica, float $puntosActividad): float
    {
        if ($totalRubrica <= 0) {
            return 0.0;
        }

        return round($obtenido / $totalRubrica * $puntosActividad, 2);
    }

    /**
     * El nivel elegido, sólo si de verdad es de ESE criterio.
     *
     * Se comprueba la pertenencia y no sólo que el id exista: con el id de un
     * nivel de otro criterio —o de otra rúbrica— se podrían sumar puntos que
     * aquí no se pueden dar.
     */
    private function nivelElegido(RubricaCriterio $criterio, mixed $nivelId): ?object
    {
        if ($nivelId === null) {
            return null;
        }

        return $criterio->niveles->firstWhere('id', (int) $nivelId);
    }

    /** Escribe la nota en la entrega, o la deja pendiente si falta evaluar. */
    private function asentar(
        Entrega $entrega,
        float $obtenido,
        float $total,
        bool $completa,
        ?string $retroalimentacion,
        int $usuarioId,
    ): void {
        if (! $completa) {
            /*
             * A medias: se guarda lo evaluado y la retroalimentación, y la
             * entrega vuelve a PENDIENTE de nota.
             *
             * Se limpia la calificación anterior a propósito. Si el docente
             * reabre una ya calificada y deja un criterio en blanco, dejar el
             * número viejo diría que la nota corresponde a esta evaluación
             * cuando ya no.
             */
            $entrega->update([
                'retroalimentacion' => $retroalimentacion,
                'calificacion' => null,
                'estado' => Entrega::ENTREGADA,
                'calificada_por' => null,
                'calificada_en' => null,
            ]);

            return;
        }

        $entrega->update([
            'calificacion' => $this->aEscalaDeLaActividad($obtenido, $total, (float) $entrega->actividad->puntos),
            'retroalimentacion' => $retroalimentacion,
            'estado' => Entrega::CALIFICADA,
            'calificada_por' => $usuarioId,
            'calificada_en' => now(),
        ]);

        // Lo mismo que hace la calificación a mano: el componente del parcial se
        // rehace solo. Que la nota venga de una rúbrica no cambia a dónde va.
        $this->calculador->tras($entrega->fresh());
    }
}
