<?php

declare(strict_types=1);

namespace App\Reportes\Definiciones;

use App\Reportes\DefinicionReporte;

/**
 * Cuánto dinero entró, y por qué método.
 *
 * ── Sólo lo CONFIRMADO, y es lo que lo hace un corte ─────────────────────
 * Un cheque reportado y no verificado todavía puede rebotar. Incluirlo daría un
 * corte que no cuadra con el banco, que es exactamente lo que un corte existe
 * para hacer.
 */
class CorteDeCaja extends DefinicionReporte
{
    public function clave(): string
    {
        return 'corte-de-caja';
    }

    public function titulo(): string
    {
        return 'Corte de caja';
    }

    public function descripcion(): string
    {
        return 'El dinero CONFIRMADO que entró, con su método y a qué cargos se aplicó. Un depósito '
            .'que cubre tres mensualidades sale UNA vez. NO incluye lo reportado y sin verificar —para '
            .'eso está «Pagos por confirmar»— ni lo que pagan los aspirantes antes de matricularse.';
    }

    public function fuente(): string
    {
        return 'ingresos';
    }

    public function areaSugerida(): string
    {
        return 'finanzas';
    }

    public function filtrosFijos(): array
    {
        return ['solo_cobrados' => true];
    }

    public function columnasPorOmision(): ?array
    {
        return ['momento', 'matricula', 'alumno', 'metodo', 'monto', 'aplicado', 'sin_aplicar'];
    }

    public function ordenPorOmision(): ?array
    {
        return ['momento', 'desc'];
    }
}
