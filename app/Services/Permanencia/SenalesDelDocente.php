<?php

declare(strict_types=1);

namespace App\Services\Permanencia;

use App\Configuracion\Ajustes;
use App\Configuracion\CatalogoAjustes;
use App\Exceptions\AvisoParaElUsuario;
use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\Identidad\Usuario;
use App\Models\Permanencia\Alerta;
use App\Models\Permanencia\CategoriaSenal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Lo que un docente puede ver de sus propios alumnos.
 *
 * ── TRES capas, y ninguna sobra ───────────────────────────────────────────
 *  1. El INTERRUPTOR de la escuela (`permanencia.docente_ve_alertas`, apagado
 *     por omisión). El pedido condiciona esto a «cuando la política
 *     institucional lo permita», y eso lo decide la escuela. Apagado, **404**.
 *  2. El PERMISO (`ver-alertas-de-mis-grupos`).
 *  3. La ASIGNACIÓN. **El alcance no lo da el permiso: lo da
 *     `docente_asignatura_grupo`**, igual que la captura de calificaciones. El
 *     permiso dice QUÉ puede hacer; la asignación dice SOBRE QUIÉN.
 *
 * ── Y las categorías SENSIBLES no entran, ni para decir que existen ───────
 * El plan, en su capítulo de riesgos, dice que quien está en un CASO ve «hay una
 * señal financiera pendiente» sin el monto: ahí tiene sentido, porque está
 * atendiendo a la persona y necesita saber que hay otro frente. Aquí no: esto es
 * un LISTADO de sus alumnos, y decirle a un docente que tal alumno tiene una
 * señal financiera ya es decirle que tiene problemas de dinero — que es
 * exactamente lo que el pedido prohíbe. Se excluyen de la consulta, no se
 * esconden en la pantalla.
 *
 * ── Ni el riesgo, ni los casos, ni las intervenciones ─────────────────────
 * Un puntaje compuesto no le sirve a un docente para nada que pueda hacer en su
 * clase, y el expediente del acompañamiento tiene su propio permiso y su propia
 * bitácora de consulta. Lo que sí le sirve —y es lo único que se le da— son las
 * señales de sus materias, con lo que la regla midió.
 */
class SenalesDelDocente
{
    /** ¿La escuela encendió esto? */
    public function laEscuelaLoPermite(): bool
    {
        return app(Ajustes::class)->bool(CatalogoAjustes::PERMANENCIA_DOCENTE_VE_ALERTAS);
    }

    /**
     * O 404. No 403: con el interruptor apagado la pantalla NO EXISTE en esta
     * escuela, y un 403 diría «existe pero no es para ti» sobre algo que la
     * dirección decidió no ofrecer. Mismo criterio que la postulación
     * autogestiva de la bolsa.
     */
    public function exigirQueEsteEncendido(): void
    {
        AvisoParaElUsuario::aMenosQue(
            $this->laEscuelaLoPermite(),
            404,
            'No se encontró la página.',
        );
    }

    /**
     * Sus materias, cada una con los alumnos que tienen alguna señal abierta.
     *
     * @return array<string, mixed>
     */
    public function de(Usuario $docente, ?int $cicloId = null): array
    {
        $materias = $this->susMaterias($docente, $cicloId);

        if ($materias->isEmpty()) {
            return ['materias' => [], 'total' => 0, 'categorias_ocultas' => $this->cuantasSensibles()];
        }

        /*
         * Las personas de SUS grupos, en una sola consulta. Sin esto, preguntar
         * por materia dispara una consulta por materia y luego una por alumno —
         * la N+1 que este proyecto tiene documentada en media docena de sitios.
         */
        $porMateria = $materias->mapWithKeys(fn (AsignaturaGrupo $ag) => [
            $ag->id => $ag->inscripciones->pluck('matriculaOferta.persona_id')->filter()->unique()->values(),
        ]);

        $personas = $porMateria->flatten()->unique()->values();

        if ($personas->isEmpty()) {
            return ['materias' => [], 'total' => 0, 'categorias_ocultas' => $this->cuantasSensibles()];
        }

        $senales = $this->senalesDe($personas->all())->get();

        $porPersona = $senales->groupBy(fn (Alerta $a) => $a->matricula?->persona_id);

        $filas = $materias
            ->map(function (AsignaturaGrupo $ag) use ($porPersona, $docente) {
                $alumnos = $ag->inscripciones
                    ->map(fn ($i) => $i->matriculaOferta)
                    ->filter()
                    ->unique('id')
                    ->map(function ($matricula) use ($porPersona, $ag, $docente) {
                        $suyas = ($porPersona[$matricula->persona_id] ?? collect())
                            /*
                             * Las de OTRA materia se quedan fuera: una señal de
                             * asistencia en la clase de al lado no es asunto de
                             * este docente. Las que no van atadas a ninguna
                             * —las del ciclo entero— sí entran: hablan del
                             * alumno, no de una clase.
                             */
                            ->filter(fn (Alerta $a) => $a->asignatura_grupo_id === null
                                || $a->asignatura_grupo_id === $ag->id)
                            ->values();

                        return [
                            'matricula' => $matricula->matricula,
                            'alumno' => $matricula->persona?->nombreCompleto(),
                            'persona_id' => $matricula->persona_id,
                            'senales' => $suyas->map(fn (Alerta $a) => $a->comoLaVe($docente))->all(),
                        ];
                    })
                    ->filter(fn (array $a) => $a['senales'] !== [])
                    ->sortBy('alumno')
                    ->values();

                return [
                    'id' => $ag->id,
                    'materia' => $ag->planMateria?->asignatura?->nombre,
                    'grupo' => $ag->grupo?->clave,
                    'ciclo' => $ag->grupo?->ciclo?->nombre,
                    'alumnos' => $alumnos->all(),
                ];
            })
            ->filter(fn (array $m) => $m['alumnos'] !== [])
            ->sortBy('materia')
            ->values();

        return [
            'materias' => $filas->all(),
            'total' => $filas->sum(fn (array $m) => count($m['alumnos'])),
            /*
             * Cuántas categorías quedan fuera. Se DICE en vez de callarse: sin
             * el dato, un docente que ve la lista vacía cree que a sus alumnos
             * no les pasa nada, y lo que pasa es que lo suyo no se le enseña.
             * Es la misma lección que las notas reservadas de un caso.
             */
            'categorias_ocultas' => $this->cuantasSensibles(),
        ];
    }

    /**
     * Sus materias del ciclo, por la ASIGNACIÓN.
     *
     * **El filtro va por `docentes.persona_id`, no por `personas.id`.** Es la
     * trampa que este proyecto ya documentó: escrito del otro modo el alcance no
     * se aplica nunca y el docente ve la escuela entera.
     *
     * @return Collection<int, AsignaturaGrupo>
     */
    private function susMaterias(Usuario $docente, ?int $cicloId)
    {
        return AsignaturaGrupo::query()
            ->whereHas('docentes', fn (Builder $q) => $q->where('docentes.persona_id', $docente->persona_id))
            ->when($cicloId !== null, fn (Builder $q) => $q->whereHas(
                'grupo', fn (Builder $g) => $g->where('ciclo_id', $cicloId),
            ))
            ->with([
                'planMateria:id,asignatura_id',
                'planMateria.asignatura:id,nombre',
                'grupo:id,clave,ciclo_id',
                'grupo.ciclo:id,nombre',
                'inscripciones:id,asignatura_grupo_id,matricula_oferta_id',
                'inscripciones.matriculaOferta:id,persona_id,matricula',
                'inscripciones.matriculaOferta.persona:id,nombre,primer_apellido,segundo_apellido',
            ])
            ->get();
    }

    /**
     * Las señales abiertas de estas personas, SIN las categorías sensibles.
     *
     * @param  array<int, int>  $personas
     */
    private function senalesDe(array $personas): Builder
    {
        return Alerta::query()
            ->abiertas()
            /*
             * Y sólo las VALIDADAS. Una sin revisar puede ser un dato mal
             * capturado —una lista que nadie pasó—, y ponerla delante del
             * docente del alumno antes de que alguien la mire es el mismo daño
             * que avisarle al alumno de algo que mañana se descarta.
             */
            ->where('estado_triage', Alerta::VALIDADA)
            ->whereHas('categoria', fn (Builder $c) => $c->where('sensible', false))
            ->whereHas('matricula', fn (Builder $m) => $m->whereIn('persona_id', $personas))
            ->with([
                'matricula:id,persona_id,matricula',
                'matricula.persona:id,nombre,primer_apellido,segundo_apellido',
                'categoria', 'regla:id,nombre', 'version',
                'asignaturaGrupo:id,plan_materia_id',
                'asignaturaGrupo.planMateria:id,asignatura_id',
                'asignaturaGrupo.planMateria.asignatura:id,nombre',
            ]);
    }

    private function cuantasSensibles(): int
    {
        return CategoriaSenal::query()->activas()->where('sensible', true)->count();
    }
}
