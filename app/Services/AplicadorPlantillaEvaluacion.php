<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Academico\EsquemaEvaluacion;
use App\Models\Academico\PlanEstudio;
use App\Models\Academico\PlanMateria;
use App\Models\Academico\PlantillaComponente;
use App\Models\Academico\PlantillaEvaluacion;
use App\Models\ControlEscolar\CalificacionComponente;
use App\Models\Lms\Actividad;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Aplica una plantilla de evaluación a las materias de un plan.
 *
 * Los componentes se MATERIALIZAN como filas de `esquema_evaluacion`, no se
 * leen en vivo desde la plantilla. Es a propósito: `calificaciones_componente`
 * apunta a `esquema_evaluacion_id`, y resolver el esquema en tiempo real
 * obligaría a un segundo camino —calificaciones que apuntan a veces a una tabla
 * y a veces a otra— para no ganar nada.
 *
 * La regla que gobierna todo lo demás: **una materia con trabajo colgando de su
 * esquema NO se re-aplica**. Son dos cosas distintas y las dos bloquean:
 *
 *  - **Calificaciones capturadas.** Cambiarle el criterio a media evaluación
 *    dejaría huérfano lo capturado y movería números que un docente ya asentó.
 *  - **Actividades del LMS que ponderan en su esquema.** El reemplazo borra las
 *    filas viejas de `esquema_evaluacion` con `forceDelete`, y eso dispara el
 *    `nullOnDelete` de `actividades`: cada actividad del curso se queda SIN
 *    componente, en silencio y sin error. Un semestre de tareas dejaría de
 *    ponderar y nadie se enteraría hasta cerrar el acta.
 *
 * Esas materias se reportan como bloqueadas CON SU MOTIVO; no se tocan ni se
 * fallan en silencio. Y ojo: esto no estorba la primera aplicación —una materia
 * sin esquema no tiene nada colgando—; sólo la re-aplicación sobre trabajo ya
 * hecho, que es exactamente cuando hay algo que perder.
 */
class AplicadorPlantillaEvaluacion
{
    public function __construct(private readonly RepartidorPorcentajes $repartidor) {}

    /**
     * Aplica la plantilla a una materia, reemplazando su esquema.
     *
     * @throws RuntimeException si la materia tiene calificaciones capturadas o
     *                          actividades ponderando en su esquema, o si la
     *                          plantilla no suma 100%.
     */
    public function aplicarAMateria(PlantillaEvaluacion $plantilla, PlanMateria $materia): void
    {
        if (! $plantilla->estaCompleta()) {
            throw new RuntimeException(
                sprintf('La plantilla "%s" suma %s%% y debe sumar 100%% para poder aplicarse.',
                    $plantilla->nombre, $this->formatear($plantilla->sumaPorcentajes()))
            );
        }

        $motivo = $this->motivoParaNoAplicar($materia);

        if ($motivo !== null) {
            throw new RuntimeException("No se puede reemplazar su esquema: {$motivo}.");
        }

        DB::transaction(function () use ($plantilla, $materia): void {
            $viejos = EsquemaEvaluacion::query()
                ->where('plan_materia_id', $materia->id)
                ->pluck('id');

            /*
             * Los rastros en blanco se van con el esquema que reemplazan.
             *
             * Son filas de `calificaciones_componente` con la calificación en
             * NULL: el docente guardó la hoja sin llegar a ese componente. No
             * cuentan como capturas —por eso la materia no está bloqueada— pero
             * el borrado de abajo es DURO, así que dejarlas atrás hace que la
             * foránea reviente y la aplicación termine en un 500. Antes no se
             * notaba porque esas mismas filas bloqueaban la re-aplicación: se
             * cambiaba un aviso claro por un error de base.
             */
            CalificacionComponente::query()->whereIn('esquema_evaluacion_id', $viejos)->forceDelete();

            EsquemaEvaluacion::query()->where('plan_materia_id', $materia->id)->forceDelete();

            foreach ($plantilla->componentes as $componente) {
                EsquemaEvaluacion::create([
                    'plan_materia_id' => $materia->id,
                    'componente' => $componente->componente,
                    'parcial' => $componente->parcial,
                    'porcentaje' => $componente->porcentaje,
                    'orden' => $componente->orden,
                ]);
            }

            $materia->update(['plantilla_evaluacion_id' => $plantilla->id]);
        });
    }

    /**
     * Aplica la plantilla a todas las materias del plan y la fija como su
     * criterio por defecto.
     *
     * @param  bool  $respetarPersonalizadas  Si true, no toca las materias que
     *                                        armaron su esquema a mano.
     * @return array{aplicadas: int, bloqueadas: array<int, array{materia: string, motivo: string}>, omitidas: int}
     */
    public function aplicarAPlan(PlantillaEvaluacion $plantilla, PlanEstudio $plan, bool $respetarPersonalizadas = true): array
    {
        $materias = PlanMateria::query()
            ->with('asignatura:id,nombre')
            ->where('plan_id', $plan->id)
            ->get();

        $resultado = $this->aplicarALote($plantilla, $materias, $respetarPersonalizadas);

        $plan->update(['plantilla_evaluacion_id' => $plantilla->id]);

        return $resultado;
    }

    /**
     * Re-propaga la plantilla a las materias que la usan. Es lo que hace que
     * editar el criterio una vez lo cambie en todas.
     *
     * @return array{aplicadas: int, bloqueadas: array<int, array{materia: string, motivo: string}>, omitidas: int}
     */
    public function repropagar(PlantillaEvaluacion $plantilla): array
    {
        $materias = PlanMateria::query()
            ->with('asignatura:id,nombre')
            ->where('plantilla_evaluacion_id', $plantilla->id)
            ->get();

        // Aquí no se respetan personalizadas: estas materias YA declararon que
        // siguen esta plantilla. Si alguien editó su esquema a mano, al hacerlo
        // se desligó (plantilla_evaluacion_id quedó en NULL) y no está en esta
        // lista.
        return $this->aplicarALote($plantilla, $materias, respetarPersonalizadas: false);
    }

    /**
     * Materias que no se podrán re-aplicar, con el porqué de cada una. Se
     * consulta ANTES de guardar, para advertir en vez de sorprender.
     *
     * @return array<int, array{materia: string, motivo: string}>
     */
    public function materiasBloqueadas(PlantillaEvaluacion $plantilla): array
    {
        return PlanMateria::query()
            ->with('asignatura:id,nombre')
            ->where('plantilla_evaluacion_id', $plantilla->id)
            ->get()
            ->map(fn (PlanMateria $m) => [
                'materia' => $this->nombrar($m),
                'motivo' => $this->motivoParaNoAplicar($m),
            ])
            ->filter(fn (array $f) => $f['motivo'] !== null)
            ->values()
            ->all();
    }

    /**
     * Reparte 100% en partes iguales entre los componentes de la plantilla.
     * Reemplaza los porcentajes actuales.
     */
    public function repartirEquitativo(PlantillaEvaluacion $plantilla): void
    {
        $componentes = $plantilla->componentes()->get();
        $porcentajes = $this->repartidor->equitativo($componentes->count());

        DB::transaction(function () use ($componentes, $porcentajes): void {
            foreach ($componentes->values() as $i => $componente) {
                /** @var PlantillaComponente $componente */
                $componente->update(['porcentaje' => $porcentajes[$i]]);
            }
        });
    }

    /**
     * @param  Collection<int, PlanMateria>  $materias
     * @return array{aplicadas: int, bloqueadas: array<int, array{materia: string, motivo: string}>, omitidas: int}
     */
    private function aplicarALote(PlantillaEvaluacion $plantilla, Collection $materias, bool $respetarPersonalizadas): array
    {
        if (! $plantilla->estaCompleta()) {
            throw new RuntimeException(
                sprintf('La plantilla "%s" suma %s%% y debe sumar 100%% para poder aplicarse.',
                    $plantilla->nombre, $this->formatear($plantilla->sumaPorcentajes()))
            );
        }

        $aplicadas = 0;
        $omitidas = 0;
        $bloqueadas = [];

        foreach ($materias as $materia) {
            $motivo = $this->motivoParaNoAplicar($materia);

            if ($motivo !== null) {
                // Con el motivo al lado, no con la sola lista de nombres: dos
                // razones distintas la bloquean y la salida de cada una es
                // otra —vaciar celdas de captura, o mover actividades a otro
                // componente—. Un aviso que no distingue no se puede accionar.
                $bloqueadas[] = ['materia' => $this->nombrar($materia), 'motivo' => $motivo];

                continue;
            }

            // Una materia con esquema propio y sin plantilla declarada se armó
            // a mano: se respeta salvo que se pida explícitamente pisarla.
            $personalizada = $materia->plantilla_evaluacion_id === null
                && $materia->esquemaEvaluacion()->exists();

            if ($respetarPersonalizadas && $personalizada) {
                $omitidas++;

                continue;
            }

            $this->aplicarAMateria($plantilla, $materia);
            $aplicadas++;
        }

        return ['aplicadas' => $aplicadas, 'bloqueadas' => $bloqueadas, 'omitidas' => $omitidas];
    }

    /**
     * Por qué NO se le puede reemplazar el esquema, o null si sí se puede.
     *
     * Las dos razones se preguntan aquí y no en dos sitios, porque las dos
     * terminan en el mismo lugar —la lista de bloqueadas— y separarlas hace que
     * una se olvide al agregar la siguiente.
     *
     * El orden importa poco pero no es arbitrario: si hay calificaciones, eso es
     * lo más grave y lo que se dice; ver además el conteo de actividades no
     * cambiaría qué hacer.
     */
    private function motivoParaNoAplicar(PlanMateria $materia): ?string
    {
        if ($this->tieneCapturas($materia)) {
            return 'ya tiene calificaciones capturadas, y cambiar el criterio ahora movería números que un docente ya puso';
        }

        $actividades = $this->actividadesQueSeQuedarianSueltas($materia);

        if ($actividades > 0) {
            return $actividades === 1
                ? 'tiene 1 actividad que pondera en su esquema y se quedaría sin componente'
                : "tiene {$actividades} actividades que ponderan en su esquema y se quedarían sin componente";
        }

        return null;
    }

    /**
     * Cuántas actividades del LMS perderían su componente con el reemplazo.
     *
     * Cuenta las de TODOS los cursos —el de la plantilla del plan y el de cada
     * grupo—, porque `CopiadorDeCurso` copia la actividad al grupo apuntando al
     * MISMO `esquema_evaluacion_id`: el componente es del plan, no del grupo.
     * Contar sólo las del plan dejaría pasar el reemplazo y desengancharía en
     * silencio las de todos los grupos abiertos.
     */
    private function actividadesQueSeQuedarianSueltas(PlanMateria $materia): int
    {
        return Actividad::query()
            ->whereIn(
                'esquema_evaluacion_id',
                EsquemaEvaluacion::query()->where('plan_materia_id', $materia->id)->select('id')
            )
            ->count();
    }

    /** ¿Algún alumno ya tiene calificación capturada contra el esquema de esta materia? */
    private function tieneCapturas(PlanMateria $materia): bool
    {
        return CalificacionComponente::query()
            ->capturadas()
            ->whereIn(
                'esquema_evaluacion_id',
                EsquemaEvaluacion::query()->where('plan_materia_id', $materia->id)->select('id')
            )
            ->exists();
    }

    private function nombrar(PlanMateria $materia): string
    {
        return trim($materia->clave_en_plan.' '.($materia->asignatura?->nombre ?? ''));
    }

    private function formatear(float $numero): string
    {
        return rtrim(rtrim(number_format($numero, 2, '.', ''), '0'), '.');
    }
}
