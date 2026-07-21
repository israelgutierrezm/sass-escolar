<?php

declare(strict_types=1);

namespace App\Models\Plataforma;

use Illuminate\Database\Eloquent\Model;

/**
 * auditoria (TENANT) — bitácora transversal append-only.
 *
 * No usa el trait TieneAuditoria (no se audita a sí misma) y solo tiene
 * created_at: se desactiva updated_at con la constante UPDATED_AT = null.
 * Los valores anteriores/nuevos se guardan como JSON (único uso justificado).
 */
class Auditoria extends Model
{
    protected $table = 'auditoria';

    const UPDATED_AT = null;

    protected $fillable = [
        'auditable_type',
        'auditable_id',
        'evento',
        'valores_anteriores',
        'valores_nuevos',
        'usuario_id',
        'ip',
    ];

    protected function casts(): array
    {
        return [
            'valores_anteriores' => 'array',
            'valores_nuevos' => 'array',
        ];
    }
}
