<?php

declare(strict_types=1);

namespace App\Permanencia;

use App\Permanencia\Proveedores\ProveedorDeSenales;
use InvalidArgumentException;

/**
 * Qué proveedores hay, y la guarda que impide una métrica sin quien la calcule.
 *
 * ── La guarda RUIDOSA, y por qué es lo más importante de esta clase ────────
 * `CatalogoMetricas` se declaró en la fase 1, antes que los proveedores. Eso
 * permitió que la pantalla de reglas ofreciera lo real desde el primer día, y a
 * cambio abrió un riesgo: una métrica declarada que nadie calcula. La regla se
 * guardaría, se encendería, y **no levantaría nada nunca** — sin un solo error,
 * que es la peor forma de romperse. Es exactamente lo que le pasó a este
 * proyecto con `ver-personas` y con `cierra_el_embudo`.
 *
 * Por eso `registrar()` comprueba al ARRANCAR que cada métrica que un proveedor
 * dice calcular exista en el catálogo, y `metricasSinProveedor()` contesta al
 * revés. Una prueba lo cruza y **falla en rojo** mientras alguna quede sin
 * calcular: es la guarda ruidosa que la fase 1 del módulo formativo ya usó,
 * escrita para caerse sola el día que deje de hacer falta.
 */
class RegistroProveedores
{
    /** @var array<string, ProveedorDeSenales> */
    private array $proveedores = [];

    public function registrar(ProveedorDeSenales $proveedor): void
    {
        foreach ($proveedor->metricas() as $metrica) {
            if (! CatalogoMetricas::existe($metrica)) {
                throw new InvalidArgumentException(
                    "El proveedor «{$proveedor->clave()}» dice calcular la métrica «{$metrica}», que no "
                    .'está declarada en `CatalogoMetricas`. Una regla que la use se guardaría y no '
                    .'levantaría nada: declárala o quítasela al proveedor.',
                );
            }

            $declarado = CatalogoMetricas::de($metrica)['proveedor'];

            if ($declarado !== $proveedor->clave()) {
                throw new InvalidArgumentException(
                    "La métrica «{$metrica}» está declarada para el proveedor «{$declarado}» y la "
                    ."calcula «{$proveedor->clave()}». Con los dos desalineados, el motor buscaría al "
                    .'proveedor equivocado y la regla no mediría nada.',
                );
            }
        }

        $this->proveedores[$proveedor->clave()] = $proveedor;
    }

    public function de(string $clave): ?ProveedorDeSenales
    {
        return $this->proveedores[$clave] ?? null;
    }

    /** @return array<string, ProveedorDeSenales> */
    public function todos(): array
    {
        return $this->proveedores;
    }

    /**
     * Las métricas declaradas que ningún proveedor calcula.
     *
     * Tiene que devolver un arreglo VACÍO. Lo vigila una prueba.
     *
     * @return array<int, string>
     */
    public function metricasSinProveedor(): array
    {
        $calculadas = [];

        foreach ($this->proveedores as $p) {
            $calculadas = array_merge($calculadas, $p->metricas());
        }

        return array_values(array_diff(CatalogoMetricas::claves(), $calculadas));
    }
}
