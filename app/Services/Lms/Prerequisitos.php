<?php

declare(strict_types=1);

namespace App\Services\Lms;

use App\Exceptions\AvisoParaElUsuario;
use App\Models\ControlEscolar\Inscripcion;
use App\Models\Lms\Actividad;
use App\Models\Lms\ActividadVista;
use App\Models\Lms\Curso;
use App\Models\Lms\Entrega;
use Illuminate\Validation\ValidationException;

/**
 * El candado de avance: una actividad puede exigir que otra del MISMO curso se
 * complete antes de abrirse al alumno.
 *
 * ── «Completada» se define UNA vez, aquí ────────────────────────────────────
 * La entrega si la actividad se entrega, el botón del alumno si es lectura —el
 * mismo criterio que el aula ya usa para la barra de progreso—. El candado y el
 * aula preguntan a esta misma función, así que el índice no puede palomear una
 * actividad que el candado considere pendiente, ni al revés.
 *
 * ── El candado es SÓLO del alumno, y se comprueba en el SERVIDOR ────────────
 * Al docente no se le aplica —está enseñando, no cursando—, así que la puerta
 * por persona devuelve «sin candado» cuando quien pregunta no tiene inscripción.
 * Y esconder el botón en la pantalla no es defensa: cada acción que «completa»
 * una actividad —entregar, iniciar un examen, sumar al portafolio, participar en
 * el foro, marcar una lectura— llama a `exigirDesbloqueada` antes de escribir.
 *
 * ── Falla ABIERTO ───────────────────────────────────────────────────────────
 * Un prerrequisito que ya no se puede completar —oculto o dado de baja— deja de
 * ser candado. Nunca se encierra a un alumno por un cambio del docente; lo peor
 * que pasa es que una actividad quede accesible antes de tiempo, y eso lo corrige
 * el docente. Un candado que atrapa es peor que uno que se suelta.
 *
 * ── Lo que NO hace todavía, a propósito ─────────────────────────────────────
 * El candado se abre al COMPLETAR el prerrequisito (entregarlo o declararlo
 * leído), no al APROBARLO. Exigir una nota de paso dejaría al alumno esperando a
 * que el docente califique —a veces días— para poder seguir, y abre la pregunta
 * de «cuánto es aprobar». Es el mismo criterio de completitud que Moodle trae por
 * omisión; «debe aprobarlo» sería otra rebanada.
 */
class Prerequisitos
{
    /** El criterio único de «completada», con lo que la actividad DEJA como rastro. */
    public function estaCompletada(Actividad $actividad, ?Entrega $entrega, ?ActividadVista $vista): bool
    {
        return $actividad->tipo->seEntrega()
            ? $entrega?->entregada_en !== null
            : $vista?->completada_en !== null;
    }

    /** ¿La completó esta inscripción? Consulta su rastro y aplica el criterio. */
    public function completadaPor(Actividad $actividad, int $inscripcionId): bool
    {
        if ($actividad->tipo->seEntrega()) {
            $entrega = Entrega::query()
                ->where('actividad_id', $actividad->id)
                ->where('inscripcion_id', $inscripcionId)
                ->first();

            return $this->estaCompletada($actividad, $entrega, null);
        }

        $vista = ActividadVista::query()
            ->where('actividad_id', $actividad->id)
            ->where('inscripcion_id', $inscripcionId)
            ->first();

        return $this->estaCompletada($actividad, null, $vista);
    }

    /**
     * El prerrequisito que mantiene bloqueada esta actividad para esta
     * inscripción, o null si está abierta. Falla abierto si el prerrequisito ya
     * no es alcanzable.
     */
    public function bloqueoPara(Actividad $actividad, int $inscripcionId): ?Actividad
    {
        $prereq = $actividad->prerequisito;

        // Sólo bloquea mientras el prerrequisito se puede COMPLETAR: publicado y
        // abierto. Si está oculto, aún no abre o ya cerró sin extemporáneos, el
        // candado se suelta — nunca se le pide al alumno completar algo que no
        // ve o que ya no puede hacer, y así el aula y esta puerta dicen lo mismo.
        if ($prereq === null || $prereq->trashed() || ! $prereq->publicada || ! $prereq->abierta()) {
            return null;
        }

        return $this->completadaPor($prereq, $inscripcionId) ? null : $prereq;
    }

    /**
     * Igual, pero resolviendo la inscripción de una PERSONA en el grupo de la
     * actividad. Devuelve null cuando no tiene inscripción —el docente— para que
     * el candado no lo alcance. Lo usa el foro, donde escriben los dos oficios.
     */
    public function bloqueoParaPersona(Actividad $actividad, int $personaId): ?Actividad
    {
        $inscripcionId = $this->inscripcionDe($actividad, $personaId);

        return $inscripcionId === null ? null : $this->bloqueoPara($actividad, $inscripcionId);
    }

    /** @throws AvisoParaElUsuario 403 nombrando el prerrequisito */
    public function exigirDesbloqueada(Actividad $actividad, int $inscripcionId): void
    {
        $bloqueo = $this->bloqueoPara($actividad, $inscripcionId);

        AvisoParaElUsuario::si(
            $bloqueo !== null,
            403,
            'Antes tienes que completar «'.$bloqueo?->titulo.'».',
        );
    }

    /** @throws AvisoParaElUsuario 403 nombrando el prerrequisito */
    public function exigirDesbloqueadaParaPersona(Actividad $actividad, int $personaId): void
    {
        $bloqueo = $this->bloqueoParaPersona($actividad, $personaId);

        AvisoParaElUsuario::si(
            $bloqueo !== null,
            403,
            'Antes tienes que completar «'.$bloqueo?->titulo.'».',
        );
    }

    /**
     * Valida un prerrequisito al guardar la actividad: tiene que ser OTRA
     * actividad del mismo curso y no cerrar un ciclo. Devuelve el id validado.
     *
     * @throws ValidationException
     */
    public function validarAlGuardar(?int $prerequisitoId, Curso $curso, ?Actividad $actividad): ?int
    {
        if ($prerequisitoId === null) {
            return null;
        }

        $prereq = Actividad::query()
            ->where('id', $prerequisitoId)
            ->where('curso_id', $curso->id)
            ->first();

        if ($prereq === null) {
            throw ValidationException::withMessages([
                'prerequisito_id' => 'Ese prerrequisito no es una actividad de este curso.',
            ]);
        }

        // Una actividad nueva todavía no existe: nada le apunta, así que no puede
        // ser su propio prerrequisito ni cerrar un ciclo.
        if ($actividad !== null) {
            if ($prereq->id === $actividad->id) {
                throw ValidationException::withMessages([
                    'prerequisito_id' => 'Una actividad no puede ser su propio prerrequisito.',
                ]);
            }

            if ($this->cadenaLlegaA($prereq, $actividad->id)) {
                throw ValidationException::withMessages([
                    'prerequisito_id' => 'Ese prerrequisito crea un ciclo: la cadena volvería a esta actividad.',
                ]);
            }
        }

        return $prereq->id;
    }

    /** ¿La cadena de prerrequisitos que arranca en $inicio pasa por $objetivoId? */
    private function cadenaLlegaA(Actividad $inicio, int $objetivoId): bool
    {
        $vistos = [];
        $actual = $inicio;

        while ($actual !== null) {
            if ($actual->id === $objetivoId) {
                return true;
            }

            if (isset($vistos[$actual->id])) {
                return true; // la cadena ya era cíclica: defensivo
            }

            $vistos[$actual->id] = true;
            $actual = $actual->prerequisito_id === null ? null : Actividad::find($actual->prerequisito_id);
        }

        return false;
    }

    private function inscripcionDe(Actividad $actividad, int $personaId): ?int
    {
        $grupoId = $actividad->curso?->asignatura_grupo_id;

        if ($grupoId === null) {
            return null;
        }

        return Inscripcion::query()
            ->where('inscripcion.asignatura_grupo_id', $grupoId)
            ->join('matricula_oferta', 'matricula_oferta.id', '=', 'inscripcion.matricula_oferta_id')
            ->where('matricula_oferta.persona_id', $personaId)
            ->value('inscripcion.id');
    }
}
