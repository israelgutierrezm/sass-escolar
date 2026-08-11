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
 * ── DOS decisiones, no una con tres opciones ──────────────────────────────
 * Estaban en un solo desplegable y obligaban a elegir; lo normal es querer las
 * dos a la vez:
 *
 * 1. ¿El asesor se queda lo que él mismo registra? Es lo natural cuando sale a
 *    ferias y trae sus propios contactos: ya habló con esa persona.
 * 2. ¿Y con TODO LO DEMÁS —lo que cae por la web, lo que captura recepción—?
 *    MANUAL (alguien lo asigna) o SECUENCIAL (por turno entre los asesores
 *    activos del campus del prospecto).
 *
 * La primera es un interruptor y la segunda un modo. Se resuelven en ese orden:
 * si el interruptor está encendido y quien capturó es asesor activo, se lo
 * queda; si no, decide el modo.
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
    /** Nadie se asigna solo: lo reparte una persona. */
    public const MANUAL = 'manual';

    /** Por turno entre los activos del campus, al que menos tenga. */
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

        /*
         * Primero, quien lo trajo.
         *
         * Si el interruptor está encendido y quien capturó es asesor activo, se
         * lo queda y aquí termina. Los dos ajustes conviven: éste NO decide qué
         * pasa con los demás.
         */
        $elegido = $this->seLoQuedaQuienRegistra()
            ? $this->siEsAsesorActivo($quienRegistra)
            : null;

        /*
         * Y con todo lo demás, lo que diga el modo.
         *
         * Es el caso de todos los días: el prospecto llega por el formulario
         * público —donde no hay nadie capturando— o lo mete una recepcionista.
         * Con el modo en «por turno» no se queda sin dueño, que es justo lo que
         * les hace falta a los que nadie trajo de la mano.
         */
        if ($elegido === null && $this->modo() === self::SECUENCIAL) {
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

    /** Qué se hace con los prospectos que nadie se quedó al registrarlos. */
    public function modo(): string
    {
        $modo = $this->ajustes->texto(CatalogoAjustes::ASIGNACION_ASESOR);

        return in_array($modo, [self::MANUAL, self::SECUENCIAL], true) ? $modo : self::MANUAL;
    }

    /** ¿El asesor se queda lo que él mismo registra? */
    public function seLoQuedaQuienRegistra(): bool
    {
        return $this->ajustes->bool(CatalogoAjustes::ASESOR_QUIEN_REGISTRA);
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
