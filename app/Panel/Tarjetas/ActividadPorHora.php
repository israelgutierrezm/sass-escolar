<?php

declare(strict_types=1);

namespace App\Panel\Tarjetas;

use App\Models\Identidad\Usuario;
use App\Panel\TarjetaPanel;
use Illuminate\Support\Facades\DB;

/**
 * A qué hora entra la gente a la plataforma hoy.
 *
 * Sale de `sessions.last_activity`, que es lo que la aplicación de verdad
 * registra. Se rotula como ACTIVIDAD y no como "accesos": una sesión abierta a
 * las 8 y usada a las 11 cuenta en las 11, así que llamarle "accesos" sería
 * decir algo que el dato no dice.
 *
 * Se pintan las 24 horas aunque estén en cero: una gráfica que solo muestra las
 * horas con actividad esconde justo la forma de la jornada.
 */
class ActividadPorHora implements TarjetaPanel
{
    public function clave(): string
    {
        return 'actividad-por-hora';
    }

    public function titulo(): string
    {
        return 'Actividad de hoy por hora';
    }

    public function permiso(): ?string
    {
        return 'ver-configuracion';
    }

    /**
     * Columnas, no barras: veinticuatro barras horizontales apiladas ocupaban
     * media pantalla de alto y le robaban visibilidad a todo lo demás. Una
     * serie de 24 puntos es naturalmente ancha y baja.
     */
    public function tipo(): string
    {
        return 'columnas';
    }

    public function ancho(): int
    {
        return 2;
    }

    public function icono(): string
    {
        return 'M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z';
    }

    public function datos(Usuario $usuario): ?array
    {
        $desde = now()->startOfDay()->timestamp;

        $conteos = DB::table('sessions')
            ->whereNotNull('user_id')
            ->where('last_activity', '>=', $desde)
            ->selectRaw('hour(from_unixtime(last_activity)) as hora, count(distinct user_id) as total')
            ->groupBy('hora')
            ->pluck('total', 'hora');

        $series = [];

        for ($hora = 0; $hora < 24; $hora++) {
            $series[] = [
                'etiqueta' => str_pad((string) $hora, 2, '0', STR_PAD_LEFT).'h',
                'valor' => (int) ($conteos[$hora] ?? 0),
            ];
        }

        $total = array_sum(array_column($series, 'valor'));

        if ($total === 0) {
            return null;
        }

        return [
            'series' => $series,
            'pie' => 'Personas distintas activas hoy, por hora',
        ];
    }
}
