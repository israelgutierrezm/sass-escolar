<?php

declare(strict_types=1);

namespace App\Panel\Tarjetas;

use App\Models\Identidad\Usuario;
use App\Panel\TarjetaPanel;
use Illuminate\Support\Facades\DB;

/**
 * Qué día de la semana y a qué hora entra la gente a la plataforma.
 *
 * ── Por qué ya no sale de `sessions` ───────────────────────────────────────
 * La versión anterior contaba `sessions.last_activity` y sólo podía hablar de
 * HOY, porque esa tabla guarda una fila por sesión viva y la va pisando: no
 * tiene historia que consultar. Para cruzar día contra hora hace falta un
 * registro que no se borre, y ése es `bitacora_accesos`, que anota cada entrada
 * con su momento y no se toca nunca.
 *
 * El cambio de fuente cambia también lo que se cuenta, y conviene decirlo: antes
 * eran personas distintas activas; ahora son ENTRADAS. Quien entra tres veces un
 * martes suma tres. Es lo que corresponde a la pregunta que responde la
 * cuadrícula —cuándo se usa la plataforma—, y es lo que dice el pie de la
 * tarjeta para que nadie lo lea como «cuánta gente».
 *
 * Se pintan las 24 horas y los 7 días aunque estén vacíos: una cuadrícula que
 * sólo muestra lo que tiene actividad esconde justo la forma de la semana.
 */
class ActividadPorHora implements TarjetaPanel
{
    /** Cuántos días hacia atrás se miran. */
    private const DIAS = 30;

    /** Domingo primero, como en cualquier calendario mexicano. */
    private const DIAS_SEMANA = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];

    public function clave(): string
    {
        // La clave NO cambia aunque el título y el dibujo sí: es lo que ata la
        // tarjeta al acomodo que cada quien guardó en `disposicion_panel`.
        // Renombrarla dejaría a todo el mundo con su tarjeta de vuelta al final.
        return 'actividad-por-hora';
    }

    public function titulo(): string
    {
        return 'Actividad por día y hora';
    }

    public function permiso(): ?string
    {
        return 'ver-configuracion';
    }

    public function tipo(): string
    {
        return 'matriz';
    }

    /**
     * El ancho completo, porque son 24 columnas.
     *
     * A media anchura cada hora se queda en unos 13 px y los puntos dejan de
     * poder crecer sin tocarse, que es justo lo que hace legible la cuadrícula.
     * Quien lo prefiera estrecho lo devuelve a la mitad desde «Acomodar».
     */
    public function ancho(): int
    {
        return 4;
    }

    public function icono(): string
    {
        return 'M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z';
    }

    public function datos(Usuario $usuario): ?array
    {
        $conteos = $this->entradasPorDiaYHora();

        if ($conteos === []) {
            return null;
        }

        $filas = [];

        foreach (self::DIAS_SEMANA as $dia => $nombre) {
            $horas = [];

            for ($hora = 0; $hora < 24; $hora++) {
                $horas[] = (int) ($conteos["{$dia}-{$hora}"] ?? 0);
            }

            $filas[] = [
                'etiqueta' => $nombre,
                'horas' => $horas,
                'total' => array_sum($horas),
            ];
        }

        return [
            'filas' => $filas,
            // El máximo viaja calculado: es el que gradúa el tamaño de cada
            // punto, y sacarlo en el navegador obligaría a recorrer las 168
            // celdas en cada repintado para llegar al mismo número.
            'maximo' => max(array_map(fn (array $f) => max($f['horas']), $filas)),
            'pie' => 'Entradas a la plataforma en los últimos '.self::DIAS.' días',
        ];
    }

    /**
     * Entradas agrupadas por día de la semana y hora, indexadas «dia-hora» con
     * el día en 0..6 empezando en domingo.
     *
     * El agrupado va en SQL y no recorriendo filas en PHP: son treinta días de
     * accesos de toda la escuela, y traérselos para contarlos aquí es lo que
     * vuelve lento un panel que se abre cada mañana.
     *
     * @return array<string, int>
     */
    private function entradasPorDiaYHora(): array
    {
        // `dayofweek` de MySQL devuelve 1..7 empezando en domingo; `strftime`
        // de SQLite —el de las pruebas— devuelve 0..6, también desde domingo.
        // Se normalizan los dos a 0..6.
        $sqlite = DB::connection()->getDriverName() === 'sqlite';

        $dia = $sqlite ? "cast(strftime('%w', creado_en) as integer)" : 'dayofweek(creado_en) - 1';
        $hora = $sqlite ? "cast(strftime('%H', creado_en) as integer)" : 'hour(creado_en)';

        return DB::table('bitacora_accesos')
            ->where('tipo', 'entrada')
            ->where('creado_en', '>=', now()->subDays(self::DIAS - 1)->startOfDay())
            ->selectRaw("{$dia} as dia, {$hora} as hora, count(*) as total")
            ->groupBy('dia', 'hora')
            ->get()
            ->mapWithKeys(fn ($fila) => ["{$fila->dia}-{$fila->hora}" => (int) $fila->total])
            ->all();
    }
}
