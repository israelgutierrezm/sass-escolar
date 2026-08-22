<?php

declare(strict_types=1);

namespace App\Panel\Tarjetas;

use App\Models\Academico\EsquemaEvaluacion;
use App\Models\ControlEscolar\Acta;
use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\ControlEscolar\CalificacionComponente;
use App\Models\ControlEscolar\Docente;
use App\Models\ControlEscolar\Inscripcion;
use App\Models\Identidad\Usuario;
use App\Panel\TarjetaPanel;
use App\Services\AsentadorActa;

/**
 * Materias con la captura completa y el acta todavía sin firmar.
 *
 * Es lo que detiene el cierre del periodo: el docente ya puso todos los
 * números y falta el gesto que los vuelve historia escolar.
 *
 * ── Quién decide si «ya se puede» es el ASENTADOR ─────────────────────────
 * `AsentadorActa::impedimentos()` es el criterio canónico y el que va a correr
 * al firmar de verdad. Reimplementarlo aquí produciría la peor versión posible
 * de esta tarjeta: la que invita a firmar algo que luego se niega.
 *
 * ── Pero primero se filtra en SQL, y por una razón medida ─────────────────
 * Cada llamada al asentador cuesta unas nueve consultas. Preguntándole por las
 * once materias del ciclo, el panel pagaría cien consultas casi todos los días
 * —los que no hay nada que firmar, que son casi todos—. Así que el SQL descarta
 * a lo bruto (sin alumnos, sin esquema, esquema que no suma 100, captura
 * incompleta) y el servicio sólo opina sobre las que se van a pintar.
 *
 * ── Y NUNCA se pide el acta de trabajo ────────────────────────────────────
 * `actaDeTrabajo()` CREA la fila. Pintar un panel no puede crear actas, así que
 * se arma una en memoria sólo para preguntar.
 */
class ActasPorAsentar implements TarjetaPanel
{
    /** Cada renglón confirmado cuesta ~9 consultas: el tope no es estético. */
    private const TOPE = 5;

    public function __construct(private readonly AsentadorActa $asentador) {}

    public function clave(): string
    {
        return 'actas-por-asentar';
    }

    public function titulo(): string
    {
        return 'Actas por asentar';
    }

    public function permiso(): ?string
    {
        return 'asentar-acta';
    }

    public function tipo(): string
    {
        return 'lista';
    }

    public function ancho(): int
    {
        return 2;
    }

    public function icono(): string
    {
        return 'm16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10';
    }

    public function datos(Usuario $usuario): ?array
    {
        $personaId = $usuario->persona_id;

        /*
         * El único sitio donde se firma es `/captura`, y esa ruta entera exige
         * `capturar-calificaciones`. Quien tenga `asentar-acta` sin el otro
         * vería una cola con un enlace que le responde 403.
         */
        if ($personaId === null || ! $usuario->can('capturar-calificaciones')) {
            return null;
        }

        $universo = $this->universo($usuario, $personaId);

        if ($universo->isEmpty()) {
            return null;
        }

        $inscritos = $this->inscritosPorMateria($universo->pluck('id')->all());
        $candidatas = $this->conCapturaCompleta($universo, $inscritos);

        if ($candidatas->isEmpty()) {
            return null;
        }

        $listas = $candidatas->take(self::TOPE)->filter(
            fn (AsignaturaGrupo $materia) => $this->asentador->impedimentos($this->actaEnMemoria($materia)) === []
        )->values();

        // Cola de trabajo: sin nada esperando firma, no se dibuja. Un «0 actas»
        // todos los días de septiembre enseña a saltarse la tarjeta justo antes
        // de diciembre, que es cuando importa.
        if ($listas->isEmpty()) {
            return null;
        }

        return [
            'renglones' => $listas->map(fn (AsignaturaGrupo $m) => $this->renglon($m, $inscritos))->all(),
            'pie' => $this->pie($candidatas->count(), $listas->count()),
            'enlace' => '/captura',
        ];
    }

    /**
     * Las materias que esta persona podría firmar, sin hidratar nada.
     *
     * Sin relaciones a propósito: en la escuela sin nada por firmar la tarjeta
     * se apaga aquí mismo, sin haber pagado una sola carga ansiosa.
     */
    private function universo(Usuario $usuario, int $personaId)
    {
        $campus = $usuario->campusVisibles();

        // El alcance del docente sale de la tabla `docentes`, no del nombre del
        // rol; y de `titular`, porque sólo el titular puede cerrar el acta.
        $esDocente = Docente::query()->whereKey($personaId)->exists();

        return AsignaturaGrupo::query()
            ->select('id', 'grupo_id', 'plan_materia_id')
            ->whereHas('grupo', fn ($g) => $g
                ->when($campus !== null, fn ($q) => $q->whereIn('campus_id', $campus))
                // Mismo filtro que el selector de `/captura`: la tarjeta y la
                // pantalla no pueden discrepar sobre qué ciclo sigue operable.
                ->whereHas('ciclo', fn ($c) => $c->vigentes()))
            ->when($esDocente, fn ($q) => $q->whereHas('docentes', fn ($d) => $d
                ->where('docentes.persona_id', $personaId)
                ->where('docente_asignatura_grupo.tipo', 'titular')))
            /*
             * Fuera las que ya tienen acta cerrada, incluidas las que están en
             * corrección. No es sólo producto: el acta que se arma en memoria va
             * sin tipo de evaluación, así que la comprobación de «ya hay acta»
             * del servicio no la encontraría y diría que sí se puede firmar una
             * materia ya asentada. Este filtro es lo que sostiene la suposición.
             */
            ->whereDoesntHave('actas', fn ($a) => $a->where('situacion', Acta::CERRADA))
            ->get();
    }

    /** @param  array<int, int>  $materias */
    private function inscritosPorMateria(array $materias)
    {
        return $this->calificables($materias)
            ->selectRaw('asignatura_grupo_id, count(*) as n')
            ->groupBy('asignatura_grupo_id')
            ->pluck('n', 'asignatura_grupo_id');
    }

    /** Los que cuentan para el acta: el que se dio de baja no se califica. */
    private function calificables(array $materias)
    {
        return Inscripcion::query()
            ->whereIn('asignatura_grupo_id', $materias)
            ->whereHas('situacion', fn ($q) => $q->where('clave', '!=', 'baja'));
    }

    /**
     * Descarte a lo bruto: alumnos × componentes contra lo capturado.
     *
     * `capturadas()` es lo que hace que un NULL no cuente. Guardar la hoja
     * escribe una fila por alumno aunque el docente no llegara a ese
     * componente, así que contar FILAS daría por completa una materia en la que
     * nadie puso un número.
     */
    private function conCapturaCompleta($universo, $inscritos)
    {
        $esquemas = EsquemaEvaluacion::query()
            ->selectRaw('plan_materia_id, count(*) as componentes, sum(porcentaje) as suma')
            ->whereIn('plan_materia_id', $universo->pluck('plan_materia_id')->unique())
            ->groupBy('plan_materia_id')
            ->get()
            ->keyBy('plan_materia_id');

        $capturadas = CalificacionComponente::query()
            ->capturadas()
            ->selectRaw('inscripcion.asignatura_grupo_id as ag, count(*) as n')
            ->join('inscripcion', 'inscripcion.id', '=', 'calificaciones_componente.inscripcion_id')
            ->whereIn('inscripcion.id', $this->calificables($universo->pluck('id')->all())->select('inscripcion.id'))
            ->groupBy('inscripcion.asignatura_grupo_id')
            ->pluck('n', 'ag');

        $ids = $universo->filter(function (AsignaturaGrupo $materia) use ($inscritos, $esquemas, $capturadas) {
            $alumnos = (int) ($inscritos[$materia->id] ?? 0);
            $esquema = $esquemas->get($materia->plan_materia_id);

            // Sin alumnos no hay acta; con el esquema incompleto la
            // calificación final ni siquiera se puede calcular.
            if ($alumnos === 0 || $esquema === null || abs((float) $esquema->suma - 100.0) > 0.01) {
                return false;
            }

            return (int) ($capturadas[$materia->id] ?? 0) >= $alumnos * (int) $esquema->componentes;
        })->pluck('id');

        if ($ids->isEmpty()) {
            return collect();
        }

        return AsignaturaGrupo::query()
            ->with([
                'planMateria.asignatura:id,nombre',
                'grupo:id,clave,ciclo_id,campus_id',
                'grupo.ciclo:id,clave,fecha_fin,captura_calif_hasta',
            ])
            ->whereIn('id', $ids)
            ->get()
            // Lo más viejo primero: el acta que lleva más tiempo esperando es la
            // que está deteniendo el cierre.
            ->sortBy(fn (AsignaturaGrupo $m) => (string) ($m->grupo?->ciclo?->fecha_fin ?? ''))
            ->values();
    }

    /** Un acta que NO se guarda: sólo existe para preguntarle al asentador. */
    private function actaEnMemoria(AsignaturaGrupo $materia): Acta
    {
        $acta = new Acta(['asignatura_grupo_id' => $materia->id, 'situacion' => Acta::ABIERTA]);
        $acta->setRelation('asignaturaGrupo', $materia);

        return $acta;
    }

    /** @return array<string, mixed> */
    private function renglon(AsignaturaGrupo $materia, $inscritos): array
    {
        /*
         * La misma regla con la que el asentador marca el acta como
         * extemporánea al firmarla. Su método es privado, así que estas dos
         * líneas están duplicadas a sabiendas: aquí sólo AVISAN, y allá es
         * donde se decide.
         */
        $limite = $materia->grupo?->ciclo?->captura_calif_hasta;
        $tarde = $limite !== null && now()->toDateString() > $limite->toDateString();

        $alumnos = (int) ($inscritos[$materia->id] ?? 0);

        return [
            'etiqueta' => $materia->planMateria?->asignatura?->nombre ?? 'Materia',
            'detalle' => sprintf(
                'Grupo %s · %s',
                $materia->grupo?->clave ?? '?',
                $materia->grupo?->ciclo?->clave ?? '',
            ),
            'valor' => $alumnos === 1 ? '1 alumno' : "{$alumnos} alumnos",
            'pie' => $tarde ? 'Fuera del plazo: el acta saldrá extemporánea.' : null,
            'progreso' => null,
            'alerta' => $tarde,
            'enlace' => "/captura/{$materia->id}",
        ];
    }

    private function pie(int $candidatas, int $pintadas): string
    {
        $sobran = $candidatas - $pintadas;

        if ($sobran > 0) {
            return $sobran === 1
                ? 'y 1 materia más con la captura completa'
                : "y {$sobran} materias más con la captura completa";
        }

        return $pintadas === 1
            ? 'La captura ya está completa; sólo falta firmar.'
            : "{$pintadas} materias esperan la firma del acta.";
    }
}
