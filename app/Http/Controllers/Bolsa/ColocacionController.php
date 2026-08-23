<?php

declare(strict_types=1);

namespace App\Http\Controllers\Bolsa;

use App\Http\Controllers\Controller;
use App\Models\Academico\Carrera;
use App\Models\Bolsa\Colocacion;
use App\Models\Bolsa\Empresa;
use App\Models\Bolsa\Postulacion;
use App\Models\Bolsa\Vacante;
use App\Services\Bolsa\IndicadorEmpleabilidad;
use App\Services\Bolsa\RegistradorColocacion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Las colocaciones y el indicador de empleabilidad.
 *
 * ── Dos puertas al mismo hecho ────────────────────────────────────────────
 * `contratar()` cierra una postulación de la bolsa; `guardar()` registra lo que
 * un egresado consiguió por su cuenta y la escuela supo al darle seguimiento.
 * Las dos pasan por `RegistradorColocacion` para que el indicador cuente lo
 * mismo venga por donde venga.
 */
class ColocacionController extends Controller
{
    public function __construct(
        private readonly RegistradorColocacion $registrador,
        private readonly IndicadorEmpleabilidad $indicador,
    ) {}

    public function index(Request $peticion): Response
    {
        $colocaciones = Colocacion::query()
            ->with([
                'persona:id,nombre,primer_apellido,segundo_apellido',
                'empresa:id,razon_social',
                'matricula:id,matricula,oferta_id',
                'matricula.oferta:id,carrera_id',
                'matricula.oferta.carrera:id,nombre',
            ])
            ->when($peticion->filled('empresa_id'), fn ($q) => $q->where('empresa_id', $peticion->integer('empresa_id')))
            ->when(
                $peticion->filled('origen'),
                fn ($q) => $peticion->query('origen') === 'bolsa'
                    ? $q->whereNotNull('postulacion_id')
                    : $q->whereNull('postulacion_id'),
            )
            ->orderByDesc('fecha_ingreso')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Bolsa/Colocaciones', [
            'colocaciones' => $colocaciones->through(fn (Colocacion $c) => [
                'id' => $c->id,
                'persona' => $c->persona?->nombreCompleto(),
                'matricula' => $c->matricula?->matricula,
                'carrera' => $c->matricula?->oferta?->carrera?->nombre,
                'empresa' => $c->empresa?->razon_social,
                'puesto' => $c->puesto,
                'salario' => $c->salario === null ? null : '$'.number_format((float) $c->salario, 2),
                'fecha_ingreso' => $c->fecha_ingreso?->toDateString(),
                // Tres estados: null no es «no». Ver el modelo.
                'relacionado' => $c->relacionado_con_carrera,
                'notas' => $c->notas,
                'origen' => $c->salioDeLaBolsa() ? 'Bolsa de trabajo' : 'Seguimiento',
            ]),
            'filtros' => [
                'empresa_id' => $peticion->integer('empresa_id') ?: null,
                'origen' => $peticion->query('origen'),
            ],
            'empresas' => Empresa::query()->publicables()->orderBy('razon_social')->get(['id', 'razon_social']),
        ]);
    }

    /** Seguimiento de egresados: no salió de ninguna vacante nuestra. */
    public function guardar(Request $peticion): RedirectResponse
    {
        $datos = $peticion->validate($this->reglas() + [
            'persona_id' => ['required', 'integer', Rule::exists('personas', 'id')->whereNull('deleted_at')],
            'matricula_oferta_id' => ['nullable', 'integer'],
            'empresa_id' => ['required', 'integer', Rule::exists('empresas', 'id')->whereNull('deleted_at')],
        ]);

        try {
            $this->registrador->directa($datos);
        } catch (RuntimeException $e) {
            return back(303)->with('error', $e->getMessage());
        }

        return back(303)->with('exito', 'Colocación registrada.');
    }

    /** Cierra una postulación como contratada, con su colocación. */
    public function contratar(Request $peticion, Vacante $vacante, Postulacion $postulacion): RedirectResponse
    {
        abort_unless($postulacion->vacante_id === $vacante->id, 404);

        $datos = $peticion->validate($this->reglas() + [
            // Por omisión, la empresa de la vacante. Se deja elegir porque un
            // grupo empresarial contrata por una razón social distinta de la que
            // publicó, y forzar la de la vacante metería el dato equivocado.
            'empresa_id' => ['nullable', 'integer', Rule::exists('empresas', 'id')->whereNull('deleted_at')],
        ]);

        try {
            $this->registrador->desdePostulacion($postulacion, $datos, (int) $peticion->user()->persona_id);
        } catch (RuntimeException $e) {
            return back(303)->with('error', $e->getMessage());
        }

        return back(303)->with('exito', 'Colocación registrada.');
    }

    public function actualizar(Request $peticion, Colocacion $colocacion): RedirectResponse
    {
        $datos = $peticion->validate($this->reglas() + [
            'empresa_id' => ['required', 'integer', Rule::exists('empresas', 'id')->whereNull('deleted_at')],
        ]);

        // Ni la persona ni la matrícula se editan: son a quién y a qué programa
        // cuenta esta colocación, y cambiarlos mueve el número de dos renglones
        // del reporte a la vez. Para eso se deshace y se vuelve a capturar.
        $colocacion->update($datos);

        return back(303)->with('exito', 'Colocación actualizada.');
    }

    public function eliminar(Request $peticion, Colocacion $colocacion): RedirectResponse
    {
        $this->registrador->deshacer($colocacion, (int) $peticion->user()->persona_id);

        return back(303)->with('exito', 'Se deshizo la colocación.');
    }

    public function indicadores(Request $peticion): Response
    {
        $filtros = [
            'generacion' => $peticion->query('generacion') ?: null,
            'carrera_id' => $peticion->integer('carrera_id') ?: null,
        ];

        return Inertia::render('Bolsa/Empleabilidad', [
            'resumen' => $this->indicador->resumen($filtros),
            'por_carrera' => $this->indicador->porCarrera($filtros),
            'por_generacion' => $this->indicador->porGeneracion($filtros),
            'filtros' => $filtros,
            'generaciones' => $this->indicador->generaciones(),
            'carreras' => Carrera::query()->orderBy('nombre')->get(['id', 'nombre']),
        ]);
    }

    /** Lo que describe el empleo, común a los tres caminos. */
    private function reglas(): array
    {
        return [
            'puesto' => ['required', 'string', 'max:200'],
            'salario' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'fecha_ingreso' => ['required', 'date'],
            // Nullable a propósito: «no se preguntó» es una respuesta y no se
            // puede confundir con «no es de su área».
            'relacionado_con_carrera' => ['nullable', 'boolean'],
            'notas' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
