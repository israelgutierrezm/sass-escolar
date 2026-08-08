<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\AvisoParaElUsuario;
use App\Models\Finanzas\PasarelaPago;
use App\Services\Pagos\Pasarelas;
use App\Support\PasarelasCatalogo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Configuración de pasarelas de pago (Stripe, Mercado Pago, PayPal, OpenPay).
 *
 * La escuela mete SUS credenciales y enciende las que quiera con un switch. Regla
 * dura: una pasarela no se puede activar si su ambiente activo no trae completos
 * los campos requeridos —no habría con qué cobrarle al alumno—. Las credenciales
 * se guardan cifradas y NUNCA se mandan completas al frontend (solo si están
 * puestas, enmascaradas).
 */
class PasarelaPagoController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Plataforma/Configuraciones/Pasarelas', [
            'pasarelas' => collect(PasarelasCatalogo::todas())
                ->map(fn (array $def, string $clave) => $this->paraElFrente($clave, $def))
                ->values(),
        ]);
    }

    public function guardar(Request $request, string $clave): RedirectResponse
    {
        abort_unless(PasarelasCatalogo::existe($clave), 404);

        $datos = $request->validate([
            'ambiente' => ['required', Rule::in([PasarelaPago::AMBIENTE_PRUEBAS, PasarelaPago::AMBIENTE_PRODUCCION])],
            'activa' => ['boolean'],
            'credenciales' => ['array'],
            'credenciales.*' => ['nullable', 'string', 'max:500'],
            // Qué ofrece: formas de pago encendidas y plazos de MSI.
            'opciones' => ['array'],
        ]);

        $pasarela = PasarelaPago::para($clave);
        $pasarela->ambiente = $datos['ambiente'];
        $pasarela->opciones = $this->opcionesValidas($clave, $datos['opciones'] ?? []);

        /*
         * Apagar TODAS las formas de pago deja una pasarela que abre el cobro y
         * no ofrece con qué pagarlo. No falla al guardar —falla después, con el
         * alumno delante de una pantalla vacía—, así que se ataja aquí.
         */
        AvisoParaElUsuario::si(
            PasarelasCatalogo::metodosDe($clave) !== [] && $pasarela->metodosAceptados() === [],
            422,
            'Deja encendida al menos una forma de pago: sin ninguna, quien vaya a pagar no tendría con qué.',
        );

        // Solo se pisan los campos que llegaron con valor: un campo en blanco
        // significa "conserva el guardado", no "bórralo" (el frontend nunca
        // muestra el valor actual, así que enviarlo vacío es lo normal).
        $columna = $datos['ambiente'] === PasarelaPago::AMBIENTE_PRODUCCION
            ? 'credenciales_produccion'
            : 'credenciales_pruebas';

        $credenciales = $pasarela->{$columna} ?? [];
        foreach ($this->camposValidos($clave, $datos['credenciales'] ?? []) as $campo => $valor) {
            if (filled($valor)) {
                $credenciales[$campo] = $valor;
            }
        }
        $pasarela->{$columna} = $credenciales;

        // La activación es condicional: si piden encenderla pero le faltan datos
        // del ambiente activo, se guardan las credenciales pero NO se activa.
        $quiereActivar = (bool) ($datos['activa'] ?? false);
        $pasarela->activa = $quiereActivar && $pasarela->completaEn($datos['ambiente']);
        $pasarela->save();

        if ($quiereActivar && ! $pasarela->activa) {
            return back()->with('advertencia', 'Se guardaron las credenciales, pero faltan datos requeridos para activar '.PasarelasCatalogo::todas()[$clave]['nombre'].'.');
        }

        return back()->with('exito', 'Pasarela '.PasarelasCatalogo::todas()[$clave]['nombre'].' actualizada.');
    }

    /**
     * Las opciones, saneadas contra lo que la pasarela de verdad ofrece.
     *
     * Llegan del navegador, así que aquí se decide qué es válido: un
     * interruptor es un sí o un no, y los meses sólo pueden ser plazos que la
     * pasarela acepte. Guardar «24 meses» porque alguien lo escribió en la
     * petición produciría un cobro que la pasarela rechaza, y el error saldría
     * al pagar, no al configurar.
     *
     * @param  array<string, mixed>  $entrada
     * @return array<string, mixed>
     */
    private function opcionesValidas(string $clave, array $entrada): array
    {
        return collect(PasarelasCatalogo::opciones($clave))
            ->map(function (array $def, string $k) use ($entrada) {
                $valor = $entrada[$k] ?? $def['default'];

                if ($def['tipo'] === 'meses') {
                    $permitidos = $def['valores'] ?? [];

                    return collect(is_array($valor) ? $valor : [])
                        ->map(fn ($m) => (int) $m)
                        ->filter(fn (int $m) => in_array($m, $permitidos, true))
                        ->unique()
                        ->sort()
                        ->values()
                        ->all();
                }

                return filter_var($valor, FILTER_VALIDATE_BOOLEAN);
            })
            ->all();
    }

    /**
     * Descarta claves de credenciales que no pertenecen a la pasarela (nadie
     * mete un campo de Stripe en OpenPay a mano por el request).
     *
     * @param  array<string, mixed>  $entrada
     * @return array<string, mixed>
     */
    private function camposValidos(string $clave, array $entrada): array
    {
        $permitidos = array_keys(PasarelasCatalogo::todas()[$clave]['campos']);

        return array_intersect_key($entrada, array_flip($permitidos));
    }

    /**
     * Objeto seguro para el frontend: definición del catálogo + estado, con las
     * credenciales reducidas a "¿está puesto este campo?" por ambiente. Jamás el
     * valor.
     *
     * @param  array{nombre: string, descripcion: string, color: string, campos: array<string, array{etiqueta: string, requerido: bool, ayuda?: string}>}  $def
     * @return array<string, mixed>
     */
    private function paraElFrente(string $clave, array $def): array
    {
        $pasarela = PasarelaPago::para($clave);

        $puestos = fn (string $ambiente) => collect($def['campos'])
            ->map(fn ($_, string $campo) => filled($pasarela->credencialesDe($ambiente)[$campo] ?? null))
            ->all();

        return [
            'clave' => $clave,
            'nombre' => $def['nombre'],
            'descripcion' => $def['descripcion'],
            'color' => $def['color'],
            'campos' => collect($def['campos'])->map(fn (array $c, string $k) => [
                'clave' => $k,
                'etiqueta' => $c['etiqueta'],
                'requerido' => $c['requerido'],
                'ayuda' => $c['ayuda'] ?? null,
            ])->values(),
            'activa' => $pasarela->activa,
            'ambiente' => $pasarela->ambiente,
            'puestos_pruebas' => $puestos(PasarelaPago::AMBIENTE_PRUEBAS),
            'puestos_produccion' => $puestos(PasarelaPago::AMBIENTE_PRODUCCION),
            'completa_pruebas' => $pasarela->completaEn(PasarelaPago::AMBIENTE_PRUEBAS),
            'completa_produccion' => $pasarela->completaEn(PasarelaPago::AMBIENTE_PRODUCCION),
            // Qué ofrece esta pasarela y qué tiene encendido hoy.
            'opciones_disponibles' => collect($def['opciones'])->map(fn (array $o, string $k) => [
                'clave' => $k,
                'etiqueta' => $o['etiqueta'],
                'tipo' => $o['tipo'],
                'valores' => $o['valores'] ?? [],
                'ayuda' => $o['ayuda'] ?? null,
            ])->values(),
            'opciones' => $pasarela->opciones(),
            // Que se sepa si la escuela puede esperar cobros en línea de aquí.
            'cobra' => in_array($clave, Pasarelas::IMPLEMENTADAS, true),
        ];
    }
}
