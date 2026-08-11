<?php

declare(strict_types=1);

namespace App\Services;

use App\Configuracion\Ajustes;
use App\Configuracion\CatalogoAjustes;
use App\Models\Admisiones\Aspirante;
use App\Models\Admisiones\Asesor;
use Illuminate\Support\Facades\DB;

/**
 * A quién le toca el prospecto que acaba de llegar.
 *
 * ── Por qué esto es automático y no una tarea de alguien ───────────────────
 * Un prospecto sin dueño no le sale a nadie en su pantalla, y lo que no le sale
 * a nadie no se atiende. Repartir a mano funciona los primeros días y falla el
 * lunes que llegan cuarenta: lo que se queda sin repartir se enfría, y un
 * prospecto frío es indistinguible de uno que nunca existió.
 *
 * ── Tres modos, porque son tres escuelas distintas ────────────────────────
 * - MANUAL: nadie se asigna solo. La escuela chica donde el coordinador conoce
 *   a cada prospecto y decide caso por caso.
 * - QUIEN_REGISTRA: se lo queda quien lo capturó, si es asesor activo. Es lo
 *   natural cuando el asesor sale a ferias y trae sus propios contactos: ya
 *   habló con esa persona, no tiene sentido pasársela a otro.
 * - SECUENCIAL: se reparte por turno entre los asesores activos DEL CAMPUS.
 *   Es lo que hace falta cuando los prospectos «caen» de la página web y no
 *   traen dueño natural.
 *
 * ── El turno se decide por CARGA, no por un contador ──────────────────────
 * Un contador guardado («el último fue el 3, sigue el 4») se desincroniza en
 * cuanto alguien se da de baja, entra uno nuevo o dos altas ocurren a la vez, y
 * entonces reparte torcido para siempre sin que nadie lo note. Preguntar quién
 * tiene menos prospectos activos da el mismo turno rotatorio, se corrige solo
 * cuando cambia la plantilla, y responde a la pregunta que de verdad importa —
 * quién está más libre—.
 */
class AsignadorAsesor
{
    /** Nadie se asigna solo. */
    public const MANUAL = 'manual';

    /** Se lo queda quien lo capturó, si es asesor activo. */
    public const QUIEN_REGISTRA = 'quien_registra';

    /** Por turno entre los activos del campus. */
    public const SECUENCIAL = 'secuencial';

    public function __construct(private readonly Ajustes $ajustes) {}

    /**
     * Asigna al prospecto recién creado y devuelve a quién le tocó.
     *
     * Devuelve null cuando no hay a quién asignarle —modo manual, o no hay
     * asesores activos—: no es un fallo, es que la escuela todavía no ha
     * configurado su equipo, y reventar el alta de un prospecto por eso sería
     * perder el prospecto.
     */
    public function asignar(Aspirante $aspirante, ?int $quienRegistra = null): ?int
    {
        // Ya tiene titular: no se le quita a nadie lo que ya atiende.
        if ($aspirante->asesores()->wherePivot('titular', true)->exists()) {
            return null;
        }

        $elegido = match ($this->modo()) {
            self::QUIEN_REGISTRA => $this->siEsAsesorActivo($quienRegistra),
            self::SECUENCIAL => $this->elMasLibre($aspirante->campus_id),
            default => null,
        };

        /*
         * El modo «quien registra» cae al reparto secuencial cuando quien
         * captura no es asesor.
         *
         * Es el caso de todos los días: el prospecto llega por el formulario
         * público —donde no hay nadie capturando— o lo mete una recepcionista.
         * Sin este respaldo, justamente los prospectos que nadie trajo de la
         * mano se quedarían sin dueño, que son los que más falta les hace.
         */
        if ($elegido === null && $this->modo() === self::QUIEN_REGISTRA) {
            $elegido = $this->elMasLibre($aspirante->campus_id);
        }

        if ($elegido === null) {
            return null;
        }

        $this->atarComoTitular($aspirante, $elegido);

        return $elegido;
    }

    /** Ata a la persona como titular del prospecto. */
    public function atarComoTitular(Aspirante $aspirante, int $personaId): void
    {
        DB::transaction(function () use ($aspirante, $personaId) {
            // Un solo titular: dos comisiones por el mismo alumno serían pagar
            // dos veces por un resultado.
            DB::table('aspirante_asesor')
                ->where('aspirante_id', $aspirante->id)
                ->update(['titular' => false]);

            $aspirante->asesores()->syncWithoutDetaching([$personaId => ['titular' => true]]);
        });
    }

    /** El modo configurado por la escuela. */
    public function modo(): string
    {
        $modo = $this->ajustes->texto(CatalogoAjustes::ASIGNACION_ASESOR);

        return in_array($modo, [self::MANUAL, self::QUIEN_REGISTRA, self::SECUENCIAL], true)
            ? $modo
            : self::MANUAL;
    }

    /**
     * Quien capturó, si de verdad es asesor y está activo.
     *
     * Se comprueba y no se supone: quien tiene el permiso para dar de alta un
     * prospecto no es forzosamente quien lo va a atender —control escolar
     * también captura—, y asignárselo lo dejaría con una cartera que no sabe
     * que tiene.
     */
    private function siEsAsesorActivo(?int $personaId): ?int
    {
        if ($personaId === null) {
            return null;
        }

        return Asesor::query()->activos()->whereKey($personaId)->exists() ? $personaId : null;
    }

    /**
     * El asesor activo del campus con menos prospectos abiertos.
     *
     * «Abiertos» = los que todavía no se convirtieron en alumno. Contar los
     * históricos castigaría al que lleva años: el que más ha inscrito recibiría
     * menos, que es exactamente al revés de lo que quiere una escuela.
     *
     * El desempate va por `persona_id` para que el orden sea estable: con dos
     * asesores en cero, un orden aleatorio repartiría los primeros diez al azar
     * en vez de alternarlos.
     */
    private function elMasLibre(?int $campusId): ?int
    {
        $candidatos = Asesor::query()
            ->activos()
            ->deCampus($campusId)
            ->pluck('persona_id');

        if ($candidatos->isEmpty()) {
            /*
             * Sin nadie en ese campus, se reparte entre TODOS los activos.
             *
             * Un prospecto sin dueño porque su campus no tiene asesor asignado
             * es un prospecto perdido por un dato de configuración. Antes que
             * eso, que lo atienda quien haya.
             */
            $candidatos = Asesor::query()->activos()->pluck('persona_id');
        }

        if ($candidatos->isEmpty()) {
            return null;
        }

        $cargas = DB::table('aspirante_asesor')
            ->join('aspirantes', 'aspirantes.id', '=', 'aspirante_asesor.aspirante_id')
            ->whereIn('aspirante_asesor.persona_id', $candidatos)
            ->where('aspirante_asesor.titular', true)
            ->whereNull('aspirantes.deleted_at')
            // Los que ya son alumnos dejaron de ser trabajo pendiente.
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('matricula_oferta')
                ->whereColumn('matricula_oferta.persona_id', 'aspirantes.persona_id')
                ->whereNull('matricula_oferta.deleted_at'))
            ->groupBy('aspirante_asesor.persona_id')
            ->pluck(DB::raw('COUNT(*) as total'), 'aspirante_asesor.persona_id');

        return $candidatos
            ->sortBy(fn (int $id) => [(int) ($cargas[$id] ?? 0), $id])
            ->first();
    }
}
