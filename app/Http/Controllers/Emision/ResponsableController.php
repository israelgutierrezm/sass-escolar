<?php

declare(strict_types=1);

namespace App\Http\Controllers\Emision;

use App\Http\Controllers\Controller;
use App\Models\Emision\Cargo;
use App\Models\Emision\Responsable;
use App\Models\Emision\TipoResponsable;
use App\Models\Emision\TituloProfesional;
use App\Services\LectorCertificado;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Responsables que firman la certificación (tipo 1) y la titulación (tipo 2).
 * Un mismo controlador sirve ambas secciones: el tipo y la sección llegan como
 * defaults de la ruta. Certificación admite 1 responsable; Titulación hasta 2.
 */
class ResponsableController extends Controller
{
    /** Cuántos responsables admite cada tipo. */
    private const MAXIMO = [
        TipoResponsable::CERTIFICACION => 1,
        TipoResponsable::TITULACION => 2,
    ];

    public function index(Request $request): Response
    {
        $tipo = (int) $request->route('tipo');
        $seccion = (string) $request->route('seccion');

        return Inertia::render('Emision/Responsables', [
            'seccion' => $seccion,
            'tituloSeccion' => $this->tituloSeccion($seccion),
            'maximo' => self::MAXIMO[$tipo] ?? 1,
            'responsables' => Responsable::deTipo($tipo)
                ->with(['cargo:id,nombre', 'tituloProfesional:id,abreviatura,descripcion'])
                ->orderBy('id')
                ->get()
                ->map(fn (Responsable $r) => [
                    'id' => $r->id,
                    'nombre_completo' => $r->nombreCompleto(),
                    'curp' => $r->curp,
                    'cargo' => $r->cargo?->nombre,
                    'titulo' => $r->tituloProfesional?->abreviatura,
                    'cer_titular' => $r->cer_titular,
                    'cer_serial' => $r->cer_serial,
                    'vigencia_inicio' => $r->cer_vigencia_inicio?->format('d/m/Y'),
                    'vigencia_fin' => $r->cer_vigencia_fin?->format('d/m/Y'),
                ]),
            'cargos' => Cargo::orderBy('nombre')->get(['id', 'nombre']),
            'titulos' => TituloProfesional::orderBy('abreviatura')->get(['id', 'abreviatura', 'descripcion']),
        ]);
    }

    /**
     * Lee un .cer subido y devuelve sus datos (sin guardar): el formulario los
     * muestra para que el usuario solo complete cargo y título.
     */
    public function leerCertificado(Request $request, LectorCertificado $lector): JsonResponse
    {
        $request->validate(['certificado' => ['required', 'file', 'max:64']]);

        $contenido = (string) file_get_contents($request->file('certificado')->getRealPath());

        if (! $lector->esValido($contenido)) {
            return response()->json(['error' => 'El archivo no es un certificado (.cer) válido.'], 422);
        }

        return response()->json($lector->leer($contenido));
    }

    public function store(Request $request, LectorCertificado $lector): RedirectResponse
    {
        $tipo = (int) $request->route('tipo');

        $datos = $request->validate([
            'certificado' => ['required', 'file', 'max:64'],
            'cargo_id' => ['required', 'integer', Rule::exists('cargos', 'id')],
            'titulo_profesional_id' => ['required', 'integer', Rule::exists('titulos_profesionales', 'id')],
        ]);

        // El límite es regla de negocio: certificación 1, titulación 2.
        $maximo = self::MAXIMO[$tipo] ?? 1;
        if (Responsable::deTipo($tipo)->count() >= $maximo) {
            return back()->with('error', "Este apartado admite máximo {$maximo} responsable(s). Elimina uno para agregar otro.");
        }

        // La identidad SIEMPRE se toma del certificado (server-side), no del
        // formulario: es el dato de confianza. Solo cargo y título los pone el usuario.
        $contenido = (string) file_get_contents($request->file('certificado')->getRealPath());
        if (! $lector->esValido($contenido)) {
            return back()->with('error', 'El archivo no es un certificado (.cer) válido.');
        }
        $cert = $lector->leer($contenido);

        Responsable::create([
            'tipo_responsable_id' => $tipo,
            'nombre' => $cert['nombre'],
            'apellido_paterno' => $cert['apellido_paterno'],
            'apellido_materno' => $cert['apellido_materno'] ?: null,
            'curp' => $cert['curp'],
            'cargo_id' => $datos['cargo_id'],
            'titulo_profesional_id' => $datos['titulo_profesional_id'],
            'cer_titular' => $cert['titular'],
            'cer_serial' => $cert['serial'],
            'cer_vigencia_inicio' => $cert['vigencia_inicio'],
            'cer_vigencia_fin' => $cert['vigencia_fin'],
        ]);

        return back()->with('exito', 'Responsable guardado.');
    }

    public function destroy(Request $request, Responsable $responsable): RedirectResponse
    {
        abort_unless($responsable->tipo_responsable_id === (int) $request->route('tipo'), 404);

        $responsable->delete();

        return back()->with('exito', 'Responsable eliminado.');
    }

    private function tituloSeccion(string $seccion): string
    {
        return $seccion === 'titulacion' ? 'Titulación electrónica' : 'Certificación electrónica';
    }
}
