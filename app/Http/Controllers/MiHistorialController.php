<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Admisiones\MatriculaOferta;
use App\Models\Identidad\Usuario;
use App\Services\HistorialDelAlumno;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Su propio historial académico.
 *
 * ── Por qué existe esta pantalla ───────────────────────────────────────────
 * El rol `alumno` ya traía `ver-historial-academico`, pero el único sitio donde se pinta un
 * historial académico es el expediente de control escolar, y ése cuelga de `ver-alumnos`
 * —un permiso de personal administrativo que abre el listado de TODA la
 * escuela—. O sea: un permiso concedido sin puerta por donde entrar. Para saber
 * cómo va, el alumno tenía que pedirle a alguien que se lo consultara.
 *
 * ── La matrícula sale de la sesión ─────────────────────────────────────────
 * Nunca de la URL. No hay forma de pedir el historial académico de otro porque no hay dónde
 * escribir su id. Quien estudia dos carreras elige entre las SUYAS, y la
 * elección se comprueba contra esa misma lista.
 */
class MiHistorialController extends Controller
{
    public function __construct(private readonly HistorialDelAlumno $historial) {}

    public function __invoke(Request $peticion): Response
    {
        $matriculas = $this->matriculasDe($peticion);

        // Sin matrícula no hay historial académico: es alguien con el permiso pero sin ser
        // alumno todavía. Se dice, en vez de pintar una tabla vacía que parece
        // que se perdió su historial.
        if ($matriculas->isEmpty()) {
            return Inertia::render('MiHistorial', [
                'matriculas' => [],
                'matricula' => null,
                'renglones' => [],
                'resumen' => null,
            ]);
        }

        $elegida = $matriculas->firstWhere('id', (int) $peticion->query('matricula'))
            ?? $matriculas->first();

        /*
         * El PLAN se carga entero, sin lista de columnas.
         *
         * De él salen `total_creditos` y `minimo_asignaturas` —los denominadores
         * del resumen— y la escala con la que `HistorialDelAlumno::promedio`
         * redondea. Pidiendo `plan:id,nombre`, como se hace con la carrera y el
         * campus, esas columnas llegan en NULL y no falla nada: los créditos
         * salen «148» sin el «de 336» y el promedio se redondea con la regla por
         * omisión en vez de la del plan. Pasó al escribir esta pantalla y sólo se
         * vio comparando su resumen contra el del servicio.
         */
        $elegida->load(['oferta.carrera:id,nombre', 'oferta.plan', 'oferta.campus:id,nombre']);

        return Inertia::render('MiHistorial', [
            'matriculas' => $matriculas
                ->map(fn (MatriculaOferta $m) => [
                    'id' => $m->id,
                    'matricula' => $m->matricula,
                    'carrera' => $m->oferta?->carrera?->nombre,
                ])
                ->values(),

            'matricula' => [
                'id' => $elegida->id,
                'matricula' => $elegida->matricula,
                'carrera' => $elegida->oferta?->carrera?->nombre,
                'plan' => $elegida->oferta?->plan?->nombre,
                'campus' => $elegida->oferta?->campus?->nombre,
                'generacion' => $elegida->generacion,
            ],

            // Los mismos que ve control escolar, del mismo servicio: si el
            // promedio de aquí no fuera el de allá, alguien reclamaría con razón
            // y nadie sabría cuál de los dos está mal.
            'renglones' => $this->historial->renglones($elegida),
            'resumen' => $this->historial->resumen($elegida),
        ]);
    }

    /**
     * Sus matrículas, sacadas de la sesión.
     *
     * @return Collection<int, MatriculaOferta>
     */
    private function matriculasDe(Request $peticion)
    {
        /** @var Usuario $usuario */
        $usuario = $peticion->user();

        if ($usuario->persona_id === null) {
            return collect();
        }

        return MatriculaOferta::query()
            ->with('oferta.carrera:id,nombre')
            ->where('persona_id', $usuario->persona_id)
            ->orderBy('matricula')
            ->get();
    }
}
