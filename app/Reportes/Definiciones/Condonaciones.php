<?php

declare(strict_types=1);

namespace App\Reportes\Definiciones;

use App\Reportes\DefinicionReporte;

/**
 * Cuánto dinero perdonó la escuela, a quién y de qué concepto.
 *
 * ── Por qué merece reporte propio ────────────────────────────────────────
 * Hoy esto sólo se puede reconstruir abriendo el estado de cuenta de cada
 * alumno, uno por uno. Es la cifra que una dirección pide al cerrar el año y la
 * que ninguna pantalla contesta: cancelar y condonar son decisiones que cuestan
 * dinero y que se toman de una en una, así que nadie las ve juntas nunca.
 */
class Condonaciones extends DefinicionReporte
{
    public function clave(): string
    {
        return 'condonaciones';
    }

    public function titulo(): string
    {
        return 'Cancelaciones y condonaciones';
    }

    public function descripcion(): string
    {
        return 'Los cargos que se cancelaron o se condonaron: a quién, de qué concepto y por cuánto. '
            .'NO son las becas —una beca se descuenta al generar el cargo y se ve en «Becas»—: esto es '
            .'lo que se perdonó DESPUÉS de haberlo cobrado. Y no dice quién lo autorizó: eso vive en la '
            .'auditoría del cargo.';
    }

    public function fuente(): string
    {
        return 'cargos';
    }

    public function areaSugerida(): string
    {
        return 'finanzas';
    }

    public function filtrosFijos(): array
    {
        return ['solo_perdonados' => true];
    }

    public function columnasPorOmision(): ?array
    {
        return ['matricula', 'alumno', 'programa_academico', 'concepto', 'periodo', 'monto_total', 'estatus'];
    }

    public function ordenPorOmision(): ?array
    {
        return ['monto_total', 'desc'];
    }
}
