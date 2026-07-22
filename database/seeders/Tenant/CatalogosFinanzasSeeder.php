<?php

declare(strict_types=1);

namespace Database\Seeders\Tenant;

use App\Models\Finanzas\ConceptoPago;
use App\Models\Finanzas\MetodoPago;
use App\Models\Finanzas\SituacionPago;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;

/**
 * Catálogos TENANT-CONFIG del módulo de finanzas. Idempotente por clave.
 *
 * Se siembra el mínimo con el que una escuela puede empezar a cobrar; los
 * montos, los planes de cobro y las reglas NO se siembran porque son de cada
 * escuela y no hay un valor razonable por defecto.
 *
 * Las claves del SAT que van aquí son las de uso común en servicios educativos
 * y quedan como punto de partida: el contador de cada escuela las confirma
 * antes de timbrar (entrega 7.3). Se siembran de una vez porque rellenarlas
 * después, sobre conceptos que ya tienen adeudos históricos colgando, es un
 * trabajo manual que hoy cuesta nada.
 */
class CatalogosFinanzasSeeder extends Seeder
{
    public function run(): void
    {
        // ClaveProdServ 86121600 = servicios de instituciones educativas.
        // ClaveUnidad E48 = unidad de servicio.
        // La colegiatura de nivel básico suele ir exenta; las demás las define
        // el régimen de cada escuela, por eso `gravado` nace en false y se
        // ajusta desde el catálogo.
        $this->sembrar(ConceptoPago::class, [
            [
                'clave' => 'ficha',
                'nombre' => 'Ficha de admisión',
                'clave_sat' => '86121600',
                'clave_unidad_sat' => 'E48',
                'gravado' => false,
                'tasa_iva' => null,
            ],
            [
                'clave' => 'inscripcion',
                'nombre' => 'Inscripción',
                'clave_sat' => '86121600',
                'clave_unidad_sat' => 'E48',
                'gravado' => false,
                'tasa_iva' => null,
            ],
            [
                'clave' => 'reinscripcion',
                'nombre' => 'Reinscripción',
                'clave_sat' => '86121600',
                'clave_unidad_sat' => 'E48',
                'gravado' => false,
                'tasa_iva' => null,
            ],
            [
                'clave' => 'colegiatura',
                'nombre' => 'Colegiatura',
                'clave_sat' => '86121600',
                'clave_unidad_sat' => 'E48',
                'gravado' => false,
                'tasa_iva' => null,
            ],
            [
                'clave' => 'examen_extraordinario',
                'nombre' => 'Examen extraordinario',
                'clave_sat' => '86121600',
                'clave_unidad_sat' => 'E48',
                'gravado' => false,
                'tasa_iva' => null,
            ],
            [
                'clave' => 'recursamiento',
                'nombre' => 'Recursamiento',
                'clave_sat' => '86121600',
                'clave_unidad_sat' => 'E48',
                'gravado' => false,
                'tasa_iva' => null,
            ],
            [
                'clave' => 'constancia',
                'nombre' => 'Constancia',
                'clave_sat' => '86121600',
                'clave_unidad_sat' => 'E48',
                'gravado' => true,
                'tasa_iva' => 0.16,
            ],
            [
                'clave' => 'credencial',
                'nombre' => 'Credencial',
                'clave_sat' => '86121600',
                'clave_unidad_sat' => 'E48',
                'gravado' => true,
                'tasa_iva' => 0.16,
            ],
            [
                'clave' => 'titulacion',
                'nombre' => 'Titulación',
                'clave_sat' => '86121600',
                'clave_unidad_sat' => 'E48',
                'gravado' => true,
                'tasa_iva' => 0.16,
            ],
            [
                'clave' => 'recargo_mora',
                'nombre' => 'Recargo por pago extemporáneo',
                'clave_sat' => '84111506',
                'clave_unidad_sat' => 'E48',
                'gravado' => true,
                'tasa_iva' => 0.16,
            ],
        ]);

        // `bloquea` es la decisión de negocio de cada escuela: hay quien
        // bloquea al primer adeudo y quien no bloquea nunca. Se siembra el
        // criterio prudente —solo la situación que se llama "bloqueado"
        // bloquea— y la escuela lo ajusta.
        $this->sembrar(SituacionPago::class, [
            ['clave' => 'corriente', 'nombre' => 'Al corriente', 'bloquea' => false],
            ['clave' => 'moroso', 'nombre' => 'Moroso', 'bloquea' => false],
            ['clave' => 'bloqueado', 'nombre' => 'Bloqueado por adeudo', 'bloquea' => true],
            ['clave' => 'convenio', 'nombre' => 'Con convenio de pago', 'bloquea' => false],
            ['clave' => 'becado', 'nombre' => 'Becado', 'bloquea' => false],
        ]);

        // `requiere_confirmacion` separa cobrar de prometer: lo que pasa por
        // banco o pasarela no es dinero hasta que alguien lo confirma.
        $this->sembrar(MetodoPago::class, [
            ['clave' => 'efectivo', 'nombre' => 'Efectivo', 'clave_sat' => '01', 'requiere_confirmacion' => false],
            ['clave' => 'cheque', 'nombre' => 'Cheque nominativo', 'clave_sat' => '02', 'requiere_confirmacion' => true],
            ['clave' => 'transferencia', 'nombre' => 'Transferencia (SPEI)', 'clave_sat' => '03', 'requiere_confirmacion' => true],
            ['clave' => 'tarjeta_credito', 'nombre' => 'Tarjeta de crédito', 'clave_sat' => '04', 'requiere_confirmacion' => true],
            ['clave' => 'tarjeta_debito', 'nombre' => 'Tarjeta de débito', 'clave_sat' => '28', 'requiere_confirmacion' => true],
            ['clave' => 'deposito_tienda', 'nombre' => 'Depósito en tienda', 'clave_sat' => '01', 'requiere_confirmacion' => true],
        ]);
    }

    /**
     * @param  class-string<Model>  $modelo
     * @param  array<int, array<string, mixed>>  $filas
     */
    private function sembrar(string $modelo, array $filas): void
    {
        foreach ($filas as $fila) {
            $clave = $fila['clave'];
            unset($fila['clave']);

            $modelo::query()->updateOrCreate(['clave' => $clave], $fila);
        }
    }
}
