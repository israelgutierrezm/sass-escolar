<?php

declare(strict_types=1);

namespace App\Models\Lms;

use App\Models\Concerns\TieneAuditoria;
use App\Models\Identidad\Usuario;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * imagenes_contenido (TENANT) — una imagen pegada dentro del material.
 *
 * El `uuid` es lo que va en la URL y por tanto lo que queda escrito dentro del
 * HTML de la lección: no se cambia nunca. La `ruta` sí puede moverse el día que
 * el disco cambie, y por eso son dos columnas y no una.
 */
class ImagenContenido extends Model
{
    use SoftDeletes;
    use TieneAuditoria;

    protected $table = 'imagenes_contenido';

    protected $fillable = [
        'uuid',
        'ruta',
        'nombre_original',
        'mime',
        'tamano',
        'subida_por',
    ];

    protected static function booted(): void
    {
        // El uuid nace con la fila: dejarlo al que la crea abre la puerta a
        // guardar una sin él, y esa quedaría inalcanzable por la URL.
        static::creating(function (self $imagen) {
            $imagen->uuid ??= (string) Str::uuid();
        });
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'subida_por');
    }

    /** La dirección que se escribe dentro del HTML de la lección. */
    public function url(): string
    {
        return "/lms/imagenes/{$this->uuid}";
    }
}
