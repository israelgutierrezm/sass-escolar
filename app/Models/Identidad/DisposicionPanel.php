<?php

declare(strict_types=1);

namespace App\Models\Identidad;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * disposicion_panel (TENANT) — dónde y de qué tamaño puso cada quien sus
 * tarjetas, para cada perfil con el que opera.
 *
 * Sin `TieneAuditoria` a propósito: se reemplaza entera en cada guardado y con
 * borrado lógico dejaría una fila muerta por arrastre. La razón larga está en la
 * migración.
 */
class DisposicionPanel extends Model
{
    protected $table = 'disposicion_panel';

    protected $fillable = ['usuario_id', 'rol_id', 'clave', 'orden', 'ancho'];

    protected function casts(): array
    {
        return ['orden' => 'integer', 'ancho' => 'integer'];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    public function rol(): BelongsTo
    {
        return $this->belongsTo(Rol::class, 'rol_id');
    }
}
