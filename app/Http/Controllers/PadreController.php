<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Admisiones\MatriculaOferta;
use App\Models\ControlEscolar\Historial;
use App\Models\Finanzas\Factura;
use App\Models\Identidad\Persona;
use App\Models\Identidad\TutorAlumno;
use App\Services\EstadoCuenta;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Portal del padre / tutor familiar: la información de los alumnos que tiene
 * vinculados.
 *
 * El alcance NO lo da un permiso amplio sino la PERTENENCIA: solo ve a los hijos
 * ligados a él en `tutores_alumno`, y de cada uno solo lo que el vínculo
 * permite —lo académico y/o lo financiero—. Cambiar el id en la URL para espiar
 * a un alumno ajeno choca contra ese vínculo y devuelve 403.
 */
class PadreController extends Controller
{
    public function __construct(private readonly EstadoCuenta $estadoCuenta) {}

    public function misHijos(Request $request): Response
    {
        $persona = $request->user()->persona;

        $hijos = $persona->hijos()->get()->map(function (Persona $hijo) {
            $carreras = $hijo->matriculas()
                ->with('oferta.carrera:id,nombre')
                ->get()
                ->map(fn (MatriculaOferta $m) => $m->oferta?->carrera?->nombre)
                ->filter()
                ->values();

            return [
                'id' => $hijo->id,
                'nombre' => $hijo->nombreCompleto(),
                'foto' => $hijo->urlFoto(),
                'parentesco' => $hijo->pivot->parentesco,
                'carreras' => $carreras,
                'puede_ver_academico' => (bool) $hijo->pivot->puede_ver_academico,
                'puede_ver_finanzas' => (bool) $hijo->pivot->puede_ver_finanzas,
            ];
        })->values();

        return Inertia::render('Padre/MisHijos', ['hijos' => $hijos]);
    }

    public function hijo(Request $request, Persona $hijo): Response
    {
        $vinculo = TutorAlumno::query()
            ->where('tutor_persona_id', $request->user()->persona_id)
            ->where('alumno_persona_id', $hijo->id)
            ->first();

        abort_if($vinculo === null, 403, 'Este alumno no está vinculado a tu cuenta.');

        $matriculas = $hijo->matriculas()
            ->with([
                'oferta.carrera:id,nombre',
                'oferta.plan:id,nombre,total_creditos',
                'oferta.campus:id,nombre',
                'situacion:id,nombre',
            ])
            ->orderByDesc('fecha_ingreso')
            ->get();

        return Inertia::render('Padre/Hijo', [
            'hijo' => [
                'id' => $hijo->id,
                'nombre' => $hijo->nombreCompleto(),
                'foto' => $hijo->urlFoto(),
                'curp' => $hijo->curp,
                'parentesco' => $vinculo->parentesco,
            ],
            'permisos' => [
                'academico' => $vinculo->puede_ver_academico,
                'finanzas' => $vinculo->puede_ver_finanzas,
            ],
            'academico' => $vinculo->puede_ver_academico
                ? $matriculas->map(fn (MatriculaOferta $m) => $this->academicoDe($m))->values()
                : null,
            'finanzas' => $vinculo->puede_ver_finanzas
                ? $matriculas->map(fn (MatriculaOferta $m) => $this->finanzasDe($m))->values()
                : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function academicoDe(MatriculaOferta $m): array
    {
        $historial = Historial::query()
            ->with(['planMateria.asignatura:id,nombre,creditos', 'ciclo:id,clave', 'estatus:id,clave,nombre'])
            ->where('matricula_oferta_id', $m->id)
            ->get()
            ->sortBy([['ciclo.clave', 'asc']])
            ->values();

        $aprobadas = $historial->filter(fn (Historial $h) => $h->estatus?->clave === 'aprobada');
        $conCalif = $historial->filter(fn (Historial $h) => $h->calificacion !== null);

        return [
            'matricula' => $m->matricula,
            'carrera' => $m->oferta?->carrera?->nombre,
            'plan' => $m->oferta?->plan?->nombre,
            'estatus' => $m->estatus,
            'promedio' => $conCalif->isEmpty()
                ? null
                : round((float) $conCalif->avg(fn (Historial $h) => (float) $h->calificacion), 2),
            'creditos' => round($aprobadas->sum(
                fn (Historial $h) => (float) ($h->planMateria?->creditos_en_plan ?? $h->planMateria?->asignatura?->creditos ?? 0)
            ), 1),
            'creditos_del_plan' => $m->oferta?->plan?->total_creditos,
            'materias' => $historial->map(fn (Historial $h) => [
                'materia' => $h->planMateria?->asignatura?->nombre,
                'ciclo' => $h->ciclo?->clave,
                'calificacion' => $h->calificacion,
                'estatus' => $h->estatus?->nombre,
                'estatus_clave' => $h->estatus?->clave,
            ]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function finanzasDe(MatriculaOferta $m): array
    {
        $cuenta = $this->estadoCuenta->para($m);

        $facturas = Factura::query()
            ->where('matricula_oferta_id', $m->id)
            ->orderByDesc('id')
            ->get()
            ->map(fn (Factura $f) => [
                'uuid' => $f->uuid,
                'total' => (float) $f->total,
                'estatus' => $f->estatus,
                'fecha' => $f->fecha_timbrado?->toDateString(),
            ]);

        return [
            'matricula' => $m->matricula,
            'carrera' => $m->oferta?->carrera?->nombre,
            'saldo' => $cuenta['resumen']['saldo'],
            'adeudos' => $cuenta['adeudos'],
            'pagos' => $cuenta['pagos'],
            'facturas' => $facturas,
        ];
    }
}
