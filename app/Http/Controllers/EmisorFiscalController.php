<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Academico\Carrera;
use App\Models\Facturacion\FacturacionConfig;
use App\Models\Finanzas\EmisorAsignacion;
use App\Models\Finanzas\EmisorFiscal;
use App\Models\Finanzas\Factura;
use App\Models\Landlord\NivelEstudio;
use App\Services\Facturacion\FacturapiRechazo;
use App\Services\Facturacion\SincronizadorEmisorFacturapi;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Las razones sociales con las que factura la escuela.
 *
 * Una escuela puede tener varias personas morales —bachillerato con una,
 * licenciatura con otra— y cada una timbra con su propio certificado. Esta
 * pantalla es la que evita que todos los CFDI salgan a nombre de la misma.
 *
 * Nada de lo que se captura aquí toca las facturas ya emitidas: sus datos de
 * emisor están copiados en el comprobante y ahí se quedan.
 */
class EmisorFiscalController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Finanzas/Emisores/Index', [
            'emisores' => EmisorFiscal::query()
                ->with('asignaciones')
                ->withCount('facturas')
                ->orderBy('razon_social')
                ->get()
                ->map(fn (EmisorFiscal $e) => [
                    'id' => $e->id,
                    'rfc' => $e->rfc,
                    'razon_social' => $e->razon_social,
                    'nombre_comercial' => $e->nombre_comercial,
                    'regimen_fiscal' => $e->regimen_fiscal,
                    'cp' => $e->cp,
                    'correo_fiscal' => $e->correo_fiscal,
                    'telefono' => $e->telefono,
                    'calle' => $e->calle,
                    'num_exterior' => $e->num_exterior,
                    'num_interior' => $e->num_interior,
                    'colonia' => $e->colonia,
                    'municipio' => $e->municipio,
                    'estado' => $e->estado,
                    'pais' => $e->pais,
                    'facturapi_id' => $e->facturapi_id,
                    'uso_cfdi_default' => $e->uso_cfdi_default,
                    'serie_default' => $e->serie_default,
                    'folio_inicial' => $e->folio_inicial,
                    'moneda_default' => $e->moneda_default,
                    'forma_pago_default' => $e->forma_pago_default,
                    'metodo_pago_default' => $e->metodo_pago_default,
                    'exportacion_default' => $e->exportacion_default,
                    'objeto_impuesto_default' => $e->objeto_impuesto_default,
                    'activo' => $e->activo,
                    'puede_timbrar' => $e->puedeTimbrar(),
                    'tiene_certificado' => $e->certificado_ruta !== null,
                    'tiene_llave' => $e->llave_ruta !== null,
                    'facturas_count' => $e->facturas_count,
                    'asignaciones' => $e->asignaciones->map(fn (EmisorAsignacion $a) => [
                        'id' => $a->id,
                        'tipo' => $a->aplica_a_tipo,
                        'destinatario' => $a->nombreDelDestinatario(),
                    ])->values(),
                ]),
            'destinos' => $this->destinos(),
            'catalogos' => \App\Support\CatalogosSat::todos(),
            // Se avisa cuando una carrera quedó sin razón social: es
            // exactamente el hueco que hace fallar la primera facturación del
            // mes, y descubrirlo aquí es mucho más barato.
            'carrerasSinAsignar' => $this->carrerasSinAsignar(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $request->validate($this->reglas());

        EmisorFiscal::create($datos);

        return back()->with('exito', 'Razón social dada de alta. Asígnale qué factura y sube su certificado.');
    }

    public function update(Request $request, EmisorFiscal $emisor): RedirectResponse
    {
        $datos = $request->validate($this->reglas($emisor));

        $emisor->update($datos);

        // Cambiar los datos aquí NO reescribe los comprobantes ya emitidos:
        // llevan copia de lo que decía la razón social al timbrarse.
        return $emisor->facturas()->exists()
            ? back()->with('advertencia', 'Razón social actualizada. Las facturas ya emitidas conservan los datos con los que se timbraron.')
            : back()->with('exito', 'Razón social actualizada.');
    }

    /**
     * Certificado (.cer) y llave (.key) del sello digital, más la contraseña.
     *
     * Van al disco PRIVADO y la contraseña se cifra con la APP_KEY. Nunca a
     * `public/`: con el certificado y la llave se puede timbrar a nombre de la
     * escuela, que es lo más delicado que guarda este sistema.
     */
    public function credenciales(Request $request, EmisorFiscal $emisor): RedirectResponse
    {
        $request->validate([
            'certificado' => ['nullable', 'file', 'max:64'],
            'llave' => ['nullable', 'file', 'max:64'],
            'llave_password' => ['nullable', 'string', 'max:255'],
            'pac_usuario' => ['nullable', 'string', 'max:255'],
            'pac_password' => ['nullable', 'string', 'max:255'],
        ]);

        $cambios = [];

        if ($request->hasFile('certificado')) {
            $this->borrarSiExiste($emisor->certificado_ruta);
            $cambios['certificado_ruta'] = $request->file('certificado')
                ->storeAs('cfdi/certificados', "{$emisor->id}.cer", 'local');
        }

        if ($request->hasFile('llave')) {
            $this->borrarSiExiste($emisor->llave_ruta);
            $cambios['llave_ruta'] = $request->file('llave')
                ->storeAs('cfdi/certificados', "{$emisor->id}.key", 'local');
        }

        // Un campo de contraseña en blanco significa "no lo cambies", no
        // "bórralo": el formulario nunca muestra el valor guardado, así que
        // enviarlo vacío es lo normal cuando solo se sube un archivo.
        foreach (['llave_password', 'pac_usuario', 'pac_password'] as $secreto) {
            if (filled($request->input($secreto))) {
                $cambios[$secreto] = $request->input($secreto);
            }
        }

        $emisor->update($cambios);
        $emisor->refresh();

        // Con el CSD ya guardado, se intenta crear/actualizar la organización en
        // Facturapi automáticamente: es la razón de ser de este flujo —que el
        // usuario NO tenga que entrar a Facturapi ni copiar el organization_id—.
        $aviso = $this->sincronizarConFacturapi($request, $emisor);

        return back()->with($aviso['tipo'], $aviso['mensaje']);
    }

    /**
     * Crea/actualiza la organización de la razón social en Facturapi con el CSD
     * recién guardado. Solo corre si el módulo está activo y hay Secret Admin
     * Key; si falta el CSD completo, avisa qué falta sin romper nada.
     *
     * @return array{tipo: string, mensaje: string}
     */
    private function sincronizarConFacturapi(Request $request, EmisorFiscal $emisor): array
    {
        $base = 'Credenciales de timbrado actualizadas.';
        $config = FacturacionConfig::actual();

        // Sin módulo activo o sin Admin Key, se guarda local y no se toca la API.
        if (! $config->activo || blank($config->apiKeyUsuario())) {
            return ['tipo' => 'exito', 'mensaje' => $base];
        }

        $cer = $this->contenidoCredencial($request, 'certificado', $emisor->certificado_ruta);
        $key = $this->contenidoCredencial($request, 'llave', $emisor->llave_ruta);
        $password = filled($request->input('llave_password')) ? $request->input('llave_password') : $emisor->llave_password;

        if ($cer === null || $key === null || blank($password)) {
            return ['tipo' => 'advertencia', 'mensaje' => $base.' Falta el .cer, el .key o la contraseña para crear la organización en Facturapi.'];
        }

        try {
            SincronizadorEmisorFacturapi::paraLaEscuela()->sincronizar($emisor, $cer, $key, (string) $password);
        } catch (FacturapiRechazo $e) {
            // Rechazo de negocio (RFC del CSD, contraseña, régimen): se muestra.
            return ['tipo' => 'error', 'mensaje' => 'Se guardó el CSD, pero Facturapi lo rechazó: '.$e->getMessage()];
        } catch (\Throwable $e) {
            // Falla de comunicación: el CSD quedó guardado, se puede reintentar.
            return ['tipo' => 'advertencia', 'mensaje' => 'Se guardó el CSD, pero no se pudo contactar a Facturapi ahora. Reintenta más tarde.'];
        }

        return ['tipo' => 'exito', 'mensaje' => 'Razón social sincronizada con Facturapi: organización creada y CSD cargado. Ya puedes timbrar en pruebas.'];
    }

    /** Contenido crudo de una credencial: del archivo recién subido o del disco. */
    private function contenidoCredencial(Request $request, string $campo, ?string $ruta): ?string
    {
        if ($request->hasFile($campo)) {
            return $request->file($campo)->get();
        }

        if ($ruta !== null && Storage::disk('local')->exists($ruta)) {
            return Storage::disk('local')->get($ruta);
        }

        return null;
    }

    public function asignar(Request $request, EmisorFiscal $emisor): RedirectResponse
    {
        $datos = $request->validate([
            'aplica_a_tipo' => ['required', Rule::in([
                EmisorAsignacion::APLICA_GLOBAL,
                EmisorAsignacion::APLICA_NIVEL,
                EmisorAsignacion::APLICA_CARRERA,
            ])],
            'aplica_a_id' => ['nullable', 'integer'],
        ]);

        if ($datos['aplica_a_tipo'] === EmisorAsignacion::APLICA_GLOBAL) {
            $datos['aplica_a_id'] = null;
        } elseif (($datos['aplica_a_id'] ?? null) === null) {
            return back()->with('error', 'Elige a qué nivel o carrera aplica.');
        }

        // La misma asignación dos veces no significa nada, y dos razones
        // sociales para la misma carrera es una ambigüedad que después nadie
        // sabe cómo se resolvió. Se avisa en vez de dejarlo pasar.
        $ocupada = EmisorAsignacion::query()
            ->where('aplica_a_tipo', $datos['aplica_a_tipo'])
            ->where('aplica_a_id', $datos['aplica_a_id'])
            ->with('emisor')
            ->first();

        if ($ocupada !== null) {
            return back()->with(
                'error',
                $ocupada->emisor_id === $emisor->id
                    ? 'Esa asignación ya la tiene esta razón social.'
                    : 'Eso ya lo factura '.$ocupada->emisor?->razon_social.'. Quítaselo primero.'
            );
        }

        $emisor->asignaciones()->create($datos);

        return back()->with('exito', 'Asignación agregada.');
    }

    public function desasignar(EmisorFiscal $emisor, EmisorAsignacion $asignacion): RedirectResponse
    {
        abort_unless($asignacion->emisor_id === $emisor->id, 404);

        $asignacion->delete();

        return back()->with('exito', 'Asignación retirada.');
    }

    /**
     * Una razón social que ya facturó NO se borra: sus comprobantes son el
     * respaldo de lo que se declaró y la referencia sigue teniendo sentido. Se
     * desactiva, que es como se retira una persona moral que dejó de operar.
     */
    public function destroy(EmisorFiscal $emisor): RedirectResponse
    {
        if ($emisor->facturas()->exists()) {
            return back()->with(
                'error',
                'Esta razón social ya emitió facturas y no se elimina. Desactívala para que deje de usarse.'
            );
        }

        $this->borrarSiExiste($emisor->certificado_ruta);
        $this->borrarSiExiste($emisor->llave_ruta);

        $emisor->delete();

        return back()->with('exito', 'Razón social eliminada.');
    }

    /**
     * @return array<string, mixed>
     */
    private function reglas(?EmisorFiscal $emisor = null): array
    {
        return [
            'rfc' => [
                'required', 'string', 'min:12', 'max:13',
                Rule::unique('emisores_fiscales', 'rfc')->ignore($emisor?->id)->whereNull('deleted_at'),
            ],
            'razon_social' => ['required', 'string', 'max:255'],
            'nombre_comercial' => ['nullable', 'string', 'max:255'],
            'regimen_fiscal' => ['required', 'string', 'max:5'],
            'cp' => ['required', 'string', 'size:5'],
            'correo_fiscal' => ['nullable', 'email', 'max:190'],
            'telefono' => ['nullable', 'string', 'max:20'],
            // Domicilio fiscal: opcional (solo cuando el régimen lo exige).
            'calle' => ['nullable', 'string', 'max:190'],
            'num_exterior' => ['nullable', 'string', 'max:30'],
            'num_interior' => ['nullable', 'string', 'max:30'],
            'colonia' => ['nullable', 'string', 'max:190'],
            'municipio' => ['nullable', 'string', 'max:190'],
            'estado' => ['nullable', 'string', 'max:120'],
            'pais' => ['nullable', 'string', 'max:3'],
            'facturapi_id' => ['nullable', 'string', 'max:100'],
            // Predeterminados de CFDI.
            'uso_cfdi_default' => ['nullable', 'string', 'max:4'],
            'serie_default' => ['nullable', 'string', 'max:25'],
            'folio_inicial' => ['nullable', 'integer', 'min:1'],
            'moneda_default' => ['nullable', 'string', 'max:3'],
            'forma_pago_default' => ['nullable', 'string', 'max:2'],
            'metodo_pago_default' => ['nullable', 'string', 'max:3'],
            'exportacion_default' => ['nullable', 'string', 'max:2'],
            'objeto_impuesto_default' => ['nullable', 'string', 'max:2'],
            'activo' => ['boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function destinos(): array
    {
        return [
            'nivel' => NivelEstudio::query()->orderBy('orden')->get(['id', 'nombre']),
            'carrera' => Carrera::query()->orderBy('nombre')->get(['id', 'nombre']),
        ];
    }

    /**
     * Carreras que hoy no resolverían a ninguna razón social. Si existe una
     * asignación global, ninguna queda huérfana.
     *
     * @return array<int, string>
     */
    private function carrerasSinAsignar(): array
    {
        $hayGlobal = EmisorAsignacion::query()
            ->where('aplica_a_tipo', EmisorAsignacion::APLICA_GLOBAL)
            ->whereHas('emisor', fn ($q) => $q->activos())
            ->exists();

        if ($hayGlobal || EmisorFiscal::query()->activos()->doesntExist()) {
            return [];
        }

        $porCarrera = EmisorAsignacion::query()
            ->where('aplica_a_tipo', EmisorAsignacion::APLICA_CARRERA)
            ->whereHas('emisor', fn ($q) => $q->activos())
            ->pluck('aplica_a_id');

        $porNivel = EmisorAsignacion::query()
            ->where('aplica_a_tipo', EmisorAsignacion::APLICA_NIVEL)
            ->whereHas('emisor', fn ($q) => $q->activos())
            ->pluck('aplica_a_id');

        return Carrera::query()
            ->whereNotIn('id', $porCarrera)
            ->where(fn ($q) => $q->whereNull('nivel_estudios_id')->orWhereNotIn('nivel_estudios_id', $porNivel))
            ->orderBy('nombre')
            ->pluck('nombre')
            ->all();
    }

    private function borrarSiExiste(?string $ruta): void
    {
        if ($ruta !== null && Storage::disk('local')->exists($ruta)) {
            Storage::disk('local')->delete($ruta);
        }
    }
}
