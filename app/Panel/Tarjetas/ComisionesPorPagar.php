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

    public function icono(): string
    {
        return 'M21 11.25v8.25a1.5 1.5 0 0 1-1.5 1.5H5.25a1.5 1.5 0 0 1-1.5-1.5v-8.25M12 4.875A2.625 2.625 0 1 0 9.375 7.5H12m0-2.625V7.5m0-2.625A2.625 2.625 0 1 1 14.625 7.5H12m0 0V21m-8.625-9.75h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125Z';
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
