<?php

declare(strict_types=1);

namespace App\Models\ControlEscolar;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * biblioteca_enlaces (TENANT) — un recurso publicado en la biblioteca digital.
 *
 * Se administra desde Control Escolar y lo consulta el alumno desde su panel.
 */
class BibliotecaEnlace extends Model
{
    use TieneAuditoria;

    protected $table = 'biblioteca_enlaces';

    protected $fillable = ['titulo', 'descripcion', 'url', 'imagen_url', 'orden', 'activo'];

    protected function casts(): array
    {
        return ['orden' => 'integer', 'activo' => 'boolean'];
    }

    /** Lo que ve el alumno: sólo lo publicado, en el orden que decidió la escuela. */
    public function scopePublicados(Builder $consulta): Builder
    {
        return $consulta->where('activo', true)->orderBy('orden')->orderBy('id');
    }

    /**
     * Los que se pintan como tarjeta, que son los que traen imagen.
     *
     * La distinción vive en el modelo y no en la pantalla porque es una regla
     * del dominio —la escuela publica de dos maneras—, y las dos vistas que lo
     * consultan (la del alumno y la de quien administra) tienen que estar de
     * acuerdo en cuál es cuál.
     */
    public function esTarjeta(): bool
    {
        return $this->imagen_url !== null && $this->imagen_url !== '';
    }
}
