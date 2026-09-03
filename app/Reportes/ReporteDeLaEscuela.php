<?php

declare(strict_types=1);

namespace App\Reportes;

use App\Models\Reportes\ReporteEscuela;

/**
 * Un reporte que armó la escuela, con la misma forma que uno del código.
 *
 * ── Es la MISMA clase de cosa ──────────────────────────────────────────────
 * Hereda de `DefinicionReporte` y no de otra jerarquía paralela: para el motor,
 * el ejecutor, la bitácora, las vistas guardadas y las programaciones por
 * correo, un reporte de la escuela es indistinguible de uno escrito a mano.
 * Una segunda jerarquía habría obligado a que cada uno de esos seis sitios
 * preguntara «¿y si es de la escuela?», y el que se olvidara dejaría un hueco.
 *
 * ── Y no trae permiso propio ───────────────────────────────────────────────
 * `RegistroReportes::para()` resuelve el permiso, el módulo y la faceta
 * mirando la FUENTE. Así un reporte armado desde pantalla no puede abrir una
 * puerta que su fuente tenga cerrada, y quien lo arma no puede concederse nada
 * que no tuviera ya.
 */
class ReporteDeLaEscuela extends DefinicionReporte
{
    public function __construct(private readonly ReporteEscuela $fila) {}

    public function clave(): string
    {
        return $this->fila->clave;
    }

    public function titulo(): string
    {
        return $this->fila->nombre;
    }

    public function descripcion(): string
    {
        return $this->fila->descripcion;
    }

    public function fuente(): string
    {
        return $this->fila->fuente;
    }

    public function areaSugerida(): string
    {
        return $this->fila->area_sugerida ?: 'general';
    }

    /** @return array<string, mixed> */
    public function filtrosFijos(): array
    {
        return $this->fila->filtros_fijos ?? [];
    }

    /**
     * Los filtros que hay que elegir para poder correrlo.
     *
     * Se guardan porque un reporte armado desde pantalla no puede ser el UNICO
     * que no sepa acotarse: sobre una fuente grande, sin ellos barre la escuela
     * entera. El motor los exige igual que los de un reporte del codigo.
     *
     * @return array<int, string>
     */
    public function filtrosObligatorios(): array
    {
        return $this->fila->filtros_obligatorios ?? [];
    }

    /** @return array<int, string>|null */
    public function columnasPorOmision(): ?array
    {
        $columnas = $this->fila->columnas ?? [];

        return $columnas === [] ? null : $columnas;
    }

    /** @return array{0: string, 1: string}|null */
    public function ordenPorOmision(): ?array
    {
        if ($this->fila->orden_por === null || $this->fila->orden_por === '') {
            return null;
        }

        return [$this->fila->orden_por, $this->fila->orden_dir === 'desc' ? 'desc' : 'asc'];
    }

    /** Para la pantalla: el id de la fila que lo respalda. */
    public function id(): int
    {
        return (int) $this->fila->id;
    }
}
