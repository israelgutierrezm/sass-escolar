<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AlcanceDelAlumno;
use App\Models\ControlEscolar\Inscripcion;
use App\Services\Lms\CursosDelAlumno;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Portal del ALUMNO: las materias que cursa.
 *
 * El alcance no lo da un permiso amplio sino la PERTENENCIA, igual que en el
 * portal del padre: solo se ven las inscripciones de las matrículas de la
 * persona que entró. Cambiar el id en la URL para espiar la materia de otro
 * choca contra esa comprobación y devuelve 403.
 *
 * ── Qué arma la vista y qué NO ─────────────────────────────────────────────
 * Este controlador resuelve el ALCANCE (qué inscripciones son suyas) y pinta
 * Inertia; los DATOS —el listado y el detalle— los arma `CursosDelAlumno`,
 * porque los mismos datos los pide la app móvil por otra puerta y una segunda
 * copia divergiría. Ver ese servicio.
 */
class MisCursosController extends Controller
{
    // La pertenencia vive en un solo lugar, compartida con el aula: si alguna
    // vez hay que endurecerla, se endurece una vez.
    use AlcanceDelAlumno;

    public function __construct(private readonly CursosDelAlumno $cursos) {}

    /** Las materias que cursa, agrupadas por ciclo (el vigente primero). */
    public function index(Request $request): Response
    {
        $inscripciones = $this->misInscripciones($request)
            ->with(CursosDelAlumno::relacionesListado())
            ->get()
            ->reject(fn (Inscripcion $i) => $i->situacion?->clave === 'baja');

        return Inertia::render('MisCursos/Index', $this->cursos->listado($inscripciones));
    }

    /** Una materia: su evaluación, lo que lleva calificado, su asistencia. */
    public function show(Request $request, int $asignaturaGrupo): Response
    {
        $inscripcion = $this->miInscripcionEn($request, $asignaturaGrupo, CursosDelAlumno::relacionesDetalle());

        return Inertia::render('MisCursos/Materia', $this->cursos->detalle($inscripcion));
    }
}
