<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;

/**
 * Convención de auditoría para modelos de la capa TENANT.
 *
 * Aporta dos cosas a cualquier modelo que lo use:
 *  1. Borrado lógico (soft delete) vía la columna `deleted_at`.
 *  2. Autollenado de `created_by` / `updated_by` con el id del usuario
 *     autenticado, mediante los eventos `creating` y `updating`.
 *
 * El esquema de estas columnas lo aporta la macro `Blueprint::auditoria()`
 * (registrada en AppServiceProvider). Todo modelo TENANT debería usar este
 * trait; las tablas LANDLORD no llevan auditoría por convención de la spec.
 *
 * En contexto sin sesión (seeders, jobs de consola) `Auth::id()` es null y las
 * columnas de autor quedan en NULL, lo cual la spec permite.
 */
trait TieneAuditoria
{
    use SoftDeletes;

    protected static function bootTieneAuditoria(): void
    {
        static::creating(function ($model): void {
            if (($id = Auth::id()) !== null) {
                $model->created_by ??= $id;
                $model->updated_by ??= $id;
            }
        });

        static::updating(function ($model): void {
            if (($id = Auth::id()) !== null) {
                $model->updated_by = $id;
            }
        });
    }
}
