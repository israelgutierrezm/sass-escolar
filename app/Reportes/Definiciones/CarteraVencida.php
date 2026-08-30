<?php

declare(strict_types=1);

namespace App\Reportes\Definiciones;

use App\Reportes\DefinicionReporte;

/**
 * Quién debe dinero YA VENCIDO.
 *
 * Es el reporte de cobranza: con quién hay que hablar hoy. Deber no es lo mismo
 * que deber tarde, y mezclarlos convierte una lista de trabajo en un padrón.
 */
class CarteraVencida extends DefinicionReporte
{
    public function clave(): string
    {
        return 'cartera-vencida';
    }

    public function titulo(): string
    {
        return 'Cartera vencida';
    }

    public function descripcion(): string
    {
        return 'Las matrículas con saldo que ya pasó su fecha de vencimiento, de mayor a menor. '
            .'NO es la cartera total —para eso está «Estado de cartera»— ni incluye lo que deben los '
            .'ASPIRANTES, que todavía no tienen matrícula. Y una fila es una matrícula: quien estudia '
            .'dos programas académicos aparece dos veces, cada una con lo suyo.';
    }

    public function fuente(): string
    {
        return 'cartera';
    }

    public function areaSugerida(): string
    {
        return 'finanzas';
    }

    /**
     * «Con vencido» es fijo, y eso es lo que lo hace un reporte.
     *
     * Sugerirlo como filtro por omisión no bastaría: quien lo quitara sin
     * darse cuenta se llevaría el padrón entero creyendo que son morosos.
     */
    public function filtrosFijos(): array
    {
        return ['solo_vencido' => true];
    }

    public function columnasPorOmision(): ?array
    {
        return ['matricula', 'alumno', 'programa_academico', 'campus', 'vencido', 'saldo', 'cargos_abiertos'];
    }

    public function ordenPorOmision(): ?array
    {
        return ['vencido', 'desc'];
    }
}
