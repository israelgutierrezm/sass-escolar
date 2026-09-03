<?php

declare(strict_types=1);

namespace App\Models\Reportes;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * reportes_escuela (TENANT) — un reporte que armó la escuela.
 *
 * Es un PRESET sobre una fuente del código, no una consulta: fuente + nombre +
 * columnas + filtros fijos. El permiso, el módulo y la faceta los pone la
 * FUENTE, así que un reporte de aquí no puede abrir una puerta que su fuente
 * tenga cerrada.
 */
class ReporteEscuela extends Model
{
    use TieneAuditoria;

    /**
     * El prefijo de las claves.
     *
     * Sin él, una escuela que llamara al suyo «alumnos-inscritos» SOMBREARÍA al
     * del código —el registro es un mapa por clave— y nadie sabría por qué el
     * reporte de siempre cambió de columnas.
     */
    public const PREFIJO = 'esc-';

    protected $table = 'reportes_escuela';

    protected $attributes = ['publicado' => false];

    protected $fillable = [
        'clave',
        'nombre',
        'descripcion',
        'fuente',
        'area_sugerida',
        'columnas',
        'filtros_fijos',
        'filtros_obligatorios',
        'orden_por',
        'orden_dir',
        'publicado',
    ];

    protected function casts(): array
    {
        return [
            'columnas' => 'array',
            'filtros_fijos' => 'array',
            'filtros_obligatorios' => 'array',
            'publicado' => 'boolean',
        ];
    }

    public function scopePublicados(Builder $consulta): Builder
    {
        return $consulta->where('publicado', true);
    }

    /**
     * Una clave estable a partir del nombre, con su prefijo.
     *
     * Se genera UNA vez y no se recalcula al renombrar: la guardan la
     * ubicación, las vistas guardadas y la bitácora, y cambiarla dejaría
     * huérfano todo lo que la nombra —la bitácora conserva la clave de un
     * reporte retirado justamente para poder decir qué se corrió—.
     */
    public static function claveDe(string $nombre, callable $existe): string
    {
        $base = self::PREFIJO.Str::slug(Str::limit($nombre, 60, ''));
        $clave = $base;
        $n = 2;

        while ($existe($clave)) {
            $clave = $base.'-'.$n;
            $n++;
        }

        return $clave;
    }
}
