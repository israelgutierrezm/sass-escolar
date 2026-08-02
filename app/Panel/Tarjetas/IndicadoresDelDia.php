<?php

declare(strict_types=1);

namespace App\Panel\Tarjetas;

use App\Models\Identidad\Usuario;
use App\Panel\TarjetaPanel;
use App\Services\Plataforma\IndicadoresFinancieros as Servicio;

/**
 * UMA y tipo de cambio: los dos números con los que se hacen cuentas.
 *
 * ── Para quién ─────────────────────────────────────────────────────────────
 * Para quien cobra, factura o arma becas. Un docente no necesita saber cuánto
 * vale la UMA, así que la tarjeta cuelga de `ver-finanzas` y no aparece en el
 * panel de quien no la usa.
 *
 * ── Por qué en el panel y no en una pantalla ───────────────────────────────
 * Son datos que se consultan de reojo mientras se hace otra cosa: se está
 * capturando un recargo y hace falta la UMA, o cotizando una colegiatura en
 * dólares. Meterlos en su propia pantalla obligaría a salir de lo que se está
 * haciendo para volver con un número en la cabeza.
 */
class IndicadoresDelDia implements TarjetaPanel
{
    public function __construct(private readonly Servicio $indicadores) {}

    public function clave(): string
    {
        return 'indicadores';
    }

    public function titulo(): string
    {
        return 'Indicadores del día';
    }

    public function permiso(): ?string
    {
        // El mismo que la cartera: quien mira lo que se debe es quien necesita
        // la UMA y el dólar para calcularlo.
        return 'ver-adeudos';
    }

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
        return 'M2.25 18 9 11.25l4.306 4.307a11.95 11.95 0 0 1 5.814-5.518l2.74-1.22m0 0-5.94-2.281m5.94 2.28-2.28 5.941';
    }

    public function datos(Usuario $usuario): ?array
    {
        $uma = $this->indicadores->uma();
        $cambio = $this->indicadores->tipoDeCambio();

        $columnas = [];

        if ($uma['disponible']) {
            $columnas[] = [
                'etiqueta' => "UMA {$uma['anio']}",
                'valor' => '$'.number_format($uma['diaria'], 2),
                'detalle' => 'diaria · $'.number_format($uma['mensual'], 2).' al mes',
            ];
        } else {
            // Se dice que falta, en vez de mostrar la del año pasado como si
            // fuera la vigente: con un número viejo alguien calcula una beca.
            $columnas[] = [
                'etiqueta' => 'UMA',
                'valor' => '—',
                'detalle' => $uma['aviso'],
            ];
        }

        if ($cambio !== null) {
            $columnas[] = [
                'etiqueta' => 'Dólar',
                'valor' => '$'.number_format($cambio['valor'], 4),
                // La fuente NO es adorno: con la referencia del BCE no se
                // timbra, y quien mire el número tiene que saberlo.
                'detalle' => $cambio['fuente'].' · '.$cambio['fecha'],
            ];
        }

        return $columnas === [] ? null : [
            'columnas' => $columnas,
            'pie' => $cambio !== null && ! $cambio['oficial']
                ? 'Para CFDI usa el FIX de Banxico: configura BANXICO_TOKEN (es gratuito).'
                : null,
        ];
    }
}
