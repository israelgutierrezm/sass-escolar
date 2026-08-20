<?php

declare(strict_types=1);

namespace App\Models\Lms;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * grabaciones (TENANT) — un archivo de una clase grabada.
 *
 * Varios por clase: Zoom devuelve el video, el audio aparte, el chat y a veces
 * la transcripción, y el chat de una clase es justo lo que alguien va a buscar
 * medio año después.
 *
 * ── Se ve sólo si la escuela lo enciende ───────────────────────────────────
 * `visible_alumnos` nace apagada. Una clase grabada trae caras y voces de
 * menores; publicarla es una decisión sobre datos personales, no un efecto
 * secundario de haber configurado el archivado.
 */
class Grabacion extends Model
{
    use TieneAuditoria;

    public const PENDIENTE = 'pendiente';

    public const ARCHIVANDO = 'archivando';

    public const ARCHIVADA = 'archivada';

    public const FALLIDA = 'fallida';

    protected $table = 'grabaciones';

    protected $fillable = [
        'videoconferencia_id',
        'origen',
        'id_externo',
        'tipo',
        'nombre',
        'bytes',
        'estado',
        'destino',
        'ruta_destino',
        'url_destino',
        'intentos',
        'error',
        'archivada_en',
        'visible_alumnos',
    ];

    protected function casts(): array
    {
        return [
            'bytes' => 'integer',
            'intentos' => 'integer',
            'archivada_en' => 'datetime',
            'visible_alumnos' => 'boolean',
        ];
    }

    public function clase(): BelongsTo
    {
        return $this->belongsTo(Videoconferencia::class, 'videoconferencia_id');
    }

    public function estaArchivada(): bool
    {
        return $this->estado === self::ARCHIVADA;
    }

    /** Si el alumno la puede abrir: archivada Y encendida. Las dos cosas. */
    public function laVeElAlumno(): bool
    {
        return $this->estaArchivada() && $this->visible_alumnos;
    }

    public function scopeParaArchivar(Builder $query): Builder
    {
        return $query->whereIn('estado', [self::PENDIENTE, self::FALLIDA]);
    }

    /** Legible: «1.2 GB». El tamaño decide si vale la pena abrirla en datos. */
    public function pesoLegible(): ?string
    {
        if ($this->bytes === null) {
            return null;
        }

        if ($this->bytes < 1024 * 1024) {
            return round($this->bytes / 1024).' KB';
        }

        if ($this->bytes < 1024 * 1024 * 1024) {
            return round($this->bytes / 1024 / 1024).' MB';
        }

        return round($this->bytes / 1024 / 1024 / 1024, 1).' GB';
    }
}
