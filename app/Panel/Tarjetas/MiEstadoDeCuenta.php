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

    public function icono(): string
    {
        return 'M12 6v12m-3-2.818.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z';
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
