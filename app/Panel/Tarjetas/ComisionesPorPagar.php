<?php

declare(strict_types=1);

namespace App\Panel\Tarjetas;

use App\Models\Identidad\Usuario;
use App\Models\Promocion\Comision;
use App\Panel\TarjetaPanel;

/**
 * Lo que la escuela le debe a promoción. Quien coordina ve el total; cada
 * promotor, lo suyo — es la misma frontera de la pantalla de comisiones.
 */
class ComisionesPorPagar implements TarjetaPanel
{
    public function clave(): string
    {
        return 'comisiones-por-pagar';
    }

    public function titulo(): string
    {
        return 'Comisiones por pagar';
    }

    public function permiso(): ?string
    {
        return 'ver-mis-prospectos';
    }

    public function tipo(): string
    {
        return 'metrica';
    }

    public function ancho(): int
    {
        return 1;
    }

    public function datos(Usuario $usuario): ?array
    {
        $consulta = Comision::query()->porPagar();

        $mias = ! $usuario->can('gestionar-comisiones');

        if ($mias) {
            $consulta->where('persona_id', $usuario->persona_id);
        }

        $total = (float) $consulta->sum('monto');
        $cuantas = $consulta->count();

        if ($cuantas === 0) {
            return null;
        }

        return [
            'valor' => round($total, 2),
            'formato' => 'moneda',
            'pie' => $mias
                ? "{$cuantas} tuyas por cobrar"
                : "{$cuantas} devengadas sin pagar",
            'enlace' => '/promocion/comisiones',
        ];
    }
}
