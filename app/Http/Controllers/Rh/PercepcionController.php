<?php

declare(strict_types=1);

namespace App\Http\Controllers\Rh;

use App\Http\Controllers\Controller;
use App\Models\Nomina\ConceptoNomina;
use App\Models\Nomina\EsquemaPercepcion;
use App\Models\Nomina\ExpedienteLaboral;
use App\Models\Nomina\ModalidadPercepcion;
use App\Services\Nomina\RegistroPercepciones;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Cuánto se le paga a cada quien, y con qué conceptos.
 *
 * ── Detrás de `gestionar-percepciones`, no de `gestionar-rh` ──────────────
 * Quien captura altas, bajas y adscripciones no necesariamente puede ver
 * sueldos. Por eso el esquema no vive en la ficha del expediente sino en su
 * propia ruta: un `v-if` en la pantalla no es una defensa.
 */
class PercepcionController extends Controller
{
    public function __construct(private readonly RegistroPercepciones $registro) {}

    /** El historial de sueldos de un expediente. */
    public function index(ExpedienteLaboral $expediente): Response
    {
        $expediente->load([
            'persona:id,nombre,primer_apellido,segundo_apellido',
            'esquemas.modalidad:id,nombre,usa_monto_base,usa_tarifa_hora,usa_tarifa_asignatura',
        ]);

        return Inertia::render('Rh/Percepciones', [
            'expediente' => [
                'id' => $expediente->id,
                'persona' => $expediente->persona?->nombreCompleto(),
                'numero_empleado' => $expediente->numero_empleado,
                'vigente' => $expediente->sigueContratado(),
            ],
            'esquemas' => $expediente->esquemas
                ->sortByDesc('vigente_desde')
                ->values()
                ->map(fn (EsquemaPercepcion $e) => [
                    'id' => $e->id,
                    'modalidad' => $e->modalidad?->nombre,
                    'modalidad_id' => $e->modalidad_id,
                    // Se manda lo que la modalidad USA, para que la pantalla
                    // no pinte una tarifa por hora en un sueldo fijo.
                    'componentes' => $e->modalidad?->componentes() ?? [],
                    'monto_base' => $e->monto_base,
                    'tarifa_hora' => $e->tarifa_hora,
                    'tarifa_asignatura' => $e->tarifa_asignatura,
                    'desde' => $e->vigente_desde?->toDateString(),
                    'hasta' => $e->vigente_hasta?->toDateString(),
                    'abierto' => $e->estaAbierto(),
                    'notas' => $e->notas,
                ]),
            'modalidades' => ModalidadPercepcion::query()->activos()->get()
                ->map(fn (ModalidadPercepcion $m) => [
                    'id' => $m->id,
                    'nombre' => $m->nombre,
                    'componentes' => $m->componentes(),
                ]),
        ]);
    }

    public function abrir(Request $peticion, ExpedienteLaboral $expediente): RedirectResponse
    {
        $datos = $peticion->validate([
            'modalidad_id' => ['required', Rule::exists('modalidades_percepcion', 'id')],
            'vigente_desde' => ['required', 'date'],
            // Qué montos hacen falta lo decide la MODALIDAD, no esta lista: la
            // regla vive en el servicio para que la pantalla y el cálculo
            // pregunten lo mismo.
            'monto_base' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'tarifa_hora' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'tarifa_asignatura' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'notas' => ['nullable', 'string', 'max:1000'],
        ]);

        $modalidad = ModalidadPercepcion::findOrFail($datos['modalidad_id']);

        try {
            $this->registro->abrir($expediente, $modalidad, $datos['vigente_desde'], $datos);
        } catch (RuntimeException $e) {
            return back(303)->with('error', $e->getMessage());
        }

        return back(303)->with('exito', 'Esquema de percepción registrado.');
    }

    public function corregir(Request $peticion, ExpedienteLaboral $expediente, EsquemaPercepcion $esquema): RedirectResponse
    {
        abort_unless($esquema->expediente_laboral_id === $expediente->id, 404);

        $datos = $peticion->validate([
            'monto_base' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'tarifa_hora' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'tarifa_asignatura' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'notas' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $this->registro->corregir($esquema, $datos);
        } catch (RuntimeException $e) {
            return back(303)->with('error', $e->getMessage());
        }

        return back(303)->with('exito', 'Esquema corregido.');
    }

    /** Los dos catálogos que la escuela configura antes de la primera nómina. */
    public function catalogos(): Response
    {
        return Inertia::render('Rh/CatalogosNomina', [
            'modalidades' => ModalidadPercepcion::query()->orderBy('orden')->get()
                ->map(fn (ModalidadPercepcion $m) => [
                    'id' => $m->id,
                    'clave' => $m->clave,
                    'nombre' => $m->nombre,
                    'usa_monto_base' => $m->usa_monto_base,
                    'usa_tarifa_hora' => $m->usa_tarifa_hora,
                    'usa_tarifa_asignatura' => $m->usa_tarifa_asignatura,
                    'activo' => $m->activo,
                    // Una sin componentes pagaría cero. Se avisa aquí, que es
                    // donde se puede arreglar.
                    'utilizable' => $m->esUtilizable(),
                    'en_uso' => EsquemaPercepcion::query()->where('modalidad_id', $m->id)->exists(),
                ]),
            'conceptos' => ConceptoNomina::query()->orderBy('naturaleza')->orderBy('orden')->get(
                ['id', 'clave', 'nombre', 'naturaleza', 'es_gravable', 'activo']
            ),
        ]);
    }

    public function guardarModalidad(Request $peticion, ?ModalidadPercepcion $modalidad = null): RedirectResponse
    {
        $datos = $peticion->validate([
            'clave' => [
                'required', 'string', 'max:50',
                Rule::unique('modalidades_percepcion', 'clave')->ignore($modalidad?->id)->whereNull('deleted_at'),
            ],
            'nombre' => ['required', 'string', 'max:150'],
            'usa_monto_base' => ['boolean'],
            'usa_tarifa_hora' => ['boolean'],
            'usa_tarifa_asignatura' => ['boolean'],
            'activo' => ['boolean'],
        ]);

        // Una modalidad sin ningún componente pagaría cero, y el esquema que
        // cuelgue de ella produciría recibos en blanco sin un solo error.
        if (! ($datos['usa_monto_base'] ?? false) && ! ($datos['usa_tarifa_hora'] ?? false)
            && ! ($datos['usa_tarifa_asignatura'] ?? false)) {
            return back(303)->with('error', 'Enciéndele al menos un componente: sin ninguno, pagaría cero.');
        }

        $modalidad === null
            ? ModalidadPercepcion::create($datos)
            : $modalidad->update($datos);

        return back(303)->with('exito', 'Modalidad guardada.');
    }

    public function guardarConcepto(Request $peticion, ?ConceptoNomina $concepto = null): RedirectResponse
    {
        $datos = $peticion->validate([
            'clave' => [
                'required', 'string', 'max:30',
                Rule::unique('conceptos_nomina', 'clave')->ignore($concepto?->id)->whereNull('deleted_at'),
            ],
            'nombre' => ['required', 'string', 'max:150'],
            'naturaleza' => ['required', Rule::in([ConceptoNomina::PERCEPCION, ConceptoNomina::DEDUCCION])],
            'es_gravable' => ['boolean'],
            'activo' => ['boolean'],
        ]);

        $concepto === null
            ? ConceptoNomina::create($datos)
            : $concepto->update($datos);

        return back(303)->with('exito', 'Concepto guardado.');
    }
}
