<?php

declare(strict_types=1);

namespace App\Reportes\Definiciones;

use App\Reportes\DefinicionReporte;

/**
 * El dinero reportado que todavía NO baja ningún saldo.
 *
 * Es una COLA DE TRABAJO: cada fila es alguien que cree haber pagado y a quien
 * el sistema le sigue cobrando. Mientras nadie la verifique, el alumno aparece
 * como deudor y puede quedar bloqueado para reinscribirse.
 */
class PagosPorConfirmar extends DefinicionReporte
{
    public function clave(): string
    {
        return 'pagos-por-confirmar';
    }

    public function titulo(): string
    {
        return 'Pagos por confirmar';
    }

    public function descripcion(): string
    {
        return 'Cheques, transferencias y cobros en línea reportados y todavía sin verificar. NO han '
            .'bajado ningún saldo, así que quien está aquí sigue apareciendo como deudor. Es una cola '
            .'de trabajo, no un ingreso: hasta confirmarlos no cuentan en el corte de caja.';
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
        return ['solo_por_confirmar' => true];
    }

    public function columnasPorOmision(): ?array
    {
        return ['momento', 'matricula', 'alumno', 'metodo', 'monto', 'referencia', 'pasarela'];
    }

    public function ordenPorOmision(): ?array
    {
        return ['momento', 'asc'];
    }
}
