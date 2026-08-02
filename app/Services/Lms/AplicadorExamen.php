<?php

declare(strict_types=1);

namespace App\Services\Lms;

use App\Models\ControlEscolar\Inscripcion;
use App\Models\Lms\Entrega;
use App\Models\Lms\Examen;
use App\Models\Lms\Intento;
use App\Models\Lms\Reactivo;
use App\Models\Lms\Respuesta;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Aplica un examen: abrir el intento, ir guardando y cerrar calificando.
 *
 * Concentra las tres decisiones que no pueden quedar en el controlador porque
 * de ellas depende que la calificación sea defendible:
 *
 * 1. **El sorteo se fija al INICIAR**, no al pintar la pantalla. Si los
 *    reactivos se sortearan en cada carga, recargar la página sería una forma
 *    gratuita de buscar un examen más fácil.
 * 2. **El reloj también se fija al iniciar.** Mover el límite de minutos a media
 *    aplicación no alarga ni acorta un examen ya en curso.
 * 3. **Se califica al entregar**, con lo que el alumno mandó, contra el reactivo
 *    tal como estaba. Lo que necesite criterio queda marcado y espera al docente.
 */
class AplicadorExamen
{
    public function __construct(
        private readonly CalificadorReactivo $calificador,
        private readonly CalculadorComponente $componentes,
    ) {}

    /**
     * Abre un intento nuevo, con su sorteo y su reloj ya decididos.
     *
     * Si el alumno tiene uno sin entregar, se le devuelve ESE: un examen a medias
     * se continúa, no se reinicia —salir de la pantalla por accidente no puede
     * costar el intento—.
     */
    public function iniciar(Examen $examen, Inscripcion $inscripcion): Intento
    {
        return DB::transaction(function () use ($examen, $inscripcion) {
            $enCurso = Intento::query()
                ->where('examen_id', $examen->id)
                ->where('inscripcion_id', $inscripcion->id)
                ->whereNull('entregado_en')
                ->orderByDesc('numero')
                ->first();

            if ($enCurso !== null) {
                return $enCurso;
            }

            $usados = Intento::query()
                ->where('examen_id', $examen->id)
                ->where('inscripcion_id', $inscripcion->id)
                ->count();

            if (! $examen->permiteOtroIntento($usados)) {
                throw new RuntimeException('Ya usaste todos los intentos de este examen.');
            }

            $orden = $this->sortearReactivos($examen);

            return Intento::create([
                'examen_id' => $examen->id,
                'inscripcion_id' => $inscripcion->id,
                'numero' => $usados + 1,
                'iniciado_en' => now(),
                'expira_en' => $examen->minutos_limite !== null
                    ? now()->addMinutes($examen->minutos_limite)
                    : null,
                'orden_reactivos' => $orden,
            ]);
        });
    }

    /**
     * Qué reactivos y en qué orden le tocan a este intento.
     *
     * @return array<int, int>
     */
    private function sortearReactivos(Examen $examen): array
    {
        $ids = $examen->reactivos()->pluck('reactivos.id')->map(fn ($id) => (int) $id);

        if ($examen->barajar_reactivos || $examen->reactivos_a_presentar !== null) {
            $ids = $ids->shuffle();
        }

        if ($examen->reactivos_a_presentar !== null) {
            $ids = $ids->take($examen->reactivos_a_presentar);
        }

        return $ids->values()->all();
    }

    /**
     * Guarda una respuesta sin calificarla todavía.
     *
     * Se va guardando conforme contesta para que un cierre de navegador o una
     * caída de red no le borren el examen. Calificar aquí sería revelar el
     * resultado antes de tiempo.
     */
    public function guardarRespuesta(Intento $intento, int $reactivoId, mixed $valor): Respuesta
    {
        if ($intento->entregado()) {
            throw new RuntimeException('Este intento ya fue entregado.');
        }

        if (! in_array($reactivoId, $intento->orden_reactivos ?? [], true)) {
            throw new RuntimeException('Ese reactivo no forma parte de este examen.');
        }

        return Respuesta::updateOrCreate(
            ['intento_id' => $intento->id, 'reactivo_id' => $reactivoId],
            ['valor' => ['v' => $valor]],
        );
    }

    /**
     * Cierra el intento: califica lo automático, marca lo que no y sincroniza la
     * entrega.
     *
     * Se usa igual cuando el alumno entrega y cuando se le acabó el tiempo: un
     * examen abandonado se califica con lo que alcanzó a contestar, no se
     * queda sin nota para siempre.
     */
    public function entregar(Intento $intento): Intento
    {
        return DB::transaction(function () use ($intento) {
            $examen = $intento->examen;
            $reactivos = $this->reactivosDelIntento($intento);
            $respuestas = $intento->respuestas()->get()->keyBy('reactivo_id');

            $posibles = 0.0;
            $obtenidos = 0.0;
            $pendiente = false;

            foreach ($reactivos as $reactivo) {
                $puntos = $examen->puntosDe($reactivo);
                $posibles += $puntos;

                $respuesta = $respuestas->get($reactivo->id);

                // No contestó: cero, y no hay nada que revisar a mano.
                if ($respuesta === null) {
                    continue;
                }

                $fraccion = $this->calificador->fraccion($reactivo, $respuesta->valor['v'] ?? null);

                if ($fraccion === null) {
                    $pendiente = true;
                    $respuesta->update(['calificada_por_maquina' => 'no']);

                    continue;
                }

                $ganados = round($fraccion * $puntos, 2);
                $obtenidos += $ganados;

                $respuesta->update([
                    'puntos' => $ganados,
                    'correcta' => $fraccion >= 1.0,
                    'calificada_por_maquina' => 'si',
                ]);
            }

            $intento->update([
                'entregado_en' => now(),
                'puntos_obtenidos' => round($obtenidos, 2),
                'puntos_posibles' => round($posibles, 2),
                'requiere_revision' => $pendiente,
            ]);

            $this->sincronizarEntrega($intento->fresh());

            return $intento->refresh();
        });
    }

    /**
     * Los reactivos EN EL ORDEN que le tocó a este intento, con sus opciones.
     *
     * Se reordena en PHP porque `whereIn` devuelve lo que la base decida, y el
     * orden guardado es justamente lo que hace reproducible un examen barajado.
     *
     * @return Collection<int, Reactivo>
     */
    public function reactivosDelIntento(Intento $intento): Collection
    {
        $ids = $intento->orden_reactivos ?? [];

        $porId = Reactivo::query()->with('opciones')->whereIn('id', $ids)->get()->keyBy('id');

        return collect($ids)->map(fn ($id) => $porId->get($id))->filter()->values();
    }

    /**
     * Lleva el resultado del intento que manda a la entrega de la actividad, y
     * de ahí al componente del parcial.
     *
     * Mientras haya reactivos por revisar la entrega queda ENTREGADA, no
     * calificada: publicar una nota parcial como definitiva es peor que no
     * publicar ninguna.
     */
    private function sincronizarEntrega(Intento $intento): void
    {
        $examen = $intento->examen;
        $actividad = $examen->actividad;

        $intentos = Intento::query()
            ->where('examen_id', $examen->id)
            ->where('inscripcion_id', $intento->inscripcion_id)
            ->whereNotNull('entregado_en')
            ->get();

        $queManda = match ($examen->intento_que_cuenta) {
            Examen::CUENTA_PRIMERO => $intentos->sortBy('numero')->first(),
            Examen::CUENTA_ULTIMO => $intentos->sortByDesc('numero')->first(),
            default => $intentos->sortByDesc(fn (Intento $i) => (float) $i->puntos_obtenidos)->first(),
        } ?? $intento;

        $pendiente = (bool) $queManda->requiere_revision;

        // La calificación de la actividad va en la escala de puntos de ESA
        // actividad, no en la del examen: es la que pondera el componente.
        $calificacion = null;

        if (! $pendiente && (float) $queManda->puntos_posibles > 0) {
            $calificacion = round(
                (float) $queManda->puntos_obtenidos * (float) $actividad->puntos / (float) $queManda->puntos_posibles,
                2,
            );
        }

        $entrega = Entrega::actualizarOReviver(
            ['actividad_id' => $actividad->id, 'inscripcion_id' => $intento->inscripcion_id],
            [
                'estado' => $pendiente ? Entrega::ENTREGADA : Entrega::CALIFICADA,
                'entregada_en' => $queManda->entregado_en,
                'tarde' => $actividad->cierra_en !== null && $queManda->entregado_en?->gt($actividad->cierra_en),
                'calificacion' => $calificacion,
                'calificada_en' => $pendiente ? null : now(),
            ],
        );

        $intento->update(['entrega_id' => $entrega->id]);

        if (! $pendiente) {
            $this->componentes->tras($entrega->fresh());
        }
    }

    /**
     * El docente pone los puntos de un reactivo abierto; si ya no queda ninguno
     * pendiente, el examen se cierra y la nota entra sola.
     */
    public function calificarAMano(Respuesta $respuesta, float $puntos, ?string $comentario = null): Intento
    {
        return DB::transaction(function () use ($respuesta, $puntos, $comentario) {
            $intento = $respuesta->intento;
            $examen = $intento->examen;
            $tope = $examen->puntosDe($respuesta->reactivo);

            $respuesta->update([
                'puntos' => min(max($puntos, 0), $tope),
                'correcta' => $puntos >= $tope,
                'calificada_por_maquina' => 'no',
                'comentario' => $comentario,
            ]);

            $respuestas = $intento->respuestas()->get();
            $faltantes = $respuestas->filter(fn (Respuesta $r) => $r->puntos === null);

            $intento->update([
                'puntos_obtenidos' => round((float) $respuestas->sum(fn (Respuesta $r) => (float) $r->puntos), 2),
                'requiere_revision' => $faltantes->isNotEmpty(),
            ]);

            $this->sincronizarEntrega($intento->fresh());

            return $intento->refresh();
        });
    }
}
