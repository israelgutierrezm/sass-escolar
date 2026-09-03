<?php

declare(strict_types=1);

namespace App\Http\Controllers\ProcesosFormativos;

use App\Exceptions\AvisoParaElUsuario;
use App\Http\Controllers\Controller;
use App\Models\Academico\ProgramaAcademico;
use App\Models\ProcesosFormativos\ModalidadProceso;
use App\Models\ProcesosFormativos\OrganizacionReceptora;
use App\Models\ProcesosFormativos\PlazaProceso;
use App\Models\ProcesosFormativos\TipoProcesoFormativo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Las plazas y proyectos que ofrecen las organizaciones.
 *
 * ── El CUPO no se teclea, se OCUPA ─────────────────────────────────────────
 * `cupo` lo captura quien administra la plaza; `cupo_ocupado` lo mueve la
 * asignación (fase 4) dentro de su transacción y con la plaza bloqueada. Por
 * eso `cupo_ocupado` NO está en el `fillable`: dejarlo entrar por un formulario
 * permitiría escribirlo a mano y el cupo dejaría de significar nada.
 *
 * Y bajar el cupo por debajo de lo ya ocupado se rehúsa: el CHECK de la base lo
 * impediría con un error de SQL en la cara de quien captura, así que se detiene
 * antes y se explica.
 *
 * ── Sin programas señalados, se ofrece a TODOS ─────────────────────────────
 * Misma regla que el alcance de la organización y que las vacantes de la bolsa:
 * exigir al menos uno obligaría a palomear veinte cada vez, y la mitad de las
 * plazas reales aceptan a cualquiera. La pantalla lo dice con palabras, porque
 * un hueco se lee como captura incompleta.
 */
class PlazaProcesoController extends Controller
{
    public const POR_PAGINA = 25;

    public function index(Request $peticion): Response
    {
        $filtros = $peticion->validate([
            'busca' => ['nullable', 'string', 'max:120'],
            'organizacion_id' => ['nullable', 'integer'],
            'tipo_proceso_id' => ['nullable', 'integer'],
            'solo_disponibles' => ['nullable'],
        ]);

        /*
         * `boolean()` y no el valor validado: `nullable` devuelve la cadena
         * «0» tal cual, que en PHP es verdadera, y el filtro no se podría
         * apagar. Es la trampa que ya se cobró el motor de reportes y la
         * pantalla de documentación — validar no es convertir.
         */
        $soloDisponibles = $peticion->boolean('solo_disponibles');

        $consulta = PlazaProceso::query()
            ->with([
                'organizacion:id,razon_social,nombre_comercial',
                'tipoProceso:id,nombre',
                'modalidad:id,nombre',
                'programasAcademicos:id,nombre',
            ])
            ->when(
                ($filtros['busca'] ?? '') !== '',
                fn (Builder $q) => $q->where(fn (Builder $w) => $w
                    ->where('nombre', 'like', '%'.$filtros['busca'].'%')
                    ->orWhereHas('organizacion', fn (Builder $o) => $o
                        ->where('razon_social', 'like', '%'.$filtros['busca'].'%')
                        ->orWhere('nombre_comercial', 'like', '%'.$filtros['busca'].'%'))),
            )
            ->when(($filtros['organizacion_id'] ?? null) !== null, fn (Builder $q) => $q->where('organizacion_id', $filtros['organizacion_id']))
            ->when(($filtros['tipo_proceso_id'] ?? null) !== null, fn (Builder $q) => $q->where('tipo_proceso_id', $filtros['tipo_proceso_id']))
            ->when($soloDisponibles, fn (Builder $q) => $q->disponibles());

        return Inertia::render('Procesos/Plazas/Index', [
            'plazas' => $consulta
                ->orderBy('nombre')
                ->paginate(self::POR_PAGINA)
                ->withQueryString()
                ->through(fn (PlazaProceso $p) => [
                    'id' => $p->id,
                    'nombre' => $p->nombre,
                    'organizacion' => $p->organizacion?->comoSeLeConoce(),
                    'organizacion_id' => $p->organizacion_id,
                    'tipo' => $p->tipoProceso?->nombre,
                    'tipo_proceso_id' => $p->tipo_proceso_id,
                    'modalidad' => $p->modalidad?->nombre,
                    'modalidad_id' => $p->modalidad_id,
                    'cupo' => $p->cupo,
                    'cupo_ocupado' => $p->cupo_ocupado,
                    'libres' => $p->lugaresLibres(),
                    'abierta' => $p->abierta,
                    // Las tres: abierta, con lugar y dentro de fecha. Una sola
                    // engaña —una plaza «abierta» con la fecha pasada se ve bien—.
                    'admite' => $p->admiteA(),
                    'vencida' => $p->estaVencida(),
                    'fecha_inicio' => $p->fecha_inicio?->toDateString(),
                    'fecha_cierre' => $p->fecha_cierre?->toDateString(),
                    'duracion_estimada_horas' => $p->duracion_estimada_horas,
                    'apoyo_economico' => $p->apoyo_economico,
                    'ubicacion' => $p->ubicacion,
                    'horario' => $p->horario,
                    'descripcion' => $p->descripcion,
                    'actividades' => $p->actividades,
                    'requisitos' => $p->requisitos,
                    'responsable' => $p->responsable,
                    'programas' => $p->programasAcademicos->pluck('nombre'),
                    'programa_ids' => $p->programasAcademicos->pluck('id'),
                ]),

            'filtros' => (object) ($filtros + ['solo_disponibles' => $soloDisponibles]),
            'catalogos' => [
                'organizaciones' => OrganizacionReceptora::query()
                    ->queReciben()
                    ->orderBy('razon_social')
                    ->get(['id', 'razon_social', 'nombre_comercial'])
                    ->map(fn (OrganizacionReceptora $o) => ['id' => $o->id, 'nombre' => $o->comoSeLeConoce()]),
                'tiposProceso' => TipoProcesoFormativo::query()->activos()->get(['id', 'nombre']),
                'modalidades' => ModalidadProceso::query()->activos()->get(['id', 'nombre']),
                'programas' => ProgramaAcademico::query()->orderBy('nombre')->get(['id', 'nombre']),
            ],
            'puedeEditar' => $peticion->user()->can('gestionar-plazas-formativas'),
        ]);
    }

    public function guardar(Request $peticion, ?PlazaProceso $plaza = null): RedirectResponse
    {
        $datos = $peticion->validate([
            'organizacion_id' => ['required', 'integer', 'exists:organizaciones_receptoras,id'],
            'tipo_proceso_id' => ['required', 'integer', 'exists:tipos_proceso_formativo,id'],
            'modalidad_id' => ['nullable', 'integer', 'exists:modalidades_proceso,id'],
            'nombre' => ['required', 'string', 'max:255'],
            'descripcion' => ['nullable', 'string', 'max:4000'],
            'actividades' => ['nullable', 'string', 'max:4000'],
            'ubicacion' => ['nullable', 'string', 'max:255'],
            'horario' => ['nullable', 'string', 'max:255'],
            'cupo' => ['required', 'integer', 'min:1'],
            'fecha_inicio' => ['nullable', 'date'],
            'fecha_cierre' => ['nullable', 'date', 'after_or_equal:fecha_inicio'],
            'duracion_estimada_horas' => ['nullable', 'integer', 'min:1'],
            'apoyo_economico' => ['nullable', 'numeric', 'min:0'],
            'requisitos' => ['nullable', 'string', 'max:4000'],
            'responsable' => ['nullable', 'string', 'max:255'],
            'abierta' => ['boolean'],
            'programa_ids' => ['array'],
            'programa_ids.*' => ['integer', 'exists:programas_academicos,id'],
        ], [
            'fecha_cierre.after_or_equal' => 'La plaza no puede cerrar antes de empezar.',
        ]);

        $datos['abierta'] = $peticion->boolean('abierta');

        /*
         * El cupo no baja por debajo de lo ya ocupado.
         *
         * El CHECK de la base lo impide igual, pero con un error de SQL en la
         * cara de quien captura. Se detiene antes y se dice cuántos hay dentro:
         * la salida es cerrar la plaza, no encoger el cupo.
         */
        AvisoParaElUsuario::si(
            $plaza !== null && $plaza->exists && $datos['cupo'] < (int) $plaza->cupo_ocupado,
            422,
            "Ya hay {$plaza?->cupo_ocupado} alumno(s) asignado(s) a esta plaza: el cupo no puede quedar por debajo. "
            .'Si ya no quieres que reciba más, ciérrala.',
        );

        $programas = $datos['programa_ids'] ?? [];
        unset($datos['programa_ids']);

        DB::transaction(function () use (&$plaza, $datos, $programas) {
            $plaza ??= new PlazaProceso;
            $plaza->fill($datos)->save();
            $plaza->programasAcademicos()->sync($programas);
        });

        return back(303)->with('exito', 'Plaza guardada.');
    }

    /**
     * Abrir o cerrar la plaza.
     *
     * Cerrar NO libera lo ya asignado ni toca `cupo_ocupado`: quien está dentro
     * sigue dentro. Lo único que cambia es que deja de recibir gente nueva.
     */
    public function alternar(PlazaProceso $plaza): RedirectResponse
    {
        $plaza->update(['abierta' => ! $plaza->abierta]);

        return back(303)->with(
            'exito',
            $plaza->abierta
                ? 'Plaza abierta: vuelve a recibir asignaciones.'
                : 'Plaza cerrada. Quien ya está asignado sigue igual.',
        );
    }

    /**
     * Se borra sólo la que NUNCA recibió a nadie.
     *
     * Con gente dentro, borrarla dejaría expedientes apuntando a una plaza que
     * no existe. Para retirarla se cierra, que es lo que la pantalla ofrece.
     */
    public function eliminar(PlazaProceso $plaza): RedirectResponse
    {
        AvisoParaElUsuario::si(
            (int) $plaza->cupo_ocupado > 0,
            422,
            'Esta plaza tiene alumnos asignados y no se puede eliminar: dejaría sus expedientes '
            .'apuntando a algo que ya no existe. Ciérrala para que deje de recibir.',
        );

        $plaza->delete();

        return back(303)->with('exito', 'Plaza eliminada.');
    }
}
