<?php

declare(strict_types=1);

namespace App\Http\Controllers\Emision;

use App\Http\Controllers\Controller;
use App\Models\Emision\Cargo;
use App\Models\Emision\CertificadoResponsable;
use App\Models\Emision\Responsable;
use App\Models\Emision\ResponsableMovimiento;
use App\Models\Emision\TipoResponsable;
use App\Models\Emision\TituloProfesional;
use App\Services\LectorCertificado;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Responsables (personas) que firman certificación (tipo 1) y titulación
 * (tipo 2). Una persona por CURP; sus certificados son un historial (al renovar
 * se agrega uno nuevo a la misma persona). Certificación admite 1 responsable
 * ACTIVO; Titulación hasta 2.
 */
class ResponsableController extends Controller
{
    private const MAXIMO = [
        TipoResponsable::CERTIFICACION => 1,
        TipoResponsable::TITULACION => 2,
    ];

    public function index(Request $request): Response
    {
        $tipo = (int) $request->route('tipo');
        $seccion = (string) $request->route('seccion');

        $responsables = Responsable::deTipo($tipo)
            ->with(['cargo:id,nombre,identificador', 'tituloProfesional:id,abreviatura,descripcion', 'certificados', 'movimientos'])
            // Orden de registro (id) = orden de firmante: el primero es el 1.
            ->orderBy('id')
            ->get();

        return Inertia::render('Emision/Responsables', [
            'seccion' => $seccion,
            'tituloSeccion' => $this->tituloSeccion($seccion),
            'maximo' => self::MAXIMO[$tipo] ?? 1,
            'activos' => $responsables->where('activo', true)->values()->map($this->aArreglo(...)),
            // El historial es TODA persona registrada (activa o no), con su
            // historial de certificados.
            'responsables' => $responsables->map($this->aArreglo(...)),
            'cargos' => Cargo::orderBy('nombre')->get(['id', 'nombre']),
            'titulos' => TituloProfesional::orderBy('abreviatura')->get(['id', 'abreviatura', 'descripcion']),
        ]);
    }

    /** @return array<string, mixed> */
    private function aArreglo(Responsable $r): array
    {
        $vigente = $r->certificados->firstWhere('vigente', true);

        return [
            'id' => $r->id,
            'nombre_completo' => $r->nombreCompleto(),
            'curp' => $r->curp,
            'cargo' => $r->cargo?->nombre,
            'cargo_id' => $r->cargo_id,
            'cargo_identificador' => $r->cargo?->identificador !== null ? (int) $r->cargo->identificador : null,
            'titulo' => $r->tituloProfesional?->abreviatura,
            'titulo_profesional_id' => $r->titulo_profesional_id,
            'activo' => $r->activo,
            'cer_serial' => $vigente?->serie,
            'vigencia_inicio' => $vigente?->vigencia_inicio?->format('d/m/Y'),
            'vigencia_fin' => $vigente?->vigencia_fin?->format('d/m/Y'),
            // Estado de vigencia del certificado activo: para avisar en la ficha
            // si está por vencer o ya venció (hay que renovarlo antes de firmar).
            'vigente_hoy' => $vigente ? $vigente->estaVigente() : null,
            'dias_restantes' => $vigente?->diasRestantes(),
            'tiene_cer_guardado' => filled($vigente?->cer_pem),
            'tiene_key' => filled($vigente?->key_encriptado),
            // Historial de certificados de la persona.
            'certificados' => $r->certificados->map(fn (CertificadoResponsable $c) => [
                'id' => $c->id,
                'serie' => $c->serie,
                'vigencia_inicio' => $c->vigencia_inicio?->format('d/m/Y'),
                'vigencia_fin' => $c->vigencia_fin?->format('d/m/Y'),
                /*
                 * Dos cosas distintas que se llamaban igual.
                 *
                 * `actual` es cuál de sus certificados se usa hoy para firmar;
                 * `vigente_hoy` es si su fecha de fin todavía no pasó. El
                 * historial mostraba el primero con la etiqueta del segundo, así
                 * que un certificado vencido en 2025 seguía diciendo «Vigente»
                 * mientras fuera el activo —justo el aviso que hace falta antes
                 * de intentar firmar con él—.
                 */
                'actual' => $c->vigente,
                'vigente_hoy' => $c->estaVigente(),
                'registrado' => $c->created_at?->format('d/m/Y'),
                'tiene_cer_guardado' => filled($c->cer_pem),
                'tiene_key' => filled($c->key_encriptado),
            ])->values(),
            // Bitácora de movimientos de la persona (alta, reactivación, etc.).
            'movimientos' => $r->movimientos->map(fn (ResponsableMovimiento $m) => [
                'id' => $m->id,
                'accion' => $m->accion,
                'detalle' => $m->detalle,
                'por' => $m->realizado_por_nombre,
                'fecha' => $m->created_at?->format('d/m/Y H:i'),
            ])->values(),
        ];
    }

    /** Registra un renglón en la bitácora del responsable con el autor actual. */
    private function registrarMovimiento(Responsable $responsable, string $accion, ?string $detalle = null): void
    {
        $usuario = Auth::user();

        ResponsableMovimiento::create([
            'responsable_id' => $responsable->id,
            'accion' => $accion,
            'detalle' => $detalle,
            'realizado_por' => $usuario?->getKey(),
            'realizado_por_nombre' => $usuario?->persona?->nombreCompleto() ?: $usuario?->usuario,
        ]);
    }

    /** Lee un .cer subido y devuelve sus datos (sin guardar) para previsualizar. */
    public function leerCertificado(Request $request, LectorCertificado $lector): JsonResponse
    {
        $request->validate(['certificado' => ['required', 'file', 'max:64']]);

        $contenido = (string) file_get_contents($request->file('certificado')->getRealPath());
        if (! $lector->esValido($contenido)) {
            return response()->json(['error' => 'El archivo no es un certificado (.cer) válido.'], 422);
        }

        return response()->json($lector->leer($contenido));
    }

    /** Alta: crea la persona (o reactiva la que ya existe por CURP) y su cert. */
    public function store(Request $request, LectorCertificado $lector): RedirectResponse
    {
        $tipo = (int) $request->route('tipo');

        $datos = $request->validate([
            'certificado' => ['required', 'file', 'max:64'],
            'cargo_id' => ['required', 'integer', Rule::exists('cargos', 'id')],
            'titulo_profesional_id' => ['required', 'integer', Rule::exists('titulos_profesionales', 'id')],
            'guardar_cer' => ['boolean'],
        ]);

        $contenido = (string) file_get_contents($request->file('certificado')->getRealPath());
        if (! $lector->esValido($contenido)) {
            return back()->with('error', 'El archivo no es un certificado (.cer) válido.');
        }
        $cert = $lector->leer($contenido);

        foreach (['curp' => 'la CURP', 'nombre' => 'el nombre', 'apellido_paterno' => 'el apellido paterno'] as $clave => $etiqueta) {
            if (blank($cert[$clave] ?? null)) {
                return back()->with('error', "El certificado no contiene {$etiqueta}. Verifica que sea el .cer correcto.");
            }
        }

        $existente = Responsable::deTipo($tipo)->where('curp', $cert['curp'])->first();

        if ($existente && $existente->activo) {
            return back()->with('error', 'Esa persona ya es responsable activo. Edítala para renovar su certificado.');
        }

        // Una serie no puede estar registrada en OTRA PERSONA (CURP distinta). La
        // misma persona sí puede reusar su .cer, incluso entre certificación y
        // titulación: los responsables de cada trámite son independientes.
        $serieAjena = CertificadoResponsable::query()->where('serie', $cert['serial'])
            ->whereHas('responsable', fn ($q) => $q->withTrashed()->where('curp', '!=', $cert['curp']))
            ->exists();
        if ($serieAjena) {
            return back()->with('error', "Ese certificado (serie {$cert['serial']}) ya está registrado con otra persona.");
        }

        // El límite aplica a los ACTIVOS. Reactivar o crear suma uno activo.
        $maximo = self::MAXIMO[$tipo] ?? 1;
        if (Responsable::deTipo($tipo)->activos()->count() >= $maximo) {
            return back()->with('error', "Ya hay {$maximo} responsable(s) activo(s). Desactiva uno para agregar otro.");
        }

        $reactivacion = $existente !== null;

        DB::transaction(function () use ($tipo, $existente, $cert, $datos, $lector, $contenido, $reactivacion) {
            $responsable = $existente ?? new Responsable(['tipo_responsable_id' => $tipo]);
            $responsable->fill([
                'nombre' => $cert['nombre'],
                'apellido_paterno' => $cert['apellido_paterno'],
                'apellido_materno' => $cert['apellido_materno'] ?: null,
                'curp' => $cert['curp'],
                'cargo_id' => $datos['cargo_id'],
                'titulo_profesional_id' => $datos['titulo_profesional_id'],
                'activo' => true,
            ])->save();

            $this->guardarCertificado($responsable, $cert, $contenido, (bool) ($datos['guardar_cer'] ?? false), $lector);

            $this->registrarMovimiento(
                $responsable,
                $reactivacion ? 'reactivacion' : 'alta',
                "Certificado serie {$cert['serial']}",
            );
        });

        return back()->with('exito', $reactivacion ? 'Responsable reactivado.' : 'Responsable guardado.');
    }

    /** Edita: renueva cert (historial), carga la llave y ajusta cargo/título. */
    public function update(Request $request, Responsable $responsable, LectorCertificado $lector): RedirectResponse
    {
        abort_unless($responsable->tipo_responsable_id === (int) $request->route('tipo'), 404);

        $datos = $request->validate([
            'certificado' => ['nullable', 'file', 'max:64'],
            'guardar_cer' => ['boolean'],
            'llave' => ['nullable', 'file', 'max:64'],
            'llave_password' => ['nullable', 'string', 'required_with:llave'],
            'guardar_key' => ['boolean'],
            'cargo_id' => ['required', 'integer', Rule::exists('cargos', 'id')],
            'titulo_profesional_id' => ['required', 'integer', Rule::exists('titulos_profesionales', 'id')],
        ], [
            'llave_password.required_with' => 'La contraseña de la llave es obligatoria para cargarla.',
        ]);

        $responsable->load('certificadoVigente');
        $certActual = $responsable->certificadoVigente;

        // Valores previos para detectar cambios de cargo/título en la bitácora.
        $cargoPrevio = $responsable->cargo_id;
        $tituloPrevio = $responsable->titulo_profesional_id;

        // 1) Renovar certificado (opcional): nuevo cert al historial de la misma persona.
        if ($request->hasFile('certificado')) {
            $contenido = (string) file_get_contents($request->file('certificado')->getRealPath());
            if (! $lector->esValido($contenido)) {
                return back()->with('error', 'El certificado (.cer) no es válido.');
            }
            $cert = $lector->leer($contenido);

            if (mb_strtoupper((string) $cert['curp']) !== mb_strtoupper((string) $responsable->curp)) {
                return back()->with('error', 'El certificado es de otra persona (CURP distinta). Para cambiar de responsable, desactiva este y agrega uno nuevo.');
            }
            // Solo es conflicto si la serie está en OTRA PERSONA (CURP distinta);
            // la propia se reutiliza (incluso entre certificación y titulación).
            if (CertificadoResponsable::query()->where('serie', $cert['serial'])
                ->whereHas('responsable', fn ($q) => $q->withTrashed()->where('curp', '!=', $responsable->curp))
                ->exists()) {
                return back()->with('error', "Ese certificado (serie {$cert['serial']}) ya está registrado con otra persona.");
            }

            $certActual = $this->guardarCertificado($responsable, $cert, $contenido, (bool) ($datos['guardar_cer'] ?? false), $lector);
            $this->registrarMovimiento($responsable, 'renovacion_certificado', "Certificado serie {$cert['serial']}");
        }

        // 2) Cargar la llave (opcional) sobre el certificado vigente.
        if ($request->hasFile('llave')) {
            if ($certActual === null) {
                return back()->with('error', 'No hay un certificado vigente al cual asociar la llave.');
            }
            if (blank($certActual->cer_pem)) {
                return back()->with('error', 'Para cargar la llave, guarda también el certificado (marca «Guardar el certificado»).');
            }

            $llave = (string) file_get_contents($request->file('llave')->getRealPath());
            if (! $lector->llaveCorresponde($certActual->cer_pem, $llave, (string) $datos['llave_password'])) {
                return back()->with('error', 'La llave no corresponde al certificado o la contraseña es incorrecta.');
            }

            if ($datos['guardar_key'] ?? false) {
                $certActual->forceFill(['key_encriptado' => Crypt::encryptString($llave)])->save();
                $this->registrarMovimiento($responsable, 'carga_llave', "Certificado serie {$certActual->serie}");
            }
        }

        $responsable->update([
            'cargo_id' => $datos['cargo_id'],
            'titulo_profesional_id' => $datos['titulo_profesional_id'],
        ]);

        if ($cargoPrevio !== $responsable->cargo_id || $tituloPrevio !== $responsable->titulo_profesional_id) {
            $this->registrarMovimiento($responsable, 'actualizacion', 'Cambio de cargo o título profesional');
        }

        return back()->with('exito', 'Responsable actualizado.');
    }

    /**
     * Registra el certificado como vigente de la persona. Si ya tiene ESE mismo
     * cert (misma serie) NO lo duplica: lo reutiliza —así, si antes se subió sin
     * guardar, ahora se puede almacenar—. Marca vigente y guarda el .cer si se pidió.
     */
    private function guardarCertificado(Responsable $responsable, array $cert, string $contenido, bool $guardarCer, LectorCertificado $lector): CertificadoResponsable
    {
        $certificado = $responsable->certificados()->where('serie', $cert['serial'])->first()
            ?? $responsable->certificados()->make();

        // El vigente pasa a ser este; los demás dejan de serlo.
        $responsable->certificados()->where('vigente', true)
            ->when($certificado->exists, fn ($q) => $q->where('id', '!=', $certificado->id))
            ->update(['vigente' => false]);

        $certificado->fill([
            'serie' => $cert['serial'],
            'titular' => $cert['titular'],
            'vigencia_inicio' => $cert['vigencia_inicio'],
            'vigencia_fin' => $cert['vigencia_fin'],
            'vigente' => true,
        ]);

        // cer_pem no es fillable (asignación directa lo evita); se guarda solo si se pidió.
        if ($guardarCer) {
            $certificado->cer_pem = $lector->pem($contenido);
        }

        $certificado->save();

        return $certificado;
    }

    public function desactivar(Request $request, Responsable $responsable): RedirectResponse
    {
        abort_unless($responsable->tipo_responsable_id === (int) $request->route('tipo'), 404);

        $responsable->update(['activo' => false]);
        $this->registrarMovimiento($responsable, 'desactivacion');

        return back()->with('exito', 'Responsable desactivado. Queda en el historial.');
    }

    /** Elimina por completo a una persona del historial (y sus certificados). */
    public function destroy(Request $request, Responsable $responsable): RedirectResponse
    {
        abort_unless($responsable->tipo_responsable_id === (int) $request->route('tipo'), 404);

        if ($responsable->activo) {
            return back()->with('error', 'Desactiva al responsable antes de eliminarlo.');
        }

        $responsable->forceDelete();

        return back()->with('exito', 'Responsable eliminado del historial.');
    }

    private function tituloSeccion(string $seccion): string
    {
        return $seccion === 'titulacion' ? 'Titulación electrónica' : 'Certificación electrónica';
    }
}
