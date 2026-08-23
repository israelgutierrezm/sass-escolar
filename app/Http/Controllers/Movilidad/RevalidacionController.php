<?php

declare(strict_types=1);

namespace App\Http\Controllers\Movilidad;

use App\Http\Controllers\Controller;
use App\Models\ControlEscolar\Ciclo;
use App\Models\Movilidad\DictamenRevalidacion;
use App\Models\Movilidad\Estancia;
use App\Models\Movilidad\Revalidacion;
use App\Services\Movilidad\AsentadorRevalidacion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Las revalidaciones de una estancia.
 *
 * Vive aparte del papeleo de convenios porque es el gesto delicado del módulo:
 * al aprobarse ESCRIBE en el historial académico, y de ahí sale el certificado
 * ante la SEP.
 */
class RevalidacionController extends Controller
{
    public function __construct(private readonly AsentadorRevalidacion $asentador) {}

    public function index(Estancia $estancia): Response
    {
        $estancia->load([
            'postulacion.convocatoria.convenio.institucion:id,nombre',
            'postulacion.matricula:id,persona_id,matricula,oferta_id',
            'postulacion.matricula.persona:id,nombre,primer_apellido,segundo_apellido',
        ]);

        $revalidaciones = Revalidacion::query()
            ->where('estancia_id', $estancia->id)
            ->with(['dictamen:id,nombre,asienta', 'ciclo:id,clave'])
            ->orderBy('id')
            ->get();

        return Inertia::render('Movilidad/Revalidaciones', [
            'estancia' => [
                'id' => $estancia->id,
                'quien' => $estancia->postulacion?->quien(),
                'matricula' => $estancia->postulacion?->matricula?->matricula,
                'institucion' => $estancia->institucion()?->nombre,
                'desde' => $estancia->fecha_inicio?->toDateString(),
                'hasta' => $estancia->fecha_fin?->toDateString(),
                'concluida' => $estancia->estaConcluida(),
                'es_saliente' => (bool) $estancia->postulacion?->esSaliente(),
            ],
            'revalidaciones' => $revalidaciones->map(fn (Revalidacion $r) => [
                'id' => $r->id,
                'materia_externa' => $r->materia_externa,
                'calificacion_externa' => $r->calificacion_externa,
                'plan_materia_id' => $r->plan_materia_id,
                'equivalente' => $r->calificacion_equivalente,
                'dictamen' => $r->dictamen?->nombre,
                'asentada' => $r->estaAsentada(),
                'ciclo' => $r->ciclo?->clave,
                'notas' => $r->notas,
                // Por qué no se puede asentar, dicho por su nombre: «no se
                // puede» sin motivo obliga a adivinar.
                'motivo' => $r->estaAsentada() ? null : $this->asentador->motivoParaNoAsentar($r),
            ]),
            // Sólo las que todavía se le pueden revalidar: las que ya tiene
            // aprobadas no se ofrecen, para que nadie las elija por error.
            'materias' => $this->asentador->materiasRevalidables($estancia),
            'dictamenes' => DictamenRevalidacion::query()->activos()->get(['id', 'nombre', 'asienta']),
            'ciclos' => Ciclo::query()->orderByDesc('id')->limit(12)->get(['id', 'clave']),
        ]);
    }

    public function guardar(Request $peticion, Estancia $estancia): RedirectResponse
    {
        $datos = $peticion->validate([
            'materia_externa' => ['required', 'string', 'max:200'],
            'calificacion_externa' => ['nullable', 'string', 'max:20'],
            'plan_materia_id' => ['required', Rule::exists('plan_materias', 'id')],
            'calificacion_equivalente' => ['required', 'numeric', 'min:0', 'max:100'],
            'ciclo_id' => ['required', Rule::exists('ciclos', 'id')],
            'notas' => ['nullable', 'string', 'max:1000'],
        ]);

        // Nace SIN dictaminar: capturarla y resolverla son dos gestos, y
        // juntarlos haría que el asiento ocurriera sin que nadie lo revisara.
        $pendiente = DictamenRevalidacion::pendiente();

        if ($pendiente === null) {
            return back(303)->with('error', 'No hay ningún dictamen que deje la revalidación pendiente.');
        }

        $repetida = Revalidacion::query()
            ->where('estancia_id', $estancia->id)
            ->where('plan_materia_id', $datos['plan_materia_id'])
            ->exists();

        // Lo impide el único de la base, pero el mensaje tiene que llegar
        // antes: es la trampa que ya mordió tres veces en este proyecto.
        if ($repetida) {
            return back(303)->with('error', 'Ya hay una revalidación de esa materia en esta estancia.');
        }

        Revalidacion::create($datos + ['estancia_id' => $estancia->id, 'dictamen_id' => $pendiente->id]);

        return back(303)->with('exito', 'Revalidación capturada. Falta dictaminarla.');
    }

    public function dictaminar(Request $peticion, Estancia $estancia, Revalidacion $revalidacion): RedirectResponse
    {
        abort_unless($revalidacion->estancia_id === $estancia->id, 404);

        $datos = $peticion->validate([
            'dictamen_id' => ['required', Rule::exists('dictamenes_revalidacion', 'id')],
        ]);

        try {
            $revalidacion = $this->asentador->dictaminar(
                $revalidacion,
                DictamenRevalidacion::findOrFail($datos['dictamen_id']),
            );
        } catch (RuntimeException $e) {
            return back(303)->with('error', $e->getMessage());
        }

        return back(303)->with(
            'exito',
            $revalidacion->estaAsentada()
                ? 'Revalidación aprobada y asentada en su historial académico.'
                : 'Dictamen guardado.',
        );
    }

    public function revocar(Estancia $estancia, Revalidacion $revalidacion): RedirectResponse
    {
        abort_unless($revalidacion->estancia_id === $estancia->id, 404);

        $pendiente = DictamenRevalidacion::pendiente();

        if ($pendiente === null) {
            return back(303)->with('error', 'No hay ningún dictamen que deje la revalidación pendiente.');
        }

        try {
            $this->asentador->revocar($revalidacion, $pendiente);
        } catch (RuntimeException $e) {
            return back(303)->with('error', $e->getMessage());
        }

        return back(303)->with('exito', 'Se revocó el asiento. El renglón queda dado de baja en su historial.');
    }
}
