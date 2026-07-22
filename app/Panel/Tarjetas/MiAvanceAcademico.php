<?php

declare(strict_types=1);

namespace App\Panel\Tarjetas;

use App\Models\Admisiones\MatriculaOferta;
use App\Models\ControlEscolar\Historial;
use App\Models\Identidad\Usuario;
use App\Panel\TarjetaPanel;
use Illuminate\Support\Facades\DB;

/**
 * Cuánto lleva avanzado el alumno de su plan.
 *
 * Cuelga de la MATRÍCULA y no de la persona: quien cursa dos programas ve dos
 * avances, porque son dos historias escolares distintas.
 */
class MiAvanceAcademico implements TarjetaPanel
{
    public function clave(): string
    {
        return 'mi-avance';
    }

    public function titulo(): string
    {
        return 'Mi avance';
    }

    public function permiso(): ?string
    {
        return 'ver-kardex';
    }

    public function tipo(): string
    {
        return 'lista';
    }

    public function ancho(): int
    {
        return 2;
    }

    public function datos(Usuario $usuario): ?array
    {
        $matriculas = MatriculaOferta::query()
            ->with('oferta.carrera:id,nombre', 'oferta.plan:id,nombre,total_creditos')
            ->where('persona_id', $usuario->persona_id)
            ->get();

        // Tener `ver-kardex` no te hace alumno: control escolar también lo
        // tiene. Sin matrículas propias, esta tarjeta no le toca.
        if ($matriculas->isEmpty()) {
            return null;
        }

        $renglones = $matriculas->map(function (MatriculaOferta $m) {
            $kardex = Historial::query()
                ->where('matricula_oferta_id', $m->id)
                ->selectRaw('count(*) as materias')
                ->selectRaw('sum(case when calificacion is not null then 1 else 0 end) as calificadas')
                ->selectRaw('avg(calificacion) as promedio')
                ->first();

            // La malla cuenta CRÉDITOS, no materias: el avance real de un plan
            // se mide en créditos aprobados sobre el total.
            $totales = (float) ($m->oferta?->plan?->total_creditos ?? 0);

            return [
                'etiqueta' => $m->oferta?->carrera?->nombre ?? 'Programa',
                'detalle' => $m->matricula,
                // El promedio NO cuenta lo que no tiene calificación: una
                // materia en curso no promedia como cero, o inscribirse bajaría
                // el promedio, que es exactamente al revés.
                'valor' => $kardex?->promedio !== null
                    ? 'Promedio '.number_format((float) $kardex->promedio, 1)
                    : 'Sin calificaciones aún',
                'progreso' => $totales > 0
                    ? min(100, (int) round(($this->creditosAprobados($m) / $totales) * 100))
                    : null,
                'pie' => (int) ($kardex?->materias ?? 0).' materias en kárdex',
            ];
        })->values()->all();

        return ['renglones' => $renglones];
    }

    /**
     * Créditos de las materias APROBADAS. Se suman de `plan_materias`, que es
     * donde vive el valor curricular; el kárdex solo dice qué se aprobó.
     */
    private function creditosAprobados(MatriculaOferta $matricula): float
    {
        return (float) Historial::query()
            ->join('plan_materias as pm', 'pm.id', '=', 'historial.plan_materia_id')
            ->join('asignaturas as a', 'a.id', '=', 'pm.asignatura_id')
            ->join('estatus_historial as eh', 'eh.id', '=', 'historial.estatus_id')
            ->where('historial.matricula_oferta_id', $matricula->id)
            ->whereNull('historial.deleted_at')
            ->where('eh.clave', 'aprobada')
            // `creditos_en_plan` es el override de la malla; si no lo hay vale
            // el del catálogo de asignaturas.
            ->sum(DB::raw('coalesce(pm.creditos_en_plan, a.creditos)'));
    }
}
