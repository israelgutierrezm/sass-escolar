<?php

declare(strict_types=1);

namespace App\Services\Bolsa;

use App\Models\Academico\Carrera;
use App\Models\Academico\Oferta;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Admisiones\SituacionAlumno;
use App\Models\Bolsa\Colocacion;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Cuántos de los que egresaron están colocados.
 *
 * Es el número que una escuela presenta ante una acreditadora, y por eso lo que
 * gobierna este servicio son las decisiones sobre QUÉ se cuenta, no la consulta.
 *
 * ── Se cuenta por MATRÍCULA, no por persona ───────────────────────────────
 * Cada programa reporta lo suyo, y quien egresó de dos carreras egresó de las
 * dos. Contando personas, alguien con dos títulos aparecería una vez y habría
 * que decidir en cuál de sus dos programas —una decisión arbitraria que
 * ensuciaría los dos renglones—. Por eso la colocación guarda con qué matrícula
 * cuenta, y por eso al capturarla se pregunta cuando la persona tiene varias.
 *
 * ── Las que no señalan carrera se reportan APARTE, no se reparten ─────────
 * Una colocación sin matrícula no se puede atribuir a ningún programa. Meterla
 * en el total por carrera exigiría inventarle una; dejarla fuera en silencio
 * haría que la suma de los renglones no diera el total y nadie sabría por qué.
 * Se cuenta, se enseña, y se dice que le falta el dato.
 *
 * ── El denominador sale del CATÁLOGO ──────────────────────────────────────
 * Quién cuenta como egresado lo dice `situaciones_alumno.cuenta_como_egresado`,
 * no una lista de claves en el código: una escuela que agregue «Pasante» o
 * «Egresado sin titular» decide sola si entra al porcentaje.
 *
 * ── Los filtros mueven las DOS cifras ─────────────────────────────────────
 * Generación y carrera acotan a la vez el numerador y el denominador. Un filtro
 * que sólo acotara las colocaciones —«las de este año» sobre todos los
 * egresados— produciría un porcentaje que no significa nada y que aun así se
 * leería como el indicador.
 */
class IndicadorEmpleabilidad
{
    /**
     * @param  array{generacion?:string|null, carrera_id?:int|null}  $filtros
     * @return array<string, mixed>
     */
    public function resumen(array $filtros = []): array
    {
        $egresados = $this->egresados($filtros)->count();
        $colocadas = $this->colocacionesDeEgresados($filtros);

        // DISTINCT por matrícula: quien cambió de trabajo dos veces sigue siendo
        // un egresado colocado, no dos.
        $colocados = (clone $colocadas)->distinct()->count('colocaciones.matricula_oferta_id');

        $porArea = (clone $colocadas)
            ->selectRaw('colocaciones.relacionado_con_carrera as marca, COUNT(DISTINCT colocaciones.matricula_oferta_id) as cuantos')
            ->groupBy('colocaciones.relacionado_con_carrera')
            ->pluck('cuantos', 'marca');

        return [
            'egresados' => $egresados,
            'colocados' => $colocados,
            'porcentaje' => $this->porcentaje($colocados, $egresados),

            // Tres cifras y no dos: «no se preguntó» no es «no es de su área».
            'en_su_area' => (int) ($porArea[1] ?? 0),
            'fuera_de_su_area' => (int) ($porArea[0] ?? 0),
            'sin_ese_dato' => (int) ($porArea[''] ?? $porArea[null] ?? 0),

            /*
             * De dónde salieron, contado sobre LAS MISMAS colocaciones que el
             * porcentaje de arriba. Contar todas las de la escuela ponía «1 por
             * la bolsa» al lado de «0 de 14 colocados» —dos universos distintos
             * pegados—, y quien lee eso deja de creerle al tablero entero. Se vio
             * en el navegador, no en las pruebas.
             */
            'de_la_bolsa' => (clone $colocadas)->whereNotNull('colocaciones.postulacion_id')
                ->distinct()->count('colocaciones.matricula_oferta_id'),
            'de_seguimiento' => (clone $colocadas)->whereNull('colocaciones.postulacion_id')
                ->distinct()->count('colocaciones.matricula_oferta_id'),

            /*
             * Y lo que NO entra en el indicador, con su razón.
             *
             * Son dos causas distintas y la salida de cada una es otra: a la que
             * no señala carrera se le elige una; la de quien todavía no egresa
             * —una práctica profesional, por ejemplo— entrará sola cuando egrese.
             * Sin decirlo, la diferencia entre las colocaciones registradas y las
             * contadas es un misterio que hace desconfiar del número.
             */
            'sin_carrera_senalada' => Colocacion::query()->whereNull('matricula_oferta_id')->count(),
            'de_quien_no_ha_egresado' => Colocacion::query()
                ->whereNotNull('matricula_oferta_id')
                ->whereNotIn(
                    'matricula_oferta_id',
                    (clone $this->egresados([]))->select('matricula_oferta.id'),
                )
                ->count(),
        ];
    }

    /**
     * @param  array{generacion?:string|null, carrera_id?:int|null}  $filtros
     * @return array<int, array<string, mixed>>
     */
    public function porCarrera(array $filtros = []): array
    {
        $carreras = (new Carrera)->getTable();
        $ofertas = (new Oferta)->getTable();

        $egresados = $this->egresados($filtros)
            ->join($ofertas, "{$ofertas}.id", '=', 'matricula_oferta.oferta_id')
            ->selectRaw("{$ofertas}.carrera_id, COUNT(*) as cuantos")
            ->groupBy("{$ofertas}.carrera_id")
            ->pluck('cuantos', 'carrera_id');

        $colocados = $this->colocacionesDeEgresados($filtros)
            ->join($ofertas, "{$ofertas}.id", '=', 'matricula_oferta.oferta_id')
            ->selectRaw("{$ofertas}.carrera_id, COUNT(DISTINCT colocaciones.matricula_oferta_id) as cuantos")
            ->groupBy("{$ofertas}.carrera_id")
            ->pluck('cuantos', 'carrera_id');

        $nombres = DB::table($carreras)->whereNull('deleted_at')->pluck('nombre', 'id');

        return $egresados
            ->map(fn ($cuantos, $carreraId) => [
                'carrera_id' => (int) $carreraId,
                'carrera' => $nombres[$carreraId] ?? 'Ya no existe',
                'egresados' => (int) $cuantos,
                'colocados' => (int) ($colocados[$carreraId] ?? 0),
                'porcentaje' => $this->porcentaje((int) ($colocados[$carreraId] ?? 0), (int) $cuantos),
            ])
            ->sortByDesc('egresados')
            ->values()
            ->all();
    }

    /**
     * @param  array{generacion?:string|null, carrera_id?:int|null}  $filtros
     * @return array<int, array<string, mixed>>
     */
    public function porGeneracion(array $filtros = []): array
    {
        $egresados = $this->egresados($filtros)
            ->selectRaw('matricula_oferta.generacion, COUNT(*) as cuantos')
            ->groupBy('matricula_oferta.generacion')
            ->pluck('cuantos', 'generacion');

        $colocados = $this->colocacionesDeEgresados($filtros)
            ->selectRaw('matricula_oferta.generacion, COUNT(DISTINCT colocaciones.matricula_oferta_id) as cuantos')
            ->groupBy('matricula_oferta.generacion')
            ->pluck('cuantos', 'generacion');

        return $egresados
            ->map(fn ($cuantos, $generacion) => [
                'generacion' => (string) $generacion,
                'egresados' => (int) $cuantos,
                'colocados' => (int) ($colocados[$generacion] ?? 0),
                'porcentaje' => $this->porcentaje((int) ($colocados[$generacion] ?? 0), (int) $cuantos),
            ])
            ->sortByDesc('generacion')
            ->values()
            ->all();
    }

    /** Las generaciones que existen, para armar el filtro. */
    public function generaciones(): array
    {
        return $this->egresados([])
            ->distinct()
            ->orderByDesc('matricula_oferta.generacion')
            ->pluck('matricula_oferta.generacion')
            ->filter()
            ->values()
            ->all();
    }

    /** El denominador: matrículas cuya situación cuenta como egreso. */
    private function egresados(array $filtros): Builder
    {
        $ofertas = (new Oferta)->getTable();

        $situaciones = SituacionAlumno::query()->deEgresados()->pluck('id');

        return DB::table((new MatriculaOferta)->getTable())
            ->whereNull('matricula_oferta.deleted_at')
            ->whereIn('matricula_oferta.situacion_id', $situaciones)
            ->when(
                ($filtros['generacion'] ?? null) !== null,
                fn (Builder $q) => $q->where('matricula_oferta.generacion', $filtros['generacion']),
            )
            ->when(
                ($filtros['carrera_id'] ?? null) !== null,
                fn (Builder $q) => $q->whereIn(
                    'matricula_oferta.oferta_id',
                    DB::table($ofertas)->where('carrera_id', $filtros['carrera_id'])->select('id'),
                ),
            );
    }

    /**
     * El numerador: las colocaciones atadas a una matrícula que egresó.
     *
     * Se une contra el mismo conjunto del denominador y no contra todas las
     * colocaciones: si no, la de alguien que sigue estudiando —o de una carrera
     * que el filtro dejó fuera— subiría un porcentaje del que no forma parte, y
     * podría pasar del 100 %.
     */
    private function colocacionesDeEgresados(array $filtros): Builder
    {
        return DB::table('colocaciones')
            ->whereNull('colocaciones.deleted_at')
            ->joinSub(
                $this->egresados($filtros)->select('matricula_oferta.*'),
                'matricula_oferta',
                'matricula_oferta.id',
                '=',
                'colocaciones.matricula_oferta_id',
            );
    }

    private function porcentaje(int $parte, int $total): float
    {
        return $total === 0 ? 0.0 : round($parte * 100 / $total, 1);
    }
}
