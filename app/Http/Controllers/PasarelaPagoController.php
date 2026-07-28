<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Finanzas\PasarelaPago;
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
        ]);

        $pasarela = PasarelaPago::para($clave);
        $pasarela->ambiente = $datos['ambiente'];

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
        ];
    }
}
