<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Academico\Carrera;
use App\Models\Admisiones\DocumentoRequerido;
use App\Models\Admisiones\EtiquetaDocumento;
use App\Models\Admisiones\ExpedienteDocumento;
use App\Models\ControlEscolar\DocumentoDocente;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Qué documentos pide la escuela y a quién.
 *
 * Este catálogo existía en la base desde la Fase 1 y se sembraba con un seeder,
 * pero no tenía pantalla: para agregar un requisito había que tocar código.
 *
 * Un mismo tipo puede pedirse a varios roles —el acta de nacimiento se le pide
 * al aspirante, al alumno y al docente— y por eso el ámbito es un pivote y no
 * una columna: darlo de alta tres veces produciría tres nombres que acabarían
 * divergiendo.
 */
class DocumentoRequeridoController extends Controller
{
    public function index(Request $request): Response
    {
        $filtros = ['ambito' => $request->query('ambito')];

        $documentos = DocumentoRequerido::query()
            ->with(['etiquetas:id,nombre', 'carreras:id,nombre'])
            ->when($filtros['ambito'], fn ($q, $ambito) => $q->delAmbito($ambito))
            ->orderBy('nombre')
            ->get()
            ->map(fn (DocumentoRequerido $d) => [
                'id' => $d->id,
                'nombre' => $d->nombre,
                'descripcion' => $d->descripcion,
                'obligatorio' => $d->obligatorio,
                'ambitos' => $d->ambitos(),
                'etiquetas' => $d->etiquetas->pluck('nombre')->all(),
                'carreras' => $d->carreras->pluck('nombre')->all(),
                'carrera_ids' => $d->carreras->pluck('id')->all(),
                // Cuántos se han entregado ya: manda al borrar.
                'entregados' => $this->entregasDe($d->id),
            ]);

        return Inertia::render('Documentos/Index', [
            'documentos' => $documentos,
            'filtros' => $filtros,
            'ambitos' => collect(DocumentoRequerido::AMBITOS)
                ->map(fn (string $nombre, string $clave) => ['clave' => $clave, 'nombre' => $nombre])
                ->values(),
            'etiquetas' => EtiquetaDocumento::query()->orderBy('nombre')->get(['id', 'nombre']),
            'carreras' => Carrera::query()->orderBy('nombre')->get(['id', 'nombre']),
            'puedeEditar' => $request->user()->can('gestionar-documentos'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $this->validar($request);

        DB::transaction(function () use ($datos): void {
            $documento = DocumentoRequerido::create([
                'nombre' => $datos['nombre'],
                'descripcion' => $datos['descripcion'] ?? null,
                'obligatorio' => $datos['obligatorio'] ?? false,
            ]);

            $documento->sincronizarAmbitos($datos['ambitos']);
            $documento->carreras()->sync($datos['carrera_ids'] ?? []);
            $documento->etiquetas()->sync($datos['etiqueta_ids'] ?? []);
        });

        return back()->with('exito', 'Documento agregado al catálogo.');
    }

    public function update(Request $request, DocumentoRequerido $documento): RedirectResponse
    {
        $datos = $this->validar($request, $documento->id);

        DB::transaction(function () use ($documento, $datos): void {
            $documento->update([
                'nombre' => $datos['nombre'],
                'descripcion' => $datos['descripcion'] ?? null,
                'obligatorio' => $datos['obligatorio'] ?? false,
            ]);

            $documento->sincronizarAmbitos($datos['ambitos']);
            $documento->carreras()->sync($datos['carrera_ids'] ?? []);
            $documento->etiquetas()->sync($datos['etiqueta_ids'] ?? []);
        });

        return back()->with('exito', 'Documento actualizado.');
    }

    /**
     * Un documento que ya alguien entregó NO se borra: sus archivos y su
     * historial de revisión cuelgan de él. Para dejar de pedirlo se le quitan
     * los ámbitos, y así sale de las listas sin perder lo entregado.
     */
    public function destroy(DocumentoRequerido $documento): RedirectResponse
    {
        $entregas = $this->entregasDe($documento->id);

        if ($entregas > 0) {
            $quienes = $entregas === 1 ? '1 persona ya lo entregó' : "{$entregas} personas ya lo entregaron";

            return back()->with(
                'error',
                "No se puede eliminar: {$quienes}. Quítale los ámbitos para dejar de pedirlo.",
            );
        }

        $documento->delete();

        return back()->with('exito', 'Documento eliminado del catálogo.');
    }

    /** Entregas vivas de este tipo, de cualquier expediente. */
    private function entregasDe(int $documentoId): int
    {
        return ExpedienteDocumento::query()->where('documento_id', $documentoId)->count()
            + DocumentoDocente::query()->where('documento_id', $documentoId)->count();
    }

    /**
     * @return array<string, mixed>
     */
    private function validar(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'nombre' => [
                'required', 'string', 'max:255',
                Rule::unique('documentos_requeridos', 'nombre')->ignore($id)->whereNull('deleted_at'),
            ],
            'descripcion' => ['nullable', 'string', 'max:255'],
            'obligatorio' => ['boolean'],
            // Al menos un ámbito: un documento que no se le pide a nadie no
            // tiene por qué nacer. Retirarlo después sí es válido.
            'ambitos' => ['required', 'array', 'min:1'],
            'ambitos.*' => [Rule::in(array_keys(DocumentoRequerido::AMBITOS))],
            'carrera_ids' => ['present', 'array'],
            'carrera_ids.*' => ['integer', Rule::exists('carreras', 'id')->whereNull('deleted_at')],
            'etiqueta_ids' => ['present', 'array'],
            'etiqueta_ids.*' => ['integer', Rule::exists('etiquetas_documento', 'id')->whereNull('deleted_at')],
        ], [
            'nombre.unique' => 'Ya hay un documento con ese nombre.',
            'ambitos.required' => 'Elige al menos a quién se le pide.',
            'ambitos.min' => 'Elige al menos a quién se le pide.',
        ], [
            'carrera_ids' => 'carreras',
            'etiqueta_ids' => 'etiquetas',
        ]);
    }
}
