<?php

declare(strict_types=1);

namespace App\Panel\Tarjetas;

use App\Models\Admisiones\Aspirante;
use App\Models\Admisiones\EstadoDocumento;
use App\Models\Admisiones\ExpedienteDocumento;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\ControlEscolar\DocumentoAlumno;
use App\Models\Identidad\DocumentoTutor;
use App\Models\Identidad\Persona;
use App\Models\Identidad\Usuario;
use App\Panel\TarjetaPanel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * La bandeja de ventanilla: papeles que alguien subió y nadie ha revisado.
 *
 * ── Los TRES expedientes, en una sola cola ────────────────────────────────
 * Aspirantes, alumnos y padres o tutores. Son tres tablas distintas —a
 * propósito: los papeles del padre no deben asomar en el expediente del hijo—
 * pero quien valida hace UNA pregunta, «¿qué me está esperando?», y con tres
 * tarjetas tendría que sumarlas de memoria y acordarse de mirar las tres.
 *
 * Cada renglón lleva su propio enlace al expediente que le toca, que es lo que
 * permite mezclarlos sin que se confundan.
 *
 * ── Se agrupa por PERSONA, no por documento ───────────────────────────────
 * Quien valida abre un expediente y revisa lo que traiga; no atiende archivos
 * sueltos. Un renglón por papel repetiría el mismo nombre cinco veces y haría
 * ver una cola cinco veces más larga de lo que es.
 *
 * ── Se atiende por antigüedad ─────────────────────────────────────────────
 * El orden es la fecha del papel MÁS VIEJO de cada expediente, no cuántos trae:
 * el que lleva doce días esperando no puede quedar debajo del que subió seis
 * hoy.
 *
 * ── Sin alerta por renglón, a propósito ───────────────────────────────────
 * No hay ningún ajuste en la configuración que diga a partir de cuántos días un
 * expediente va retrasado. Cablear «siete» sería inventarle a cada escuela una
 * regla que no eligió; la antigüedad se dice con palabras y el orden ya pone
 * arriba lo que más ha esperado.
 */
class ExpedientesPorValidar implements TarjetaPanel
{
    private const A_LA_VISTA = 8;

    public function clave(): string
    {
        return 'expedientes-por-validar';
    }

    public function titulo(): string
    {
        return 'Expedientes por validar';
    }

    public function permiso(): ?string
    {
        return 'validar-expediente';
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
        return 'M10.125 2.25h-4.5c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125v-9M10.125 2.25h.375a9 9 0 0 1 9 9v.375M10.125 2.25A3.375 3.375 0 0 1 13.5 5.625v1.5c0 .621.504 1.125 1.125 1.125h1.5a3.375 3.375 0 0 1 3.375 3.375M9 15l2.25 2.25L15 12';
    }

    public function datos(Usuario $usuario): ?array
    {
        // Por CLAVE y no por id: `estados_documento` es catálogo de cada
        // escuela, así que el id es del demo y la clave es el contrato.
        $pendiente = EstadoDocumento::query()->where('clave', 'pendiente')->value('id');

        if ($pendiente === null) {
            return null;
        }

        $campus = $usuario->campusVisibles();

        $enEspera = fn () => ExpedienteDocumento::query()
            ->where('estado_documento_id', $pendiente)
            /*
             * El `whereHas` NO es sólo el filtro por campus, y por eso se pone
             * aunque el alcance sea global: dar de baja a un aspirante es un
             * borrado LÓGICO que no se lleva sus papeles, así que sin esto la
             * cola muestra renglones fantasma cuyo enlace responde 404. En el
             * demo hay cinco documentos colgando de un aspirante que ya no
             * existe.
             */
            ->whereHas('aspirante', fn (Builder $q) => $campus === null
                ? $q
                // Un aspirante sin campus no es de nadie: esconderlo de todos lo
                // dejaría sin atender para siempre.
                : $q->where(fn (Builder $w) => $w->whereIn('campus_id', $campus)->orWhereNull('campus_id')));

        $total = (int) $enEspera()->distinct()->count('aspirante_id');

        /*
         * La bandeja vacía no se dibuja —es cola de trabajo— pero eso lo decide
         * `mezclar()` con las TRES colas juntas: sin aspirantes en espera puede
         * haber alumnos, y devolver null aquí volvería a esconderlos.
         */
        if ($total === 0) {
            return $this->mezclar([], 0, $pendiente, $campus);
        }

        $conteos = $enEspera()
            ->groupBy('aspirante_id')
            ->select('aspirante_id', DB::raw('count(*) as papeles'), DB::raw('min(created_at) as desde'))
            ->orderBy('desde')
            ->limit(self::A_LA_VISTA)
            ->get();

        $aspirantes = Aspirante::query()
            ->whereIn('id', $conteos->pluck('aspirante_id'))
            ->with(['persona:id,nombre,primer_apellido,segundo_apellido', 'etapa:id,nombre'])
            ->get()
            ->keyBy('id');

        $renglones = $conteos->map(function ($fila) use ($aspirantes) {
            $aspirante = $aspirantes->get($fila->aspirante_id);
            $papeles = (int) $fila->papeles;

            return [
                'etiqueta' => $aspirante?->persona?->nombreCompleto() ?? 'Aspirante',
                'valor' => $papeles === 1 ? '1 papel' : "{$papeles} papeles",
                'detalle' => 'Aspirante'.($aspirante?->etapa?->nombre !== null ? ' · '.$aspirante->etapa->nombre : ''),
                'pie' => $this->espera($fila->desde),
                'progreso' => null,
                'alerta' => null,
                'enlace' => '/aspirantes/'.$fila->aspirante_id,
                'desde' => $fila->desde,
            ];
        })->all();

        return $this->mezclar($renglones, $total, $pendiente, $campus);
    }

    /**
     * Suma a la cola de aspirantes la de alumnos y la de tutores.
     *
     * Los tres van ordenados por ANTIGÜEDAD del papel más viejo, mezclados: lo
     * que lleva doce días esperando va arriba sea de quien sea, que es como se
     * atiende una ventanilla.
     *
     * @param  array<int, array<string, mixed>>  $renglones
     * @param  array<int, int>|null  $campus
     */
    private function mezclar(array $renglones, int $total, int $pendiente, ?array $campus): ?array
    {
        foreach ($this->deAlumnos($pendiente, $campus) as $fila) {
            $renglones[] = $fila;
            $total++;
        }

        foreach ($this->deTutores($pendiente) as $fila) {
            $renglones[] = $fila;
            $total++;
        }

        if ($renglones === []) {
            return null;
        }

        usort($renglones, fn (array $a, array $b) => strcmp((string) $a['desde'], (string) $b['desde']));
        $renglones = array_slice($renglones, 0, self::A_LA_VISTA);

        // `desde` era sólo para ordenar; la tarjeta no lo pinta.
        foreach ($renglones as $i => $fila) {
            unset($renglones[$i]['desde']);
        }

        return [
            'renglones' => array_values($renglones),
            'pie' => $total === 1 ? 'un expediente en espera' : "{$total} expedientes en espera",
            'enlace' => '/aspirantes',
        ];
    }

    /**
     * Los papeles de alumnos sin revisar.
     *
     * ── El enlace va a una MATRÍCULA ──────────────────────────────────────
     * `documentos_alumno` cuelga de la persona, pero el expediente se abre por
     * matrícula. Se toma cualquiera de las suyas —los papeles son los mismos en
     * todas, y la ficha lo dice— y quien no tenga ninguna no se enseña: el
     * renglón llevaría a un 404.
     *
     * @param  array<int, int>|null  $campus
     * @return array<int, array<string, mixed>>
     */
    private function deAlumnos(int $pendiente, ?array $campus): array
    {
        $conteos = DocumentoAlumno::query()
            ->where('estado_documento_id', $pendiente)
            ->groupBy('persona_id')
            ->select('persona_id', DB::raw('count(*) as papeles'), DB::raw('min(created_at) as desde'))
            ->orderBy('desde')
            ->limit(self::A_LA_VISTA)
            ->get();

        if ($conteos->isEmpty()) {
            return [];
        }

        $matriculas = MatriculaOferta::query()
            ->whereIn('persona_id', $conteos->pluck('persona_id'))
            // El mismo recorte por campus que el resto del expediente del alumno.
            ->when($campus !== null, fn (Builder $q) => $q->whereHas('oferta', fn (Builder $o) => $o->whereIn('campus_id', $campus)))
            ->with('persona:id,nombre,primer_apellido,segundo_apellido')
            ->get()
            ->groupBy('persona_id');

        return $conteos
            ->filter(fn ($fila) => $matriculas->has($fila->persona_id))
            ->map(function ($fila) use ($matriculas) {
                $matricula = $matriculas[$fila->persona_id]->first();
                $papeles = (int) $fila->papeles;

                return [
                    'etiqueta' => $matricula->persona?->nombreCompleto() ?? 'Alumno',
                    'valor' => $papeles === 1 ? '1 papel' : "{$papeles} papeles",
                    'detalle' => 'Alumno · '.($matricula->matricula ?? 'sin matrícula'),
                    'pie' => $this->espera($fila->desde),
                    'progreso' => null,
                    'alerta' => null,
                    'enlace' => '/escolar/alumnos/'.$matricula->id,
                    'desde' => $fila->desde,
                ];
            })->values()->all();
    }

    /**
     * Los papeles de padres y tutores sin revisar.
     *
     * SIN recorte por campus, y no por descuido: un tutor no tiene campus —sus
     * hijos pueden estar en dos— y acotarlo por el de alguno haría que
     * apareciera y desapareciera según quién mire. Es la misma decisión que la
     * fuente de vínculos familiares de Reportes.
     *
     * @return array<int, array<string, mixed>>
     */
    private function deTutores(int $pendiente): array
    {
        $conteos = DocumentoTutor::query()
            ->where('estado_documento_id', $pendiente)
            ->groupBy('persona_id')
            ->select('persona_id', DB::raw('count(*) as papeles'), DB::raw('min(created_at) as desde'))
            ->orderBy('desde')
            ->limit(self::A_LA_VISTA)
            ->get();

        if ($conteos->isEmpty()) {
            return [];
        }

        $personas = Persona::query()
            ->whereIn('id', $conteos->pluck('persona_id'))
            ->get(['id', 'nombre', 'primer_apellido', 'segundo_apellido'])
            ->keyBy('id');

        return $conteos->map(function ($fila) use ($personas) {
            $papeles = (int) $fila->papeles;

            return [
                'etiqueta' => $personas->get($fila->persona_id)?->nombreCompleto() ?? 'Tutor',
                'valor' => $papeles === 1 ? '1 papel' : "{$papeles} papeles",
                'detalle' => 'Padre o tutor',
                'pie' => $this->espera($fila->desde),
                'progreso' => null,
                'alerta' => null,
                'enlace' => '/padres-tutores/'.$fila->persona_id,
                'desde' => $fila->desde,
            ];
        })->values()->all();
    }

    private function espera(string $desde): string
    {
        $dias = (int) Carbon::parse($desde)->startOfDay()->diffInDays(now()->startOfDay());

        return $dias === 0 ? 'llegó hoy' : "espera desde hace {$dias} d";
    }
}
