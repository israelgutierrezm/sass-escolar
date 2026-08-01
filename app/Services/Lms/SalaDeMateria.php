<?php

declare(strict_types=1);

namespace App\Services\Lms;

use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\ControlEscolar\Inscripcion;
use App\Models\Lms\Conversacion;
use App\Models\Lms\ConversacionLectura;
use App\Models\Lms\Mensaje;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Quién puede hablar con quién en una materia, y por dónde.
 *
 * Concentra las tres reglas del chat porque son de acceso, y una regla de acceso
 * repartida entre pantallas es una que algún día se olvida en la que deja
 * entrar:
 *
 * 1. **La membresía sale de la materia**, no de una lista aparte: son sus
 *    inscritos vigentes y sus docentes asignados. Una tabla de participantes
 *    sería una copia que se queda vieja en cuanto alguien se da de baja.
 * 2. **Directas solo alumno↔docente.** Es lo que se pidió —«un canal de chat
 *    alumno-docente»— y deja fuera el chat entre alumnos, que traería una
 *    moderación que la escuela no encargó.
 * 3. **Escribir exige la materia activa.** Cerrada la materia, el historial se
 *    sigue leyendo y ya nadie escribe: es lo que significa «mientras tenga la
 *    materia activa».
 */
class SalaDeMateria
{
    /** Ids de persona de los alumnos vigentes (los de baja no cuentan). */
    public function alumnos(AsignaturaGrupo $materia): Collection
    {
        return Inscripcion::query()
            ->where('inscripcion.asignatura_grupo_id', $materia->id)
            ->join('matricula_oferta', 'matricula_oferta.id', '=', 'inscripcion.matricula_oferta_id')
            ->join('situaciones_inscripcion', 'situaciones_inscripcion.id', '=', 'inscripcion.situacion_id')
            ->where('situaciones_inscripcion.clave', '!=', 'baja')
            ->pluck('matricula_oferta.persona_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
    }

    /** Ids de persona de los docentes asignados a la materia. */
    public function docentes(AsignaturaGrupo $materia): Collection
    {
        return $materia->docentes()
            ->pluck('docentes.persona_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
    }

    public function esDocente(AsignaturaGrupo $materia, int $personaId): bool
    {
        return $this->docentes($materia)->contains($personaId);
    }

    public function esAlumno(AsignaturaGrupo $materia, int $personaId): bool
    {
        return $this->alumnos($materia)->contains($personaId);
    }

    /** Quien está en la materia, del lado que sea, ve su chat. */
    public function participa(AsignaturaGrupo $materia, int $personaId): bool
    {
        return $this->esDocente($materia, $personaId) || $this->esAlumno($materia, $personaId);
    }

    /**
     * Escribir exige que la materia siga activa.
     *
     * Leer no: el historial de una materia cerrada sigue estando ahí, y
     * esconderlo castigaría al alumno que quiere revisar lo que le dijeron.
     */
    public function puedeEscribir(AsignaturaGrupo $materia): bool
    {
        return $materia->situacion?->clave === 'activa';
    }

    /** El canal del grupo, creándolo la primera vez que alguien lo abre. */
    public function canalDelGrupo(AsignaturaGrupo $materia): Conversacion
    {
        return Conversacion::firstOrCreate([
            'asignatura_grupo_id' => $materia->id,
            'tipo' => Conversacion::GRUPO,
            'persona_a_id' => null,
            'persona_b_id' => null,
        ]);
    }

    /**
     * La conversación directa entre dos personas de la materia.
     *
     * Se rechaza si no son un alumno y un docente: dos alumnos no tienen canal
     * directo aquí, y dos docentes tampoco —para eso está el canal del grupo o
     * lo que usen fuera del sistema—.
     */
    public function directa(AsignaturaGrupo $materia, int $unaPersona, int $otraPersona): Conversacion
    {
        if ($unaPersona === $otraPersona) {
            throw new RuntimeException('No puedes abrir una conversación contigo mismo.');
        }

        $unDocente = $this->esDocente($materia, $unaPersona) || $this->esDocente($materia, $otraPersona);
        $unAlumno = $this->esAlumno($materia, $unaPersona) || $this->esAlumno($materia, $otraPersona);

        if (! $unDocente || ! $unAlumno) {
            throw new RuntimeException('El chat directo es entre un alumno y un docente de la materia.');
        }

        [$a, $b] = Conversacion::pareja($unaPersona, $otraPersona);

        return Conversacion::firstOrCreate([
            'asignatura_grupo_id' => $materia->id,
            'tipo' => Conversacion::DIRECTA,
            'persona_a_id' => $a,
            'persona_b_id' => $b,
        ]);
    }

    /** Quién ve esta conversación: el grupo entero, o los dos de la directa. */
    public function puedeVer(Conversacion $conversacion, int $personaId): bool
    {
        if ($conversacion->esDirecta()) {
            return in_array($personaId, [
                (int) $conversacion->persona_a_id,
                (int) $conversacion->persona_b_id,
            ], true);
        }

        return $this->participa($conversacion->asignaturaGrupo, $personaId);
    }

    /**
     * Publica un mensaje y deja la conversación al día.
     *
     * `ultimo_mensaje_en` se toca aquí para poder ordenar la lista de
     * conversaciones sin un MAX() sobre toda la tabla de mensajes, y el propio
     * autor queda marcado como que ya leyó lo suyo —si no, uno tendría siempre
     * un mensaje sin leer: el que acaba de escribir—.
     */
    public function publicar(Conversacion $conversacion, int $personaId, string $cuerpo): Mensaje
    {
        if (! $this->puedeVer($conversacion, $personaId)) {
            throw new RuntimeException('Esa conversación no es tuya.');
        }

        if (! $this->puedeEscribir($conversacion->asignaturaGrupo)) {
            throw new RuntimeException('Esta materia ya está cerrada: su chat es solo de lectura.');
        }

        return DB::transaction(function () use ($conversacion, $personaId, $cuerpo) {
            $mensaje = Mensaje::create([
                'conversacion_id' => $conversacion->id,
                'persona_id' => $personaId,
                'cuerpo' => $cuerpo,
            ]);

            $conversacion->update(['ultimo_mensaje_en' => $mensaje->created_at]);
            $this->marcarLeida($conversacion, $personaId, (int) $mensaje->id);

            return $mensaje;
        });
    }

    /** Deja constancia de hasta dónde leyó alguien. */
    public function marcarLeida(Conversacion $conversacion, int $personaId, ?int $hastaMensajeId = null): void
    {
        $hasta = $hastaMensajeId ?? (int) Mensaje::where('conversacion_id', $conversacion->id)->max('id');

        ConversacionLectura::updateOrCreate(
            ['conversacion_id' => $conversacion->id, 'persona_id' => $personaId],
            ['ultimo_mensaje_id' => $hasta ?: null],
        );
    }

    /**
     * Cuántos mensajes sin leer tiene cada conversación de esta persona.
     *
     * En una sola consulta y no una por conversación: con veinte materias
     * abiertas, contar de a una son veinte viajes a la base cada vez que se
     * pinta la lista.
     *
     * @param  Collection<int, Conversacion>  $conversaciones
     * @return array<int, int> conversacion_id => sin leer
     */
    public function sinLeer(Collection $conversaciones, int $personaId): array
    {
        $ids = $conversaciones->pluck('id');

        if ($ids->isEmpty()) {
            return [];
        }

        return Mensaje::query()
            ->selectRaw('mensajes.conversacion_id, count(*) as total')
            // La marca de lectura de ESTA persona; si nunca abrió, viene nula y
            // entonces todo el hilo cuenta como sin leer.
            ->leftJoin('conversacion_lecturas as l', function ($join) use ($personaId) {
                $join->on('l.conversacion_id', '=', 'mensajes.conversacion_id')
                    ->where('l.persona_id', '=', $personaId);
            })
            ->whereIn('mensajes.conversacion_id', $ids)
            // Lo propio nunca cuenta como no leído.
            ->where('mensajes.persona_id', '!=', $personaId)
            ->whereNull('mensajes.deleted_at')
            ->where(fn ($q) => $q->whereNull('l.ultimo_mensaje_id')
                ->orWhereColumn('mensajes.id', '>', 'l.ultimo_mensaje_id'))
            ->groupBy('mensajes.conversacion_id')
            ->pluck('total', 'conversacion_id')
            ->map(fn ($n) => (int) $n)
            ->all();
    }
}
