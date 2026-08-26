<?php

declare(strict_types=1);

namespace App\Reportes\Definiciones;

use App\Reportes\DefinicionReporte;

/**
 * El padrón completo con su saldo, para conciliar.
 *
 * Sin filtros fijos a propósito: es el listado de `/finanzas` hecho archivo. Lo
 * que aporta sobre la pantalla es poder bajarlo entero —la pantalla pagina— y
 * elegir columnas.
 */
class EstadoDeCartera extends DefinicionReporte
{
    public function clave(): string
    {
        return 'estado-de-cartera';
    }

    public function titulo(): string
    {
        return 'Estado de cartera';
    }

    public function descripcion(): string
    {
        return 'Todas las matrículas con su saldo, deban o no. Sirve para conciliar contra '
            .'contabilidad. NO cuadra con el total del panel, y no es un error: la tarjeta de la '
            .'escuela incluye lo que deben los ASPIRANTES, que no tienen matrícula donde caer. '
            .'La diferencia está desglosada en «Cobros de aspirantes».';
    }

    public function fuente(): string
    {
        return 'cartera';
    }

    public function areaSugerida(): string
    {
        return 'finanzas';
    }

    public function columnasPorOmision(): ?array
    {
        return ['matricula', 'alumno', 'carrera', 'campus', 'situacion', 'saldo', 'vencido'];
    }

    public function ordenPorOmision(): ?array
    {
        return ['matricula', 'asc'];
    }
}
