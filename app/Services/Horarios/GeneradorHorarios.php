<?php

declare(strict_types=1);

namespace App\Services\Horarios;

use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\ControlEscolar\Aula;
use App\Models\ControlEscolar\DisponibilidadDocente;
use App\Models\ControlEscolar\Grupo;
use App\Models\ControlEscolar\ReglaHorario;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Propone un horario. No lo aplica.
 *
 * Devuelve bloques y, sobre todo, DIAGNÓSTICOS: qué no pudo colocar y por qué.
 * Un generador que sólo devuelve lo que le salió bien obliga a comparar a mano
 * lo pedido contra lo entregado para descubrir qué falta, que es justo el
 * trabajo que se venía a evitar.
 *
 * ── Por qué no aplica ──────────────────────────────────────────────────────
 * Un horario es de las cosas más caras de deshacer en una escuela: cuelgan de
 * él las inscripciones, las aulas, los avisos a los alumnos. Se propone, se
 * revisa y alguien decide. Además así la funcionalidad es opcional de verdad:
 * generar no cambia nada hasta que se acepta.
 *
 * ── El algoritmo, y por qué éste ───────────────────────────────────────────
 * Voraz con la materia MÁS RESTRINGIDA primero: la que tiene menos docentes
 * aptos, más horas y menos huecos donde cabe. Es la heurística estándar de
 * asignación con restricciones, y la razón es sencilla: si se deja para el
 * final la materia que sólo puede dar una persona un día a la semana, para
 * entonces ese hueco ya está tomado por algo que cabía en veinte lugares.
 *
 * No es un solucionador exacto y no pretende serlo. Un horario escolar real
 * tiene tantas restricciones blandas —«a ese profe no le gustan los viernes»—
 * que un óptimo matemático tampoco sería el horario que la escuela quiere. Lo
 * que sí garantiza es que lo que propone es VÁLIDO: nunca dos clases del mismo
 * grupo a la vez, ni un docente en dos aulas, ni un salón con dos grupos.
 *
 * Lo que no cabe se reporta con su motivo, para que una persona lo resuelva.
 */
class GeneradorHorarios
{
    /**
     * @param  int[]  $grupoIds  los grupos a los que armarles horario
     * @return array<string, mixed>
     */
    public function paraGrupos(array $grupoIds): array
    {
        $grupos = Grupo::query()
            ->with(['campus:id,nombre', 'ciclo:id,clave'])
            ->whereIn('id', $grupoIds)
            ->get();

        if ($grupos->isEmpty()) {
            return $this->vacio('No se eligió ningún grupo.');
        }

        $regla = ReglaHorario::resolver(
            $grupos->first()->ciclo_id,
            $grupos->first()->campus_id,
        );

        if ($regla === null) {
            return $this->vacio(
                'No hay reglas de horario configuradas. Se necesitan para saber a qué hora abre la escuela y cuánto dura una clase.'
            );
        }

        $materias = $this->materiasDe($grupos->pluck('id')->all());

        if ($materias->isEmpty()) {
            return $this->vacio('Esos grupos no tienen materias abiertas todavía.');
        }

        $agenda = $this->agendaConLoQueYaExiste($materias, $grupos);
        $aptitudes = $this->aptitudesPorAsignatura($materias);
        $disponibilidad = $this->disponibilidadPorDocente($grupos->first()->ciclo_id);
        $aulas = $this->aulasPorCampus($grupos->pluck('campus_id')->unique()->all());

        $propuesta = [];
        $sinColocar = [];

        foreach ($this->masRestringidaPrimero($materias, $aptitudes, $regla) as $materia) {
            $resultado = $this->colocar($materia, $regla, $agenda, $aptitudes, $disponibilidad, $aulas);

            foreach ($resultado['bloques'] as $bloque) {
                $agenda->ocupar($bloque);
                $propuesta[] = $bloque;
            }

            if ($resultado['faltan'] > 0) {
                $sinColocar[] = [
                    'asignatura_grupo_id' => $materia['id'],
                    'materia' => $materia['nombre'],
                    'grupo' => $materia['grupo_clave'],
                    'horas_pedidas' => $materia['horas'],
                    'horas_colocadas' => $materia['horas'] - $resultado['faltan'],
                    'motivo' => $resultado['motivo'],
                ];
            }
        }

        return [
            'ok' => true,
            'bloques' => array_map(fn (Bloque $b) => $b->paraPantalla(), $propuesta),
            'sin_colocar' => $sinColocar,
            'resumen' => $this->resumen($propuesta, $materias, $sinColocar),
            'regla' => ['id' => $regla->id, 'nombre' => $regla->nombre],
            'aviso' => null,
        ];
    }

    // ── Colocar una materia ────────────────────────────────────────────────

    /**
     * Reparte las horas de una materia en sesiones que quepan.
     *
     * Primero elige DOCENTE y luego horas, no al revés: el docente restringe
     * mucho más que el aula —hay más salones que gente que sepa dar Cálculo— y
     * probar horas contra un docente que no puede ninguna es trabajo tirado.
     *
     * @param  array<string, mixed>  $materia
     * @return array{bloques: Bloque[], faltan: int, motivo: ?string}
     */
    private function colocar(
        array $materia,
        ReglaHorario $regla,
        Agenda $agenda,
        array $aptitudes,
        array $disponibilidad,
        array $aulas,
    ): array {
        $candidatos = $this->candidatosPara($materia, $aptitudes, $regla, $agenda);

        if ($candidatos === []) {
            return [
                'bloques' => [],
                'faltan' => $materia['horas'],
                'motivo' => $materia['persona_id'] !== null
                    ? 'El docente asignado no tiene disponibilidad capturada.'
                    : 'Nadie tiene registrada esta materia como una que pueda impartir.',
            ];
        }

        /*
         * Cada tanteo va sobre una COPIA de la agenda.
         *
         * Un intento que coloca 3 de 5 horas y se descarta no debe dejar esas 3
         * ocupando el calendario: quien lo llama es el que ocupa lo que se
         * acepta. Sin la copia, el horario final salía con huecos reservados
         * por clases que nunca existieron.
         */
        $mejor = null;

        foreach ($candidatos as $personaId) {
            $intento = $this->repartirCon($personaId, $materia, $regla, $agenda->copia(), $disponibilidad, $aulas);

            if ($intento['faltan'] === 0) {
                return $intento;
            }

            // Se queda el que más horas alcanzó a cubrir: media materia colocada
            // deja ver dónde aprieta, y un cero no dice nada.
            if ($mejor === null || $intento['faltan'] < $mejor['faltan']) {
                $mejor = $intento;
            }
        }

        $mejor['motivo'] ??= 'No hay huecos suficientes en la disponibilidad de quien puede darla.';

        return $mejor;
    }

    /**
     * @param  array<string, mixed>  $materia
     * @return array{bloques: Bloque[], faltan: int, motivo: ?string}
     */
    private function repartirCon(
        int $personaId,
        array $materia,
        ReglaHorario $regla,
        Agenda $agenda,
        array $disponibilidad,
        array $aulas,
    ): array {
        $minutosPendientes = $materia['horas'] * 60;
        $bloques = [];
        $sesionesPorDia = [];
        $motivo = null;

        // Se prueba primero la sesión más larga permitida: una materia de 5
        // horas en 3+2 estorba menos que en cinco sesiones de una.
        $tamanos = range($regla->bloques_max_por_sesion, $regla->bloques_min_por_sesion);

        foreach ($regla->diasLaborales() as $dia) {
            if ($minutosPendientes <= 0) {
                break;
            }

            foreach ($tamanos as $enBloques) {
                $duracion = $enBloques * $regla->minutos_bloque;

                if ($duracion > $minutosPendientes) {
                    continue;
                }

                if (($sesionesPorDia[$dia] ?? 0) >= $regla->max_sesiones_por_dia) {
                    break;
                }

                $colocado = $this->buscarHueco(
                    $materia, $dia, $duracion, $personaId, $regla, $agenda, $disponibilidad, $aulas, $bloques,
                );

                if ($colocado === null) {
                    continue;
                }

                $bloques[] = $colocado;
                $agenda->ocupar($colocado); // para que la siguiente sesión no se le encime
                $sesionesPorDia[$dia] = ($sesionesPorDia[$dia] ?? 0) + 1;
                $minutosPendientes -= $duracion;

                break; // a este día ya se le puso lo que cabía
            }
        }

        if ($minutosPendientes > 0 && $motivo === null) {
            $motivo = 'No hay huecos suficientes: se colocó lo que cupo.';
        }

        return [
            'bloques' => $bloques,
            'faltan' => (int) ceil($minutosPendientes / 60),
            'motivo' => $motivo,
        ];
    }

    /**
     * El primer hueco del día donde quepa todo: docente libre y disponible,
     * grupo libre, aula libre y dentro de los topes de carga.
     *
     * @param  array<string, mixed>  $materia
     * @param  Bloque[]  $yaPuestos
     */
    private function buscarHueco(
        array $materia,
        int $dia,
        int $duracion,
        int $personaId,
        ReglaHorario $regla,
        Agenda $agenda,
        array $disponibilidad,
        array $aulas,
        array $yaPuestos,
    ): ?Bloque {
        $topeDia = $regla->horas_max_dia_docente !== null ? $regla->horas_max_dia_docente * 60 : null;
        $topeSemana = $regla->horas_max_semana_docente !== null ? $regla->horas_max_semana_docente * 60 : null;

        if ($topeDia !== null && $agenda->minutosDelDia($personaId, $dia) + $duracion > $topeDia) {
            return null;
        }

        if ($topeSemana !== null && $agenda->minutosDeLaSemana($personaId) + $duracion > $topeSemana) {
            return null;
        }

        foreach ($regla->bloquesDelDia() as $inicio) {
            $fin = $inicio + $duracion;

            if ($fin > DisponibilidadDocente::aMinutos((string) $regla->hora_cierre)) {
                break;
            }

            $franja = $this->franjaQueCubre($disponibilidad[$personaId] ?? [], $dia, $inicio, $fin);

            if ($franja === null) {
                continue;
            }

            $modalidad = $franja->modalidad === DisponibilidadDocente::AMBAS
                ? DisponibilidadDocente::PRESENCIAL
                : $franja->modalidad;

            $tentativo = new Bloque(
                $materia['id'], $dia, $inicio, $fin, $personaId, null,
                $modalidad === DisponibilidadDocente::EN_LINEA ? 'en_linea' : 'presencial',
            );

            if (! $agenda->grupoLibre($materia['grupo_id'], $tentativo)) {
                continue;
            }

            if (! $this->dejaElDiaCompacto($tentativo, $materia, $regla, $agenda)) {
                continue;
            }

            if (! $agenda->docenteLibre($personaId, $tentativo, $regla->minutos_descanso_docente)) {
                continue;
            }

            // Una clase en línea no necesita salón; una presencial, sí.
            if ($tentativo->modalidad === 'en_linea') {
                return $tentativo;
            }

            $aula = $this->aulaLibre($aulas[$materia['campus_id']] ?? [], $tentativo, $agenda);

            if ($aula !== null) {
                return $tentativo->conAula($aula);
            }
        }

        return null;
    }

    /**
     * ¿Este bloque deja el día del grupo sin huecos?
     *
     * Un grupo con clase a las 7 y luego a las 11 tiene treinta alumnos sin
     * nada que hacer en medio. Cuando la escuela dice que no quiere huecos, un
     * bloque sólo entra si queda PEGADO a lo que ya hay ese día —o si es el
     * primero—.
     *
     * Se comprueba al colocar y no al final a propósito: un horario que ya
     * quedó con huecos no se puede compactar sin mover todo lo demás, y mover
     * lo demás es rehacerlo.
     *
     * Lo que no encuentre hueco contiguo se reporta como no colocado, igual que
     * cualquier otra restricción. Vale más decir «no cupo sin dejar huecos» que
     * entregar en silencio lo que la escuela pidió no tener.
     *
     * @param  array<string, mixed>  $materia
     */
    private function dejaElDiaCompacto(Bloque $bloque, array $materia, ReglaHorario $regla, Agenda $agenda): bool
    {
        if ($regla->permite_huecos_grupo) {
            return true;
        }

        $delDia = $agenda->bloquesDelGrupo((int) $materia['grupo_id'], $bloque->dia);

        // El primero del día no puede dejar hueco: no hay contra qué.
        if ($delDia === []) {
            return true;
        }

        foreach ($delDia as $ocupado) {
            if ($bloque->inicio === $ocupado->fin || $bloque->fin === $ocupado->inicio) {
                return true;
            }
        }

        return false;
    }

    /** @param  DisponibilidadDocente[]  $franjas */
    private function franjaQueCubre(array $franjas, int $dia, int $inicio, int $fin): ?DisponibilidadDocente
    {
        foreach ($franjas as $franja) {
            if ($franja->dia_semana === $dia
                && $franja->inicioEnMinutos() <= $inicio
                && $franja->finEnMinutos() >= $fin) {
                return $franja;
            }
        }

        return null;
    }

    /** @param  int[]  $delCampus */
    private function aulaLibre(array $delCampus, Bloque $bloque, Agenda $agenda): ?int
    {
        foreach ($delCampus as $aulaId) {
            if ($agenda->aulaLibre($aulaId, $bloque)) {
                return $aulaId;
            }
        }

        return null;
    }

    // ── A quién se le puede dar ────────────────────────────────────────────

    /**
     * Quiénes pueden dar esta materia, en orden.
     *
     * Si ya tiene docente asignado, ÉSE y nadie más: el generador acomoda
     * horas, no reasigna a la gente. Cambiar de titular es una decisión de la
     * coordinación y hacerlo en silencio sería lo peor que podría hacer.
     *
     * @param  array<string, mixed>  $materia
     * @return int[]
     */
    private function candidatosPara(array $materia, array $aptitudes, ReglaHorario $regla, Agenda $agenda): array
    {
        if ($materia['persona_id'] !== null) {
            return [$materia['persona_id']];
        }

        $aptos = $aptitudes[$materia['asignatura_id']] ?? [];

        /*
         * El orden en que se les ofrece, según la política de reparto.
         *
         * Manda la PREFERENCIA: quien la prefiere antes que quien sólo puede, y
         * de último quien la daría sólo si no hay de otra. Eso no se negocia,
         * porque un horario técnicamente correcto que ignora lo que la gente
         * quiere dar no es el horario que la escuela va a usar.
         *
         * A igual preferencia decide el reparto:
         *
         *  · `repartir` ofrece primero a quien MENOS carga lleva. Sin esto, el
         *    primero de la lista se lleva todo lo que le quepa y los demás se
         *    quedan sin nada: el motor siempre le pregunta a él antes.
         *  · `concentrar` hace lo contrario a propósito —al que ya trae carga—,
         *    para juntar las materias en menos gente y menos días. Es lo que
         *    quiere una escuela con docentes que vienen de lejos.
         */
        $concentrar = $regla->reparto === ReglaHorario::CONCENTRAR;

        $orden = [];

        foreach ($aptos as $personaId => $preferencia) {
            $carga = $agenda->minutosDeLaSemana($personaId);

            $orden[$personaId] = [-$preferencia, $concentrar ? -$carga : $carga];
        }

        uasort($orden, fn (array $a, array $b) => $a <=> $b);

        return array_keys($orden);
    }

    // ── Orden de ataque ────────────────────────────────────────────────────

    /**
     * La materia más difícil primero.
     *
     * Dificultad = pocos docentes aptos, muchas horas. Dejar para el final la
     * que sólo puede dar una persona garantiza que su único hueco ya esté
     * ocupado por algo que cabía en cualquier lado.
     *
     * @param  Collection<int, array<string, mixed>>  $materias
     * @return array<int, array<string, mixed>>
     */
    private function masRestringidaPrimero(Collection $materias, array $aptitudes, ReglaHorario $regla): array
    {
        return $materias
            ->sortBy(function (array $m) use ($aptitudes) {
                $cuantosPueden = $m['persona_id'] !== null
                    ? 1
                    : count($aptitudes[$m['asignatura_id']] ?? []);

                // Sin candidatos va primero: su diagnóstico es el que importa.
                return [$cuantosPueden === 0 ? -1 : $cuantosPueden, -$m['horas']];
            })
            ->values()
            ->all();
    }

    // ── Los datos ──────────────────────────────────────────────────────────

    /**
     * Las materias abiertas de esos grupos, con sus horas y su docente si lo hay.
     *
     * @param  int[]  $grupoIds
     * @return Collection<int, array<string, mixed>>
     */
    private function materiasDe(array $grupoIds): Collection
    {
        return AsignaturaGrupo::query()
            ->with([
                'planMateria.asignatura:id,nombre,horas_teoria,horas_practica',
                'grupo:id,clave,campus_id',
                'docentes:persona_id',
            ])
            ->whereIn('grupo_id', $grupoIds)
            ->get()
            ->map(function (AsignaturaGrupo $ag) {
                $asignatura = $ag->planMateria?->asignatura;

                /*
                 * Las horas de la semana salen de teoría + práctica.
                 *
                 * Las de acompañamiento e independientes NO se agendan: son
                 * trabajo del alumno fuera del aula, y meterlas al horario
                 * pediría el doble de salones de los que hacen falta.
                 */
                $horas = (int) (($asignatura?->horas_teoria ?? 0) + ($asignatura?->horas_practica ?? 0));

                return [
                    'id' => $ag->id,
                    'grupo_id' => $ag->grupo_id,
                    'grupo_clave' => $ag->grupo?->clave,
                    'campus_id' => $ag->grupo?->campus_id,
                    'asignatura_id' => $asignatura?->id,
                    'nombre' => $asignatura?->nombre ?? 'Materia',
                    'horas' => $horas,
                    'persona_id' => $ag->docentes->first()?->persona_id,
                ];
            })
            // Una materia sin horas declaradas no se puede agendar, y decirlo
            // aquí sería ruido: el diagnóstico que importa es el del catálogo.
            ->filter(fn (array $m) => $m['horas'] > 0)
            ->values();
    }

    private function agendaConLoQueYaExiste(Collection $materias, Collection $grupos): Agenda
    {
        /*
         * Lo que ya estaba en la base entra a la agenda, CON su docente.
         *
         * Se carga desde `AgendaDeLaEscuela` y no aquí porque el aplicador
         * necesita exactamente lo mismo: tenerlo dos veces fue lo que dejó que
         * las dos copias olvidaran al docente y con él los topes de carga entre
         * corridas.
         */
        return AgendaDeLaEscuela::actual();
    }

    /** @return array<int, array<int, int>> asignatura => [persona => preferencia] */
    private function aptitudesPorAsignatura(Collection $materias): array
    {
        $filas = DB::table('asignatura_docente')
            ->whereIn('asignatura_id', $materias->pluck('asignatura_id')->filter()->unique())
            ->get();

        $porAsignatura = [];

        foreach ($filas as $fila) {
            $porAsignatura[$fila->asignatura_id][$fila->persona_id] = (int) $fila->preferencia;
        }

        return $porAsignatura;
    }

    /** @return array<int, DisponibilidadDocente[]> */
    private function disponibilidadPorDocente(?int $cicloId): array
    {
        return DisponibilidadDocente::paraElCiclo((int) $cicloId)
            ->groupBy('persona_id')
            ->map(fn ($franjas) => $franjas->all())
            ->all();
    }

    /**
     * @param  int[]  $campusIds
     * @return array<int, int[]>
     */
    private function aulasPorCampus(array $campusIds): array
    {
        return Aula::query()
            ->whereIn('campus_id', $campusIds)
            ->orderBy('capacidad')
            ->get(['id', 'campus_id'])
            ->groupBy('campus_id')
            ->map(fn ($aulas) => $aulas->pluck('id')->all())
            ->all();
    }

    // ── Lo que se devuelve ─────────────────────────────────────────────────

    /**
     * @param  Bloque[]  $propuesta
     * @return array<string, mixed>
     */
    private function resumen(array $propuesta, Collection $materias, array $sinColocar): array
    {
        $minutos = array_sum(array_map(fn (Bloque $b) => $b->duracionEnMinutos(), $propuesta));

        return [
            'materias' => $materias->count(),
            'materias_completas' => $materias->count() - count($sinColocar),
            'bloques' => count($propuesta),
            'horas_colocadas' => round($minutos / 60, 1),
            'horas_pedidas' => (int) $materias->sum('horas'),
            'sin_docente' => count(array_filter($propuesta, fn (Bloque $b) => $b->personaId === null)),
        ];
    }

    /** @return array<string, mixed> */
    private function vacio(string $aviso): array
    {
        return [
            'ok' => false,
            'bloques' => [],
            'sin_colocar' => [],
            'resumen' => null,
            'regla' => null,
            'aviso' => $aviso,
        ];
    }
}
