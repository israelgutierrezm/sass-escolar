<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Academico\NivelEstudio;
use App\Models\Facturacion\FacturacionConfig;
use App\Models\Facturacion\FacturacionEvento;
use App\Models\Finanzas\ConceptoPago;
use App\Services\Facturacion\FacturapiService;
use App\Support\CatalogosSat;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Configuración de facturación (Facturapi) de la escuela.
 *
 * NO habla con Facturapi directamente: para eso está `FacturapiService`. Aquí
 * solo se persiste la configuración, se enmascaran las llaves antes de mandarlas
 * al frontend y se asienta la bitácora de cambios.
 */
class FacturacionConfigController extends Controller
{
    public function facturacion(): Response
    {
        $config = FacturacionConfig::actual();

        return Inertia::render('Plataforma/Configuraciones/Facturacion', [
            'config' => $this->paraElFrente($config),
            'catalogos' => $this->catalogos(),
            'nivelesIedu' => $this->nivelesIedu(),
            'catalogoIedu' => CatalogosSat::nivelesEducativosIedu(),
            // El otro interruptor del complemento, que vive en el catálogo de
            // conceptos. Se enseña aquí porque es donde se revisa si la escuela
            // puede emitir recibos deducibles: con el mapeo puesto y ningún
            // concepto marcado, no sale ni uno, y de otro modo no hay pantalla
            // desde la que verlo.
            'conceptosDeducibles' => ConceptoPago::query()
                ->where('deducible_iedu', true)->orderBy('nombre')->pluck('nombre')->all(),
        ]);
    }

    public function guardar(Request $request): RedirectResponse
    {
        $config = FacturacionConfig::actual();

        $datos = $request->validate([
            'activo' => ['boolean'],
            'ambiente' => ['required', Rule::in([FacturacionConfig::AMBIENTE_PRUEBAS, FacturacionConfig::AMBIENTE_PRODUCCION])],
            // Las llaves son opcionales: si vienen vacías se conserva la guardada.
            'api_key_pruebas' => ['nullable', 'string', 'max:255'],
            'api_key_produccion' => ['nullable', 'string', 'max:255'],
            'api_key_usuario' => ['nullable', 'string', 'max:255'],
            'organizacion_id' => ['nullable', 'string', 'max:100'],
            'uso_cfdi_default' => ['nullable', 'string', 'max:4'],
            'serie_default' => ['nullable', 'string', 'max:25'],
            'folio_inicial' => ['nullable', 'integer', 'min:1'],
            'moneda_default' => ['nullable', 'string', 'max:3'],
            'forma_pago_default' => ['nullable', 'string', 'max:2'],
            'metodo_pago_default' => ['nullable', 'string', 'max:3'],
            'exportacion_default' => ['nullable', 'string', 'max:2'],
            'objeto_impuesto_default' => ['nullable', 'string', 'max:2'],
            'version_factura' => ['nullable', 'string', 'max:5'],
        ]);

        $antesActivo = $config->activo;
        $antesAmbiente = $config->ambiente;

        // Las llaves solo se pisan si el usuario capturó una nueva.
        $llaves = [];
        if (filled($datos['api_key_pruebas'] ?? null)) {
            $llaves['api_key_pruebas'] = $datos['api_key_pruebas'];
        }
        if (filled($datos['api_key_produccion'] ?? null)) {
            $llaves['api_key_produccion'] = $datos['api_key_produccion'];
        }
        if (filled($datos['api_key_usuario'] ?? null)) {
            $llaves['api_key_usuario'] = $datos['api_key_usuario'];
        }
        unset($datos['api_key_pruebas'], $datos['api_key_produccion'], $datos['api_key_usuario']);

        $config->fill($datos + $llaves)->save();

        // Bitácora: qué cambió, sin una sola llave completa.
        $this->registrar($request, FacturacionEvento::CONFIG_GUARDADA, $config->ambiente, 'ok', 'Se guardó la configuración de facturación.', [
            'llaves_actualizadas' => array_keys($llaves),
        ]);

        if ($antesAmbiente !== $config->ambiente) {
            $this->registrar($request, FacturacionEvento::AMBIENTE_CAMBIADO, $config->ambiente, 'ok',
                "Cambió el ambiente de {$antesAmbiente} a {$config->ambiente}.");
        }

        if ($antesActivo !== $config->activo) {
            $this->registrar(
                $request,
                $config->activo ? FacturacionEvento::MODULO_ACTIVADO : FacturacionEvento::MODULO_DESACTIVADO,
                $config->ambiente,
                'ok',
                $config->activo ? 'Se activó la facturación.' : 'Se desactivó la facturación.',
            );
        }

        return back()->with('exito', 'Configuración guardada.');
    }

    /**
     * Guarda a qué nivel del complemento educativo corresponde cada nivel de la
     * escuela.
     *
     * Es lo que decide si un padre puede deducir la colegiatura de su hijo. Sin
     * el mapeo, la factura sale sin complemento: válida ante el SAT, timbrada
     * sin un solo error, y no deducible.
     *
     * Se guarda como un conjunto y no uno por uno: es una tabla de decisiones
     * que se revisa entera —«¿cuáles de mis niveles son deducibles?»— y
     * guardarla por renglón dejaría media configuración aplicada.
     */
    public function guardarNivelesIedu(Request $request): RedirectResponse
    {
        $permitidos = collect(CatalogosSat::nivelesEducativosIedu())->pluck('clave')->all();

        $datos = $request->validate([
            'niveles' => ['present', 'array'],
            'niveles.*.id' => ['required', 'integer', Rule::exists('niveles_estudio', 'id')],
            // Null es una respuesta legítima y la más común: «este nivel no es
            // deducible». Lo que no se admite es un valor fuera del catálogo
            // del SAT, que produciría un XML rechazado al timbrar.
            'niveles.*.nivel_iedu' => ['nullable', Rule::in($permitidos)],
        ]);

        DB::transaction(function () use ($datos) {
            foreach ($datos['niveles'] as $fila) {
                NivelEstudio::query()->whereKey($fila['id'])->update([
                    'nivel_iedu' => blank($fila['nivel_iedu']) ? null : $fila['nivel_iedu'],
                ]);
            }
        });

        $this->registrar($request, FacturacionEvento::CONFIG_GUARDADA, FacturacionConfig::actual()->ambiente, 'ok',
            'Se guardó el mapeo de niveles al complemento educativo.');

        return back()->with('exito', 'Mapeo del complemento educativo guardado.');
    }

    public function probar(Request $request, FacturapiService $facturapi): RedirectResponse
    {
        $resultado = $facturapi->probarConexion();

        $config = FacturacionConfig::actual();
        $config->forceFill([
            'conexion_estado' => $resultado['ok'] ? 'ok' : 'error',
            'conexion_mensaje' => $resultado['mensaje'],
            'conexion_probada_en' => now(),
            'validado_en' => $resultado['ok'] ? now() : $config->validado_en,
        ])->save();

        $this->registrar($request, FacturacionEvento::CONEXION_PROBADA, $config->ambiente,
            $resultado['ok'] ? 'ok' : 'error', $resultado['mensaje']);

        return back()->with($resultado['ok'] ? 'exito' : 'error', $resultado['mensaje']);
    }

    /**
     * Los niveles de la escuela con su mapeo al complemento educativo.
     *
     * Se listan TODOS los encendidos, incluidos los que no llevan complemento:
     * la pregunta que esta tabla contesta es «¿cuáles de mis niveles son
     * deducibles?», y enseñar sólo los mapeados escondería justo el que alguien
     * olvidó configurar.
     *
     * @return array<int, array<string, mixed>>
     */
    private function nivelesIedu(): array
    {
        return NivelEstudio::query()
            ->activos()
            ->orderBy('orden')
            ->get(['id', 'nombre', 'nivel_iedu'])
            ->map(fn (NivelEstudio $n) => [
                'id' => $n->id,
                'nombre' => $n->nombre,
                'nivel_iedu' => $n->nivel_iedu,
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function paraElFrente(FacturacionConfig $config): array
    {
        // Las llaves NUNCA salen completas: solo si existen y sus últimos 4.
        return [
            'activo' => $config->activo,
            'ambiente' => $config->ambiente,
            'tiene_key_pruebas' => filled($config->api_key_pruebas),
            'tiene_key_produccion' => filled($config->api_key_produccion),
            'tiene_key_usuario' => filled($config->api_key_usuario),
            'hint_key_pruebas' => FacturacionConfig::enmascarar($config->api_key_pruebas),
            'hint_key_produccion' => FacturacionConfig::enmascarar($config->api_key_produccion),
            'hint_key_usuario' => FacturacionConfig::enmascarar($config->api_key_usuario),
            'organizacion_id' => $config->organizacion_id,
            'conexion_estado' => $config->conexion_estado,
            'conexion_mensaje' => $config->conexion_mensaje,
            'conexion_probada_en' => $config->conexion_probada_en?->toDateTimeString(),
            'validado_en' => $config->validado_en?->toDateTimeString(),
            'uso_cfdi_default' => $config->uso_cfdi_default,
            'serie_default' => $config->serie_default,
            'folio_inicial' => $config->folio_inicial,
            'moneda_default' => $config->moneda_default,
            'forma_pago_default' => $config->forma_pago_default,
            'metodo_pago_default' => $config->metodo_pago_default,
            'exportacion_default' => $config->exportacion_default,
            'objeto_impuesto_default' => $config->objeto_impuesto_default,
            'version_factura' => $config->version_factura,
        ];
    }

    /**
     * @param  array<string, mixed>  $detalle
     */
    private function registrar(Request $request, string $tipo, ?string $ambiente, string $resultado, string $mensaje, array $detalle = []): void
    {
        FacturacionEvento::create([
            'usuario_id' => $request->user()?->id,
            'tipo' => $tipo,
            'ambiente' => $ambiente,
            'resultado' => $resultado,
            'mensaje' => $mensaje,
            'detalle' => $detalle === [] ? null : $detalle,
            'creado_en' => now(),
        ]);
    }

    /**
     * Catálogos SAT abreviados para los desplegables. Subconjuntos de uso común;
     * el catálogo completo del SAT se puede ampliar aquí sin tocar el resto.
     *
     * @return array<string, mixed>
     */
    private function catalogos(): array
    {
        return CatalogosSat::todos();
    }
}
