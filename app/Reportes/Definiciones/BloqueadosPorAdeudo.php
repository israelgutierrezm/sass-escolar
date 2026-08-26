<?php

declare(strict_types=1);

namespace App\Reportes\Definiciones;

use App\Reportes\DefinicionReporte;

/**
 * Quién NO puede reinscribirse por su situación financiera.
 *
 * ── Lo decide la BANDERA, no el saldo ────────────────────────────────────
 * `situaciones_pago.bloquea` es lo que consulta el sistema para impedir una
 * inscripción; el saldo es otra cosa. Una escuela puede tener alumnos que deben
 * y no están bloqueados —un convenio de pago— y alumnos bloqueados sin saldo
 * —un cheque devuelto que ya se cubrió pero cuya situación nadie ha movido—.
 * Preguntar por el saldo daría una lista parecida y equivocada.
 */
class BloqueadosPorAdeudo extends DefinicionReporte
{
    public function clave(): string
    {
        return 'bloqueados-por-adeudo';
    }

    public function titulo(): string
    {
        return 'Bloqueados por adeudo';
    }

    public function descripcion(): string
    {
        return 'Las matrículas cuya situación financiera vigente tiene la bandera de BLOQUEO, que es '
            .'la que de verdad impide reinscribirse. NO es «quién debe»: se puede deber sin estar '
            .'bloqueado —un convenio— y estar bloqueado con saldo en cero. Para lo otro, «Cartera vencida».';
    }

    public function fuente(): string
    {
        return 'cartera';
    }

    public function areaSugerida(): string
    {
        return 'finanzas';
    }

    public function filtrosFijos(): array
    {
        return ['solo_bloqueados' => true];
    }

    public function columnasPorOmision(): ?array
    {
        return ['matricula', 'alumno', 'carrera', 'campus', 'situacion_financiera', 'saldo', 'vencido'];
    }

    public function ordenPorOmision(): ?array
    {
        return ['matricula', 'asc'];
    }
}
