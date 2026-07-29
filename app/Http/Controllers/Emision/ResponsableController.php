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
use Illuminate\Support\Facades\Crypt;
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

        $responsables = Responsable::deTipo($tipo)
            ->with(['cargo:id,nombre', 'tituloProfesional:id,abreviatura,descripcion'])
            ->orderByDesc('activo')
            ->orderByDesc('id')
            ->get();

        return Inertia::render('Emision/Responsables', [
            'seccion' => $seccion,
            'tituloSeccion' => $this->tituloSeccion($seccion),
            'maximo' => self::MAXIMO[$tipo] ?? 1,
            // El alta se ofrece mientras haya cupo entre los ACTIVOS.
            'activos' => $responsables->where('activo', true)->values()->map($this->aArreglo(...)),
            // Los desactivados quedan como historial (sus firmas siguen ligadas).
            'historial' => $responsables->where('activo', false)->values()->map($this->aArreglo(...)),
            'cargos' => Cargo::orderBy('nombre')->get(['id', 'nombre']),
            'titulos' => TituloProfesional::orderBy('abreviatura')->get(['id', 'abreviatura', 'descripcion']),
        ]);
    }

    /** @return array<string, mixed> */
    private function aArreglo(Responsable $r): array
    {
        return [
            'id' => $r->id,
            'nombre_completo' => $r->nombreCompleto(),
            'curp' => $r->curp,
            'cargo' => $r->cargo?->nombre,
            'cargo_id' => $r->cargo_id,
            'titulo' => $r->tituloProfesional?->abreviatura,
            'titulo_profesional_id' => $r->titulo_profesional_id,
            'activo' => $r->activo,
            'tiene_cer_guardado' => filled($r->cer_pem),
            'tiene_key' => filled($r->key_encriptado),
            'cer_titular' => $r->cer_titular,
            'cer_serial' => $r->cer_serial,
            'vigencia_inicio' => $r->cer_vigencia_inicio?->format('d/m/Y'),
            'vigencia_fin' => $r->cer_vigencia_fin?->format('d/m/Y'),
        ];
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
            'guardar_cer' => ['boolean'],
        ]);

        // El límite aplica a los ACTIVOS: certificación 1, titulación 2. Para
        // reemplazar (cert vencido o cambio de responsable) se desactiva uno.
        $maximo = self::MAXIMO[$tipo] ?? 1;
        if (Responsable::deTipo($tipo)->activos()->count() >= $maximo) {
            return back()->with('error', "Ya hay {$maximo} responsable(s) activo(s). Desactiva uno para agregar otro.");
        }

        // La identidad SIEMPRE se toma del certificado (server-side), no del
        // formulario: es el dato de confianza. Solo cargo y título los pone el usuario.
        $contenido = (string) file_get_contents($request->file('certificado')->getRealPath());
        if (! $lector->esValido($contenido)) {
            return back()->with('error', 'El archivo no es un certificado (.cer) válido.');
        }
        $cert = $lector->leer($contenido);

        // Todos los datos son obligatorios: si el certificado no trae la
        // identidad completa, no se guarda a medias.
        foreach (['curp' => 'la CURP', 'nombre' => 'el nombre', 'apellido_paterno' => 'el apellido paterno'] as $clave => $etiqueta) {
            if (blank($cert[$clave] ?? null)) {
                return back()->with('error', "El certificado no contiene {$etiqueta}. Verifica que sea el .cer correcto del responsable.");
            }
        }

        Responsable::create([
            'tipo_responsable_id' => $tipo,
            'nombre' => $cert['nombre'],
            'apellido_paterno' => $cert['apellido_paterno'],
            'apellido_materno' => $cert['apellido_materno'] ?: null,
            'curp' => $cert['curp'],
            'cargo_id' => $datos['cargo_id'],
            'titulo_profesional_id' => $datos['titulo_profesional_id'],
            'activo' => true,
            'cer_titular' => $cert['titular'],
            'cer_serial' => $cert['serial'],
            'cer_vigencia_inicio' => $cert['vigencia_inicio'],
            'cer_vigencia_fin' => $cert['vigencia_fin'],
            // «Guardar mi .cer»: se almacena el certificado (público) para no
            // volver a subirlo al firmar. El .key (privado) irá después, cifrado.
            'cer_pem' => ($datos['guardar_cer'] ?? false) ? $lector->pem($contenido) : null,
        ]);

        return back()->with('exito', 'Responsable guardado.');
    }

    /**
     * Edita un responsable ya cargado: puede renovar su certificado (mismo
     * responsable, cert vencido/nuevo), cargar su llave (.key) para firmar solo
     * con la contraseña después, y ajustar título y cargo.
     */
    public function update(Request $request, Responsable $responsable, LectorCertificado $lector): RedirectResponse
    {
        abort_unless($responsable->tipo_responsable_id === (int) $request->route('tipo'), 404);

        $datos = $request->validate([
            'certificado' => ['nullable', 'file', 'max:64'],
            'llave' => ['nullable', 'file', 'max:64'],
            'llave_password' => ['nullable', 'string', 'required_with:llave'],
            'cargo_id' => ['required', 'integer', Rule::exists('cargos', 'id')],
            'titulo_profesional_id' => ['required', 'integer', Rule::exists('titulos_profesionales', 'id')],
        ], [
            'llave_password.required_with' => 'La contraseña de la llave es obligatoria para cargarla.',
        ]);

        $cambios = [
            'cargo_id' => $datos['cargo_id'],
            'titulo_profesional_id' => $datos['titulo_profesional_id'],
        ];
        $keyEncriptado = null;

        // 1) Renovar certificado (opcional). La identidad se re-lee del nuevo .cer.
        if ($request->hasFile('certificado')) {
            $contenido = (string) file_get_contents($request->file('certificado')->getRealPath());
            if (! $lector->esValido($contenido)) {
                return back()->with('error', 'El certificado (.cer) no es válido.');
            }
            $cert = $lector->leer($contenido);
            foreach (['curp', 'nombre', 'apellido_paterno'] as $req) {
                if (blank($cert[$req] ?? null)) {
                    return back()->with('error', 'El certificado no contiene la identidad completa (CURP/nombre).');
                }
            }
            $cambios += [
                'nombre' => $cert['nombre'],
                'apellido_paterno' => $cert['apellido_paterno'],
                'apellido_materno' => $cert['apellido_materno'] ?: null,
                'curp' => $cert['curp'],
                'cer_titular' => $cert['titular'],
                'cer_serial' => $cert['serial'],
                'cer_vigencia_inicio' => $cert['vigencia_inicio'],
                'cer_vigencia_fin' => $cert['vigencia_fin'],
                'cer_pem' => $lector->pem($contenido),
            ];
        }

        // 2) Cargar la llave (opcional). Se valida contra el certificado vigente
        //    (el recién subido o el guardado) con la contraseña, y se guarda
        //    CIFRADA en reposo. La contraseña NO se almacena.
        if ($request->hasFile('llave')) {
            $certPem = $cambios['cer_pem'] ?? $responsable->cer_pem;
            if (blank($certPem)) {
                return back()->with('error', 'Para cargar la llave primero guarda el certificado (marca «Guardar mi .cer» o súbelo aquí).');
            }

            $llave = (string) file_get_contents($request->file('llave')->getRealPath());
            if (! $lector->llaveCorresponde($certPem, $llave, (string) $datos['llave_password'])) {
                return back()->with('error', 'La llave no corresponde al certificado o la contraseña es incorrecta.');
            }

            $keyEncriptado = Crypt::encryptString($llave);
        }

        $responsable->update($cambios);
        // key_encriptado NO es fillable (dato sensible): se asigna con forceFill.
        if ($keyEncriptado !== null) {
            $responsable->forceFill(['key_encriptado' => $keyEncriptado])->save();
        }

        return back()->with('exito', 'Responsable actualizado.');
    }

    /** Desactiva (retira) un responsable activo: se conserva como historial. */
    public function desactivar(Request $request, Responsable $responsable): RedirectResponse
    {
        abort_unless($responsable->tipo_responsable_id === (int) $request->route('tipo'), 404);

        $responsable->update(['activo' => false]);

        return back()->with('exito', 'Responsable desactivado. Queda en el historial.');
    }

    /**
     * Elimina un responsable del historial. Solo se permite sobre INACTIVOS: uno
     * activo se desactiva primero, y más adelante se bloqueará si ya firmó algo.
     */
    public function destroy(Request $request, Responsable $responsable): RedirectResponse
    {
        abort_unless($responsable->tipo_responsable_id === (int) $request->route('tipo'), 404);

        if ($responsable->activo) {
            return back()->with('error', 'Desactiva al responsable antes de eliminarlo.');
        }

        $responsable->delete();

        return back()->with('exito', 'Responsable eliminado del historial.');
    }

    private function tituloSeccion(string $seccion): string
    {
        return $seccion === 'titulacion' ? 'Titulación electrónica' : 'Certificación electrónica';
    }
}
