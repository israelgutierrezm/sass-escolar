<?php

declare(strict_types=1);

namespace App\Models\Lms;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * entrega_archivos (TENANT) — los archivos de una entrega.
 *
 * Tabla aparte y no una columna en `entregas` porque una tarea admite varios
 * adjuntos, y porque el archivo tiene sus propios datos (nombre original, peso,
 * tipo) que en una columna suelta se pierden.
 *
 * Sin soft delete: quitar un adjunto antes de entregar es corregirse, no un
 * hecho que haya que conservar.
 */
class EntregaArchivo extends Model
{
    protected $table = 'entrega_archivos';

    protected $fillable = ['entrega_id', 'ruta', 'nombre', 'bytes', 'mime'];

    public function entrega(): BelongsTo
    {
        return $this->belongsTo(Entrega::class, 'entrega_id');
    }
}
