<?php

declare(strict_types=1);

namespace App\Reportes\Definiciones;

use App\Reportes\DefinicionReporte;

/**
 * Quién se fue, cuándo y por qué.
 *
 * ── Es la pregunta de ROTACIÓN, y no había dónde hacerla ─────────────────
 * La plantilla contesta quién está; ésta, quién dejó de estar. Sin ella, medir
 * cuánta gente se va —y por qué motivo— obliga a abrir expediente por
 * expediente. Y la antigüedad se cuenta hasta la BAJA: dejarla correr hasta hoy
 * inflaría cualquier cálculo de prima o de liquidación.
 */
class BajasDePersonal extends DefinicionReporte
{
    public function clave(): string
    {
        return 'bajas-de-personal';
    }

    public function titulo(): string
    {
        return 'Bajas de personal';
    }

    public function descripcion(): string
    {
        return 'Los expedientes con fecha de baja, con su motivo y la antigüedad que alcanzaron. La '
            .'antigüedad se cuenta hasta la BAJA, no hasta hoy. NO dice si el puesto se cubrió: para '
            .'eso está la plantilla vigente. Y quien fue recontratado aparece aquí por su vínculo '
            .'anterior y en la plantilla por el nuevo, que es lo correcto: son dos historias.';
    }

    public function fuente(): string
    {
        return 'plantilla-laboral';
    }

    public function areaSugerida(): string
    {
        return 'rh';
    }

    public function filtrosFijos(): array
    {
        return ['solo_bajas' => true];
    }

    public function columnasPorOmision(): ?array
    {
        return ['numero_empleado', 'empleado', 'puesto', 'campus', 'fecha_ingreso', 'fecha_baja', 'antiguedad_anios', 'motivo_baja'];
    }

    public function ordenPorOmision(): ?array
    {
        return ['fecha_baja', 'desc'];
    }
}
