<?php

declare(strict_types=1);

namespace App\Reportes\Definiciones;

use App\Reportes\DefinicionReporte;

/**
 * Qué facturó el motor de cobro, y de qué conceptos.
 *
 * Es el reporte con el que se concilia la emisión: cuántos cargos salieron en un
 * rango de fechas, de qué conceptos y por cuánto. La pregunta que sólo se puede
 * hacer en el grano de CARGO — en el de matrícula el saldo ya viene sumado y el
 * concepto ha desaparecido.
 */
class CargosEmitidos extends DefinicionReporte
{
    public function clave(): string
    {
        return 'cargos-emitidos';
    }

    public function titulo(): string
    {
        return 'Cargos emitidos';
    }

    public function descripcion(): string
    {
        return 'Cada cargo generado, con su concepto, su periodo y cuánto se ha cobrado de él. '
            .'Es lo que emitió el motor de cobro, NO lo que entró en caja —para eso está «Corte de '
            .'caja»—, y NO incluye los cargos de aspirantes. Una fila es un cargo: un alumno con '
            .'tres colegiaturas aparece tres veces.';
    }

    public function fuente(): string
    {
        return 'cargos';
    }

    public function areaSugerida(): string
    {
        return 'finanzas';
    }

    public function columnasPorOmision(): ?array
    {
        return ['matricula', 'alumno', 'concepto', 'periodo', 'monto_total', 'cobrado', 'por_cobrar', 'vence'];
    }

    public function ordenPorOmision(): ?array
    {
        return ['generado', 'desc'];
    }
}
