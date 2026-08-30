<?php

declare(strict_types=1);

namespace App\Http\Controllers\Disciplina;

use App\Exceptions\AvisoParaElUsuario;
use App\Http\Controllers\Controller;
use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\ControlEscolar\Inscripcion;
use App\Models\Disciplina\Incidencia;
use App\Models\Disciplina\TipoIncidencia;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * El docente levanta incidencias de SUS alumnos.
 *
 * ── El alcance NO es el permiso, es la ASIGNACIÓN ──────────────────────────
 * `levantar-incidencia` dice que puede; sobre QUIÉN lo dice
 * `docente_asignatura_grupo`. El docente elige de un desplegable con sus
 * alumnos —no del padrón, como el administrativo—, y al guardar se vuelve a
 * comprobar que la matrícula sea de uno de sus grupos: la lista de la pantalla
 * no es una defensa.
 */
class DocenteIncidenciaController extends Controller
{
    public function index(Request $peticion): Response
    {
        $personaId = $peticion->user()?->persona_id;

        $alumnos = $this->misAlumnos($personaId);

        return Inertia::render('Docencia/Incidencias', [
            'alumnos' => $alumnos->values(),
            'tipos' => TipoIncidencia::query()->activos()->get(['id', 'nombre']),
            // Sólo las que ESTE docente levantó: su bitácora, no la de la escuela.
            'mias' => Incidencia::query()
                ->where('reportada_por', $personaId)
                ->with(['matricula.persona:id,nombre,primer_apellido,segundo_apellido', 'tipo:id,nombre'])
                ->orderByDesc('fecha')
                ->limit(30)
                ->get()
                ->map(fn (Incidencia $i) => [
                    'id' => $i->id,
                    'alumno' => $i->matricula?->persona?->nombreCompleto(),
                    'tipo' => $i->tipo?->nombre,
                    'fecha' => $i->fecha?->format('Y-m-d'),
                    'descripcion' => $i->descripcion,
                ]),
        ]);
    }

    public function guardar(Request $peticion): RedirectResponse
    {
        $personaId = $peticion->user()?->persona_id;

        $datos = $peticion->validate([
            'matricula_oferta_id' => ['required', 'integer'],
            'tipo_incidencia_id' => ['required', Rule::exists('tipos_incidencia', 'id')],
            'fecha' => ['required', 'date'],
            'descripcion' => ['required', 'string', 'max:2000'],
        ], [], ['tipo_incidencia_id' => 'tipo de incidencia']);

        // La matrícula tiene que ser de un alumno de SUS grupos. Se comprueba
        // contra la asignación, no contra lo que mandó el formulario.
        $suyo = $this->misAlumnos($personaId)
            ->contains(fn (array $a) => $a['matricula_oferta_id'] === (int) $datos['matricula_oferta_id']);

        AvisoParaElUsuario::si(! $suyo, 403, 'Ese alumno no está en tus grupos.');

        $datos['reportada_por'] = $personaId;
        Incidencia::create($datos);

        return back(303)->with('exito', 'Incidencia registrada.');
    }

    /**
     * Los alumnos de los grupos que imparte, sin repetir.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function misAlumnos(?int $personaId)
    {
        if ($personaId === null) {
            return collect();
        }

        $grupos = AsignaturaGrupo::query()
            ->whereHas('docentes', fn ($q) => $q->where('docentes.persona_id', $personaId))
            ->pluck('id');

        return Inscripcion::query()
            ->whereIn('asignatura_grupo_id', $grupos)
            ->with([
                'matriculaOferta:id,matricula,persona_id',
                'matriculaOferta.persona:id,nombre,primer_apellido,segundo_apellido',
                'asignaturaGrupo.grupo:id,clave',
            ])
            ->get()
            ->map(fn (Inscripcion $i) => [
                'matricula_oferta_id' => $i->matricula_oferta_id,
                'matricula' => $i->matriculaOferta?->matricula,
                'nombre' => $i->matriculaOferta?->persona?->nombreCompleto(),
                'grupo' => $i->asignaturaGrupo?->grupo?->clave,
            ])
            // Un alumno puede estar en dos de sus materias; en el picker sale una vez.
            ->unique('matricula_oferta_id');
    }
}
