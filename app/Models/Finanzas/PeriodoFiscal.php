<?php

declare(strict_types=1);

namespace App\Models\Finanzas;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * periodos_fiscales (TENANT) — un mes declarado y cerrado.
 *
 * Sólo existen las filas de los meses que alguien tocó: un mes sin fila está
 * ABIERTO. Nacen al cerrar y sobreviven a la reapertura, que es lo que permite
 * decir «este mes se cerró y se volvió a abrir por esto».
 */
class PeriodoFiscal extends Model
{
    use TieneAuditoria;

    protected $table = 'periodos_fiscales';

    protected $fillable = [
        'anio',
        'mes',
        'cerrado_en',
        'comprobantes',
        'ingresos',
        'egresos',
        'reabierto_en',
        'motivo_reapertura',
    ];

    protected function casts(): array
    {
        return [
            'anio' => 'integer',
            'mes' => 'integer',
            'cerrado_en' => 'datetime',
            'reabierto_en' => 'datetime',
            'ingresos' => 'decimal:2',
            'egresos' => 'decimal:2',
        ];
    }

    public function estaCerrado(): bool
    {
        return $this->cerrado_en !== null;
    }

    /** «Septiembre de 2026», para decirlo en una pantalla o en un error. */
    public function etiqueta(): string
    {
        return ucfirst(Carbon::create($this->anio, $this->mes, 1)->translatedFormat('F \d\e Y'));
    }
}
