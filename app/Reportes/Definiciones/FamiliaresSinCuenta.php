<?php

declare(strict_types=1);

namespace App\Reportes\Definiciones;

use App\Reportes\DefinicionReporte;

/**
 * Familiares con permiso y sin forma de ejercerlo.
 *
 * ── Es la razón por la que una circular «no llegó» ───────────────────────
 * El vínculo les da permiso de ver lo académico o lo financiero, y de contestar
 * una autorización. Sin cuenta no pueden entrar al portal, así que ese permiso
 * no existe en la práctica: el día de la excursión resulta que a tres nunca se
 * les pidió nada — que es exactamente el caso que el módulo de autorizaciones
 * ya reporta por su nombre al emitir, sólo que cuando ya es tarde.
 */
class FamiliaresSinCuenta extends DefinicionReporte
{
    public function clave(): string
    {
        return 'familiares-sin-cuenta';
    }

    public function titulo(): string
    {
        return 'Familiares sin cuenta';
    }

    public function descripcion(): string
    {
        return 'Vínculos cuyo tutor NO tiene cuenta en la plataforma: tiene permisos que no puede '
            .'ejercer, no entra al portal y no puede contestar una autorización. Es la explicación '
            .'más frecuente de que una circular «no llegue». NO dice si el correo es correcto: sólo '
            .'que no hay cuenta con la que entrar.';
    }

    public function fuente(): string
    {
        return 'vinculos-familiares';
    }

    public function areaSugerida(): string
    {
        return 'familia';
    }

    public function filtrosFijos(): array
    {
        return ['sin_cuenta' => true];
    }

    public function columnasPorOmision(): ?array
    {
        return ['alumno', 'tutor', 'parentesco', 'telefono', 'correo', 've_academico', 've_finanzas'];
    }

    public function ordenPorOmision(): ?array
    {
        return ['alumno', 'asc'];
    }
}
