<?php

declare(strict_types=1);

namespace App\Panel\Tarjetas;

use App\Models\Admisiones\MatriculaOferta;
use App\Models\Finanzas\Adeudo;
use App\Models\Identidad\Usuario;
use App\Panel\TarjetaPanel;

/**
 * Lo que el alumno debe. Solo lo SUYO: el permiso `ver-adeudos` lo tiene también
 * finanzas, pero esta tarjeta se acota a sus propias matrículas y desaparece si
 * no tiene ninguna.
 */
class MiEstadoDeCuenta implements TarjetaPanel
{
    public function clave(): string
    {
        return 'mi-saldo';
    }

    public function titulo(): string
    {
        return 'Mi estado de cuenta';
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

    public function datos(Usuario $usuario): ?array
    {
        $matriculas = MatriculaOferta::query()
            ->where('persona_id', $usuario->persona_id)
            ->pluck('id');

        if ($matriculas->isEmpty()) {
            return null;
        }

        // A diferencia de las tarjetas que son COLA DE TRABAJO —"contactar
        // hoy" desaparece si no hay nada—, ésta se muestra aunque el saldo sea
        // cero: para un alumno, "no debes nada" es información que quiere ver
        // confirmada, no ruido. La regla es: una métrica propia en cero informa;
        // una lista de pendientes vacía enseña a ignorar la tarjeta.
        $abiertos = Adeudo::query()->whereIn('matricula_oferta_id', $matriculas)->porCobrar()->get();

        $saldo = round($abiertos->sum(fn (Adeudo $a) => $a->saldo()), 2);
        $vencidos = $abiertos->filter(fn (Adeudo $a) => $a->estaVencido());

        return [
            'valor' => $saldo,
            'formato' => 'moneda',
            // Se dice si hay vencido y no solo el total: deber 2 000 del mes que
            // corre y deber 2 000 desde hace tres meses son cosas distintas.
            'pie' => $vencidos->isEmpty()
                ? ($saldo > 0 ? $abiertos->count().' cargos al corriente' : 'Sin adeudos')
                : $vencidos->count().' cargos VENCIDOS',
            'alerta' => $vencidos->isNotEmpty(),
            'enlace' => null,
        ];
    }
}
