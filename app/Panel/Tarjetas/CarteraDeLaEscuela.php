<?php

declare(strict_types=1);

namespace App\Panel\Tarjetas;

use App\Models\Finanzas\Adeudo;
use App\Models\Finanzas\Pago;
use App\Models\Identidad\Usuario;
use App\Panel\TarjetaPanel;
use Illuminate\Support\Facades\DB;

/**
 * Saldo y vencido de toda la escuela, para quien lleva finanzas.
 *
 * Se agrega en SQL, no recorriendo adeudos: es una tarjeta del panel, o sea
 * algo que se pinta en CADA carga de la pantalla principal.
 */
class CarteraDeLaEscuela implements TarjetaPanel
{
    public function clave(): string
    {
        return 'cartera';
    }

    public function titulo(): string
    {
        return 'Cartera de la escuela';
    }

    public function permiso(): ?string
    {
        return 'ver-adeudos';
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
        return 'M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 0 0-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 0 1-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 0 0 3 15h-.75M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z';
    }

    public function datos(Usuario $usuario): ?array
    {
        // Esta es la vista GLOBAL: solo la ve quien administra el cobro. Un
        // alumno tiene `ver-adeudos` para lo suyo y no debe ver la cartera de
        // la escuela, así que se pide además el permiso de operación.
        if (! $usuario->can('registrar-pagos') && ! $usuario->can('gestionar-planes-cobro')) {
            return null;
        }

        $aplicados = DB::table('pago_adeudo as pa')
            ->join('pagos as p', 'p.id', '=', 'pa.pago_id')
            ->whereNull('pa.deleted_at')->whereNull('p.deleted_at')
            ->where('p.estatus', Pago::ESTATUS_COMPLETADO)
            ->groupBy('pa.adeudo_id')
            ->select('pa.adeudo_id', DB::raw('sum(pa.monto_aplicado) as aplicado'));

        $fila = DB::table('adeudos as a')
            ->leftJoinSub($aplicados, 'ap', 'ap.adeudo_id', '=', 'a.id')
            ->whereNull('a.deleted_at')
            ->whereIn('a.estatus', [Adeudo::ESTATUS_PENDIENTE, Adeudo::ESTATUS_PARCIAL])
            ->selectRaw('coalesce(sum(a.monto_total - coalesce(ap.aplicado, 0)), 0) as saldo')
            ->selectRaw(
                'coalesce(sum(case when a.fecha_vencimiento < ? then a.monto_total - coalesce(ap.aplicado, 0) else 0 end), 0) as vencido',
                [now()->toDateString()]
            )
            ->first();

        $vencido = round((float) ($fila->vencido ?? 0), 2);

        return [
            'valor' => round((float) ($fila->saldo ?? 0), 2),
            'formato' => 'moneda',
            'pie' => $vencido > 0
                ? number_format($vencido, 2).' vencido'
                : 'Nada vencido',
            'alerta' => $vencido > 0,
            'enlace' => '/finanzas',
        ];
    }
}
