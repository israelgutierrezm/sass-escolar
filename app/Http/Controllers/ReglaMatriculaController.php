<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Academico\Campus;
use App\Models\Academico\NivelEstudio;
use App\Models\Academico\Oferta;
use App\Models\Academico\PlanEstudio;
use App\Models\Academico\ProgramaAcademico;
use App\Models\Admisiones\ContadorMatricula;
use App\Models\Admisiones\ReglaMatricula;
use App\Models\ControlEscolar\Ciclo;
use App\Services\GeneradorMatricula;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Cómo arma ESTA escuela sus matrículas.
 *
 * La regla existía desde el principio, pero sólo en la base: para cambiar el
 * formato había que entrar a MySQL. Aquí se configura, se ve el resultado antes
 * de guardar, y se ajusta el contador —que es lo que pide toda escuela que
 * migra con matrículas ya emitidas y quiere seguir desde su último número—.
 *
 * Los contadores se muestran junto a las reglas a propósito: son lo mismo
 * mirado desde el otro lado, y una regla sin ver en qué número va no se puede
 * revisar. Ver `GeneradorMatricula::claveContador` para el formato de la llave.
 */
class ReglaMatriculaController extends Controller
{
    public function __construct(private readonly GeneradorMatricula $generador) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Admisiones/ReglasMatricula/Index', [
            'reglas' => ReglaMatricula::query()
                ->orderByRaw("FIELD(ambito, 'plan', 'programa_academico', 'global')")
                ->get()
                ->map(fn (ReglaMatricula $r) => [
                    'id' => $r->id,
                    'nombre' => $r->nombre,
                    'ambito' => $r->ambito,
                    'ambito_id' => $r->ambito_id,
                    'alcance' => $r->alcance(),
                    'plantilla' => $r->plantilla,
                    'consecutivo_dimensiones' => $r->dimensiones(),
                    'consecutivo_reinicia' => $r->consecutivo_reinicia,
                    'activo' => $r->activo,
                    // Cómo se vería hoy, con una oferta que esa regla cubra.
                    'ejemplo' => $this->ejemploDe($r),
                ]),
            'contadores' => ContadorMatricula::query()
                ->orderBy('clave')
                ->get(['clave', 'valor'])
                ->map(fn (ContadorMatricula $c) => [
                    'clave' => $c->clave,
                    'valor' => $c->valor,
                    'descripcion' => $this->descripcionContador($c->clave),
                ]),
            'programas_academicos' => ProgramaAcademico::query()->orderBy('nombre')->get(['id', 'clave', 'nombre']),
            'planes' => PlanEstudio::query()->orderBy('nombre')->get(['id', 'clave', 'nombre']),
            'tokens' => ReglaMatricula::TOKENS,
            'dimensiones' => ReglaMatricula::CONSECUTIVO_DIMENSIONES,
            'reinicios' => ReglaMatricula::REINICIOS,
            'recortables' => ReglaMatricula::TOKENS_RECORTABLES,
            'cicloEnCurso' => Ciclo::enCurso()?->clave,
            'puedeEditar' => $request->user()->can('configurar-matriculas'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $this->validar($request);

        /*
         * Si ya hubo una regla para ese alcance y se borró, se REVIVE.
         *
         * El borrado es lógico —lo pone el trait de auditoría— pero el índice
         * único de (ambito, ambito_id) no distingue: la fila en la papelera
         * sigue ocupando el sitio. Crear otra para la mismo programa académico reventaba
         * con un 1062 que la pantalla mostraba como un 500, y desde la interfaz
         * no había forma de entenderlo porque la regla vieja no se ve.
         *
         * Revivir es además lo que se quiere decir: «este programa académico vuelve a
         * numerarse aparte», con el formato que se acaba de capturar.
         */
        $enPapelera = ReglaMatricula::onlyTrashed()
            ->where('ambito', $datos['ambito'])
            ->when($datos['ambito_id'] === null,
                fn ($q) => $q->whereNull('ambito_id'),
                fn ($q) => $q->where('ambito_id', $datos['ambito_id']),
            )
            ->first();

        if ($enPapelera !== null) {
            $enPapelera->restore();
            $enPapelera->update($datos);

            return back()->with('exito', 'Regla de matrícula creada. Su contador seguía guardado y continúa donde iba.');
        }

        ReglaMatricula::create($datos);

        return back()->with('exito', 'Regla de matrícula creada.');
    }

    public function update(Request $request, ReglaMatricula $regla): RedirectResponse
    {
        $regla->update($this->validar($request, $regla->id));

        return back()->with('exito', 'Regla de matrícula actualizada.');
    }

    /**
     * Una regla se borra; su contador NO.
     *
     * El contador es el rastro de los folios ya emitidos. Borrarlo con la regla
     * haría que al volver a crearla la numeración empezara otra vez en 1 y se
     * repitieran matrículas que ya están impresas en documentos.
     */
    public function destroy(ReglaMatricula $regla): RedirectResponse
    {
        if ($regla->ambito === 'global') {
            return back()->with(
                'error',
                'La regla global no se elimina: es la que aplica a todo lo que no tiene una propia. Edítala.',
            );
        }

        $regla->delete();

        return back()->with('exito', 'Regla eliminada. Su contador se conserva.');
    }

    /**
     * Ajusta un contador al número en el que va la escuela.
     *
     * El caso real: una escuela que llega con matrículas emitidas hasta la
     * 4821. Sin esto, su primer alumno en el sistema recibiría la 0001 y
     * chocaría con alguien que ya existe en sus archivos.
     *
     * Se guarda el ÚLTIMO usado, no el siguiente: es el número que ellos tienen
     * a la vista en su propio control, y pedirles que sumen uno es una fuente
     * de error gratuita.
     */
    public function ajustarContador(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'clave' => ['required', 'string', 'max:190'],
            'valor' => ['required', 'integer', 'min:0'],
        ], [], ['valor' => 'último folio usado']);

        $contador = ContadorMatricula::find($datos['clave']);

        if ($contador !== null && $datos['valor'] < $contador->valor) {
            throw ValidationException::withMessages([
                'valor' => "Ese contador ya va en {$contador->valor}. Bajarlo repetiría matrículas ya emitidas.",
            ]);
        }

        ContadorMatricula::updateOrCreate(['clave' => $datos['clave']], ['valor' => $datos['valor']]);

        return back()->with('exito', "El contador seguirá desde el {$datos['valor']}.");
    }

    /**
     * Vista previa de una plantilla que todavía no se guarda.
     *
     * No consume folio: ver `GeneradorMatricula::previsualizar`.
     */
    public function previsualizar(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'plantilla' => ['required', 'string', 'max:100'],
            'oferta_id' => ['nullable', 'integer'],
        ]);

        $oferta = $datos['oferta_id'] === null
            ? Oferta::query()->first()
            : Oferta::find($datos['oferta_id']);

        if ($oferta === null) {
            return back()->with('warning', 'Todavía no hay ninguna oferta con la que probar la plantilla.');
        }

        $ensayo = $this->generador->ensayar(
            new ReglaMatricula(['plantilla' => $datos['plantilla']]),
            $oferta,
        );

        return back()->with('vistaPrevia', $ensayo);
    }

    /**
     * @return array<string, mixed>
     */
    private function validar(Request $request, ?int $id = null): array
    {
        $datos = $request->validate([
            'nombre' => ['nullable', 'string', 'max:100'],
            'ambito' => ['required', Rule::in(ReglaMatricula::AMBITOS)],
            'ambito_id' => ['nullable', 'integer'],
            /*
             * La plantilla TIENE que traer un consecutivo.
             *
             * Sin él, todos los alumnos de la mismo programa académico y el mismo año
             * saldrían con la misma matrícula. Es el único requisito de forma:
             * el resto de la plantilla es cosa de cada escuela.
             */
            'plantilla' => ['required', 'string', 'max:100', 'regex:/\{#+\}/'],
            'consecutivo_dimensiones' => ['array'],
            'consecutivo_dimensiones.*' => [Rule::in(ReglaMatricula::CONSECUTIVO_DIMENSIONES)],
            'consecutivo_reinicia' => ['required', Rule::in(ReglaMatricula::REINICIOS)],
            'activo' => ['boolean'],
        ], [
            'plantilla.regex' => 'La plantilla necesita un consecutivo: agrega {####} donde vaya el número.',
        ]);

        // Una regla global no apunta a nada; una de programa académico o plan, sí.
        $datos['ambito_id'] = $datos['ambito'] === 'global' ? null : ($datos['ambito_id'] ?? null);

        if ($datos['ambito'] !== 'global' && $datos['ambito_id'] === null) {
            throw ValidationException::withMessages([
                'ambito_id' => 'Elige a qué '.($datos['ambito'] === 'plan' ? 'plan' : 'programa_academico').' se aplica.',
            ]);
        }

        // El unique de la tabla es (ambito, ambito_id); se traduce a un mensaje
        // que diga qué hacer en vez del error crudo de la base.
        $repetida = ReglaMatricula::query()
            ->where('ambito', $datos['ambito'])
            ->when($datos['ambito_id'] === null,
                fn ($q) => $q->whereNull('ambito_id'),
                fn ($q) => $q->where('ambito_id', $datos['ambito_id']),
            )
            ->when($id !== null, fn ($q) => $q->whereKeyNot($id))
            ->exists();

        if ($repetida) {
            throw ValidationException::withMessages([
                'ambito_id' => 'Ya hay una regla para ese alcance. Edita la que existe en vez de crear otra.',
            ]);
        }

        return $datos;
    }

    /** Cómo se vería una matrícula con esta regla, con una oferta que cubra. */
    private function ejemploDe(ReglaMatricula $regla): ?string
    {
        $oferta = Oferta::query()
            ->when($regla->ambito === 'plan', fn ($q) => $q->where('plan_id', $regla->ambito_id))
            ->when($regla->ambito === 'programa_academico', fn ($q) => $q->where('programa_academico_id', $regla->ambito_id))
            ->first();

        if ($oferta === null) {
            return null;
        }

        try {
            return $this->generador->ensayar($regla, $oferta);
        } catch (RuntimeException) {
            return null;
        }
    }

    /**
     * La llave del contador, en español.
     *
     * Se guarda como «programa académico:12|anio:2026» porque tiene que ser estable y
     * corta; nadie debería tener que descifrarla en pantalla.
     */
    private function descripcionContador(string $clave): string
    {
        $partes = [];

        foreach (explode('|', $clave) as $trozo) {
            [$tipo, $valor] = array_pad(explode(':', $trozo, 2), 2, null);

            $partes[] = match ($tipo) {
                'global' => 'Toda la escuela',
                'anio' => "año {$valor}",
                'ciclo' => 'ciclo '.(Ciclo::find($valor)?->nombre ?? $valor),
                'campus' => 'campus '.(Campus::find($valor)?->nombre ?? $valor),
                'nivel' => 'nivel '.(NivelEstudio::find($valor)?->nombre ?? $valor),
                'programa_academico' => 'programa académico '.(ProgramaAcademico::find($valor)?->nombre ?? $valor),
                'plan' => 'plan '.(PlanEstudio::find($valor)?->nombre ?? $valor),
                default => $trozo,
            };
        }

        return ucfirst(implode(' · ', $partes));
    }
}
