<?php

declare(strict_types=1);

namespace App\Models\Identidad;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * credenciales_rol (TENANT) — cómo es la credencial virtual de un rol.
 *
 * Sin fila, ese rol no emite credencial. Ver la migración para el porqué.
 */
class CredencialRol extends Model
{
    use TieneAuditoria;

    /** Los tres diseños que trae el sistema, más el machote propio. */
    public const DISENOS = ['clasico', 'moderno', 'minimo', 'propio'];

    protected $table = 'credenciales_rol';

    protected $fillable = [
        'rol_id',
        'nivel_estudios_id',
        'activa',
        'diseno',
        'ancho',
        'alto',
        'machote_anverso',
        'machote_reverso',
        'campos_anverso',
        'campos_reverso',
        'vigencia',
        'leyenda',
        'qr_activo',
        'qr_publico',
        'firma_imagen',
        'firma_nombre',
        'firma_cargo',
    ];

    protected function casts(): array
    {
        return [
            'activa' => 'boolean',
            'qr_activo' => 'boolean',
            'qr_publico' => 'boolean',
            'ancho' => 'integer',
            'alto' => 'integer',
            'campos_anverso' => 'array',
            'campos_reverso' => 'array',
        ];
    }

    public function rol(): BelongsTo
    {
        return $this->belongsTo(Rol::class, 'rol_id');
    }

    /** Con machote propio hace falta al menos el anverso; los diseños no. */
    public function usaMachotePropio(): bool
    {
        return $this->diseno === 'propio';
    }

    /**
     * Si se puede emitir de verdad.
     *
     * Estar activa no basta: un machote propio sin imagen de anverso dibujaría
     * una credencial en blanco, y es mejor no ofrecerla que entregar eso.
     */
    public function emitible(): bool
    {
        return $this->activa
            && (! $this->usaMachotePropio() || filled($this->machote_anverso));
    }

    /**
     * Si tiene reverso que enseñar.
     *
     * Con machote propio lo dice su imagen; con un diseño del sistema, que
     * alguien haya puesto algún campo detrás. Un reverso vacío no se genera:
     * sería una tarjeta en blanco que el alumno se descarga sin saber por qué.
     */
    public function tieneReverso(): bool
    {
        return $this->usaMachotePropio()
            ? filled($this->machote_reverso)
            : filled($this->campos_reverso);
    }
}
