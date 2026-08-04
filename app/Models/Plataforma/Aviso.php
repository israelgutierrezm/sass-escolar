<?php

declare(strict_types=1);

namespace App\Models\Plataforma;

use App\Enums\PrioridadAviso;
use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * avisos (TENANT) — un mensaje de la escuela a quien corresponda.
 *
 * No es un evento de calendario: un evento ocupa un día en la rejilla; un aviso
 * es un mensaje que tiene que LLEGAR. De ahí que tenga prioridad, constancia de
 * lectura y vigencia propia. Ver la migración para el razonamiento completo.
 */
class Aviso extends Model
{
    use TieneAuditoria;

    protected $table = 'avisos';

    protected $fillable = [
        'titulo',
        'cuerpo',
        'prioridad',
        'publicado_desde',
        'vigente_hasta',
        'publicado',
    ];

    protected function casts(): array
    {
        return [
            'prioridad' => PrioridadAviso::class,
            'publicado_desde' => 'datetime',
            'vigente_hasta' => 'datetime',
            'publicado' => 'boolean',
        ];
    }

    public function destinos(): HasMany
    {
        return $this->hasMany(AvisoDestino::class, 'aviso_id');
    }

    /** Lo que lo acompaña: archivos y enlaces, en el orden en que se pusieron. */
    public function adjuntos(): HasMany
    {
        return $this->hasMany(AvisoAdjunto::class, 'aviso_id')->orderBy('orden');
    }

    public function lecturas(): HasMany
    {
        return $this->hasMany(AvisoLectura::class, 'aviso_id');
    }

    /**
     * Los que hoy deberían verse.
     *
     * Publicado, ya empezado y no caducado. Las fechas nulas no acotan: sin
     * `publicado_desde` vale desde que se publicó, y sin `vigente_hasta` hasta
     * que alguien lo retire.
     */
    public function scopeVigentes(Builder $q): Builder
    {
        return $q->where('publicado', true)
            ->where(fn (Builder $s) => $s->whereNull('publicado_desde')->orWhere('publicado_desde', '<=', now()))
            ->where(fn (Builder $s) => $s->whereNull('vigente_hasta')->orWhere('vigente_hasta', '>=', now()));
    }

    /** Un crítico no se quita de en medio hasta que alguien lo confirma. */
    public function exigeConfirmacion(): bool
    {
        return $this->prioridad->exigeConfirmacion();
    }
}
