<?php

declare(strict_types=1);

namespace App\Services\Familia;

use App\Exceptions\AvisoParaElUsuario;
use App\Enums\DestinoEvento;
use App\Enums\PrioridadAviso;
use App\Models\ControlEscolar\Inscripcion;
use App\Models\Familia\Cita;
use App\Models\Familia\DisponibilidadCitaDocente;
use App\Models\Identidad\Persona;
use App\Models\Identidad\TutorAlumno;
use App\Models\Plataforma\Aviso;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * La regla de las citas familia–docente, en UN sitio.
 *
 * Ver `docs/plan-citas-familia-docente.md`. La preguntan los dos portales —el de
 * la familia para solicitar y cancelar, el del docente para responder—, así que
 * escribirla dos veces dejaría que la pantalla ofreciera lo que el servidor
 * rechaza. Toda transición comprueba el ESTADO de origen y el vínculo.
 */
class GestorDeCitas
{
    // ── Derivaciones del vínculo (no se guardan, se preguntan) ──────────────

    /** ¿Es este alumno hijo de este tutor? */
    public function esHijoDe(int $tutorPersonaId, int $alumnoPersonaId): bool
    {
        return TutorAlumno::query()
            ->where('tutor_persona_id', $tutorPersonaId)
            ->where('alumno_persona_id', $alumnoPersonaId)
            ->exists();
    }

    /** ¿Este docente le da clase HOY a este alumno? (excluye materias en baja). */
    public function daClaseA(int $docentePersonaId, int $alumnoPersonaId): bool
    {
        return $this->inscripcionesVigentes($alumnoPersonaId)
            ->whereHas('asignaturaGrupo.docentes', fn ($q) => $q->where('docentes.persona_id', $docentePersonaId))
            ->exists();
    }

    /**
     * Los docentes que le dan clase a un alumno, con las materias de cada uno.
     * Es lo que la familia elige para pedir cita.
     *
     * @return array<int, array{persona_id: int, nombre: string, materias: array<int, string>}>
     */
    public function docentesDelAlumno(int $alumnoPersonaId): array
    {
        $inscripciones = $this->inscripcionesVigentes($alumnoPersonaId)
            ->with(['asignaturaGrupo.planMateria.asignatura:id,nombre', 'asignaturaGrupo.docentes:persona_id'])
            ->get();

        // docente persona_id => set de nombres de materia
        $porDocente = [];
        foreach ($inscripciones as $inscripcion) {
            $materia = $inscripcion->asignaturaGrupo?->planMateria?->asignatura?->nombre;
            foreach ($inscripcion->asignaturaGrupo?->docentes ?? [] as $docente) {
                $porDocente[$docente->persona_id] ??= [];
                if ($materia !== null) {
                    $porDocente[$docente->persona_id][$materia] = true;
                }
            }
        }

        if ($porDocente === []) {
            return [];
        }

        $nombres = Persona::query()->whereIn('id', array_keys($porDocente))->get()->keyBy('id');

        $salida = [];
        foreach ($porDocente as $personaId => $materias) {
            $salida[] = [
                'persona_id' => (int) $personaId,
                'nombre' => $nombres->get($personaId)?->nombreCompleto() ?? 'Docente',
                'materias' => array_keys($materias),
            ];
        }

        usort($salida, fn ($a, $b) => strcmp($a['nombre'], $b['nombre']));

        return $salida;
    }

    /**
     * Las ventanas de atención de un docente.
     *
     * @return Collection<int, DisponibilidadCitaDocente>
     */
    public function ventanasDe(int $docentePersonaId): Collection
    {
        return DisponibilidadCitaDocente::query()
            ->where('persona_id', $docentePersonaId)
            ->orderBy('dia_semana')->orderBy('hora_inicio')
            ->get();
    }

    /**
     * Las ventanas de varios docentes, agrupadas por docente y ya mapeadas —los
     * huecos que la familia elige para pedir cita—. En el servicio para que la
     * web y la app ofrezcan lo mismo, en una sola consulta.
     *
     * @param  array<int, int>  $docenteIds
     * @return array<int, array<int, array<string, mixed>>>  persona_id => ventanas
     */
    public function ventanasPorDocente(array $docenteIds): array
    {
        if ($docenteIds === []) {
            return [];
        }

        return DisponibilidadCitaDocente::query()
            ->whereIn('persona_id', $docenteIds)
            ->orderBy('dia_semana')->orderBy('hora_inicio')->get()
            ->groupBy('persona_id')
            ->map(fn (Collection $g) => $g->map(fn (DisponibilidadCitaDocente $d) => [
                'id' => $d->id,
                'dia_semana' => $d->dia_semana,
                'hora_inicio' => substr((string) $d->hora_inicio, 0, 5),
                'hora_fin' => substr((string) $d->hora_fin, 0, 5),
                'modalidad' => $d->modalidad,
                'duracion_min' => $d->duracion_min,
                'lugar' => $d->lugar,
            ])->values()->all())
            ->all();
    }

    // ── Transiciones ────────────────────────────────────────────────────────

    /**
     * La familia SOLICITA una cita en un hueco de una ventana del docente.
     *
     * @throws AvisoParaElUsuario 422/403 con su razón
     */
    public function solicitar(int $tutorPersonaId, int $alumnoPersonaId, int $disponibilidadId, string $fecha, string $horaInicio, string $motivo): Cita
    {
        // 1. El hijo es suyo. 404 y no 403: un id ajeno no confirma que exista.
        AvisoParaElUsuario::aMenosQue($this->esHijoDe($tutorPersonaId, $alumnoPersonaId), 404, 'Ese alumno no está vinculado a tu cuenta.');

        $ventana = DisponibilidadCitaDocente::query()->find($disponibilidadId);
        AvisoParaElUsuario::si($ventana === null, 404, 'Esa disponibilidad ya no existe.');

        // 2. El docente de la ventana le da clase a su hijo.
        AvisoParaElUsuario::aMenosQue($this->daClaseA($ventana->persona_id, $alumnoPersonaId), 403, 'Ese docente no le da clase a tu hijo.');

        // 3. La fecha cae en el día de la ventana y la hora es un hueco válido.
        $inicio = $this->huecoValido($ventana, $fecha, $horaInicio);
        $fin = $inicio->copy()->addMinutes($ventana->duracion_min);

        // 4. No en el pasado.
        AvisoParaElUsuario::si($inicio->isPast(), 422, 'Esa hora ya pasó.');

        // 5. No sobre un hueco YA confirmado del docente; ni una solicitud
        //    idéntica repetida del mismo hijo.
        AvisoParaElUsuario::si(
            Cita::query()->where('docente_persona_id', $ventana->persona_id)->confirmadas()->traslapa($inicio, $fin)->exists(),
            422,
            'Ese horario ya está ocupado. Elige otro.',
        );
        AvisoParaElUsuario::si(
            Cita::query()->where('docente_persona_id', $ventana->persona_id)->where('alumno_persona_id', $alumnoPersonaId)
                ->whereIn('estado', Cita::ACTIVOS)->where('inicio', $inicio)->exists(),
            422,
            'Ya tienes una solicitud para ese horario.',
        );

        $cita = Cita::create([
            'docente_persona_id' => $ventana->persona_id,
            'alumno_persona_id' => $alumnoPersonaId,
            'solicitante_persona_id' => $tutorPersonaId,
            'inicio' => $inicio,
            'fin' => $fin,
            'modalidad' => $ventana->modalidad,
            'motivo' => $motivo,
            'lugar' => $ventana->lugar,
            'estado' => Cita::SOLICITADA,
        ]);

        $alumno = Persona::query()->find($alumnoPersonaId);
        $this->avisar(
            $ventana->persona_id,
            'Nueva solicitud de cita',
            'Una familia de '.($alumno?->nombreCompleto() ?? 'un alumno').' pidió cita para el '.$inicio->format('d/m/Y H:i').'.',
        );

        return $cita;
    }

    /** El docente CONFIRMA. Bajo bloqueo, revalida que no se encime. */
    public function confirmar(Cita $cita, int $docentePersonaId, ?string $nota = null, ?string $lugar = null): Cita
    {
        $this->exigirDocente($cita, $docentePersonaId);
        AvisoParaElUsuario::aMenosQue($cita->estado === Cita::SOLICITADA, 422, 'Sólo se confirma una cita solicitada.');

        return DB::transaction(function () use ($cita, $nota, $lugar) {
            // Serializa las confirmaciones del MISMO docente: dos horas encimadas
            // no pueden pasar las dos. Es el molde del bloqueo del expediente en
            // las horas formativas.
            Cita::query()->where('docente_persona_id', $cita->docente_persona_id)
                ->whereIn('estado', Cita::ACTIVOS)->lockForUpdate()->get();

            $choca = Cita::query()->where('docente_persona_id', $cita->docente_persona_id)
                ->confirmadas()->whereKeyNot($cita->id)
                ->traslapa($cita->inicio, $cita->fin)->exists();
            AvisoParaElUsuario::si($choca, 422, 'Ya tienes otra cita confirmada a esa hora.');

            $cita->update([
                'estado' => Cita::CONFIRMADA,
                'respuesta' => $nota,
                'lugar' => $lugar ?? $cita->lugar,
            ]);

            $this->avisar($cita->solicitante_persona_id, 'Cita confirmada',
                'El docente confirmó la cita del '.$cita->inicio->format('d/m/Y H:i').'.'.($nota ? ' Nota: '.$nota : ''));

            return $cita;
        });
    }

    /** El docente RECHAZA, con motivo (puede sugerir otra hora). */
    public function rechazar(Cita $cita, int $docentePersonaId, string $motivo): Cita
    {
        $this->exigirDocente($cita, $docentePersonaId);
        AvisoParaElUsuario::aMenosQue($cita->estado === Cita::SOLICITADA, 422, 'Sólo se rechaza una cita solicitada.');
        AvisoParaElUsuario::si(trim($motivo) === '', 422, 'El rechazo necesita un motivo: es lo único que la familia puede usar para volver a pedir.');

        $cita->update(['estado' => Cita::RECHAZADA, 'respuesta' => $motivo]);

        $this->avisar($cita->solicitante_persona_id, 'Cita no confirmada',
            'El docente no pudo confirmar la cita del '.$cita->inicio->format('d/m/Y H:i').'. Motivo: '.$motivo);

        return $cita;
    }

    /** La familia o el docente CANCELA, antes de que ocurra. */
    public function cancelar(Cita $cita, int $actorPersonaId, string $motivo): Cita
    {
        $esParte = $actorPersonaId === (int) $cita->solicitante_persona_id || $actorPersonaId === (int) $cita->docente_persona_id;
        AvisoParaElUsuario::aMenosQue($esParte, 403, 'No eres parte de esta cita.');
        AvisoParaElUsuario::aMenosQue($cita->estaActiva(), 422, 'Esta cita ya no está activa.');
        AvisoParaElUsuario::si($cita->yaPaso(), 422, 'Esa cita ya pasó; no se cancela.');
        AvisoParaElUsuario::si(trim($motivo) === '', 422, 'Di por qué se cancela.');

        $cita->update(['estado' => Cita::CANCELADA, 'respuesta' => $motivo]);

        // Avisa a la OTRA parte.
        $otro = $actorPersonaId === (int) $cita->docente_persona_id ? $cita->solicitante_persona_id : $cita->docente_persona_id;
        $this->avisar($otro, 'Cita cancelada',
            'Se canceló la cita del '.$cita->inicio->format('d/m/Y H:i').'. Motivo: '.$motivo);

        return $cita;
    }

    /** El docente marca el desenlace de una cita confirmada YA PASADA. */
    public function marcar(Cita $cita, int $docentePersonaId, string $estadoFinal): Cita
    {
        $this->exigirDocente($cita, $docentePersonaId);
        AvisoParaElUsuario::aMenosQue($cita->estado === Cita::CONFIRMADA, 422, 'Sólo una cita confirmada se marca.');
        AvisoParaElUsuario::aMenosQue($cita->yaPaso(), 422, 'Esa cita todavía no ocurre.');
        AvisoParaElUsuario::aMenosQue(in_array($estadoFinal, [Cita::REALIZADA, Cita::NO_ASISTIO], true), 422, 'Desenlace inválido.');

        $cita->update(['estado' => $estadoFinal]);

        return $cita;
    }

    // ── Interno ─────────────────────────────────────────────────────────────

    private function exigirDocente(Cita $cita, int $docentePersonaId): void
    {
        // 404 y no 403: un id de cita ajena no debe confirmar que exista.
        AvisoParaElUsuario::aMenosQue((int) $cita->docente_persona_id === $docentePersonaId, 404, 'Esa cita no es tuya.');
    }

    /** @return \Illuminate\Database\Eloquent\Builder<Inscripcion> */
    private function inscripcionesVigentes(int $alumnoPersonaId): \Illuminate\Database\Eloquent\Builder
    {
        return Inscripcion::query()
            ->whereHas('matriculaOferta', fn ($q) => $q->where('persona_id', $alumnoPersonaId))
            ->whereDoesntHave('situacion', fn ($q) => $q->where('clave', 'baja'));
    }

    /**
     * Comprueba que (fecha, hora) sea un hueco válido de la ventana y devuelve el
     * datetime de inicio. El hueco tiene que caer en el día de la ventana, dentro
     * de su rango, y en un múltiplo de la duración desde su inicio —si no, dos
     * huecos se solaparían dentro de la misma ventana—.
     */
    private function huecoValido(DisponibilidadCitaDocente $ventana, string $fecha, string $horaInicio): Carbon
    {
        try {
            $inicio = Carbon::createFromFormat('Y-m-d H:i', $fecha.' '.$horaInicio);
        } catch (\Throwable) {
            AvisoParaElUsuario::lanzar(422, 'Fecha u hora inválida.');
        }

        AvisoParaElUsuario::aMenosQue(
            $inicio->isoWeekday() === $ventana->dia_semana,
            422,
            'Ese día el docente no atiende en esa ventana.',
        );

        $minuto = DisponibilidadCitaDocente::aMinutos($horaInicio);
        $ini = $ventana->inicioEnMinutos();
        $fin = $ventana->finEnMinutos();
        $dur = $ventana->duracion_min;

        AvisoParaElUsuario::aMenosQue(
            $minuto >= $ini && ($minuto + $dur) <= $fin && ($minuto - $ini) % $dur === 0,
            422,
            'Esa hora no es un hueco disponible.',
        );

        return $inicio;
    }

    private function avisar(int $personaId, string $titulo, string $cuerpo): void
    {
        $aviso = Aviso::create([
            'titulo' => $titulo,
            'cuerpo' => $cuerpo,
            'prioridad' => PrioridadAviso::Importante,
            'publicado' => true,
            'publicado_desde' => now(),
            'vigente_hasta' => now()->addDays(5),
        ]);

        // Destino Alumno casa contra `persona_id`, así que sirve igual para
        // avisarle a un docente o a un familiar (los dos son personas).
        $aviso->destinos()->create(['tipo' => DestinoEvento::Alumno, 'destino_id' => $personaId]);
    }
}
