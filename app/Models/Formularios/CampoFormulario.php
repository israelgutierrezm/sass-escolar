<?php

declare(strict_types=1);

namespace App\Models\Formularios;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * campos_formulario (TENANT) — una pregunta por fila. `padre`/`hijos` modelan
 * los campos condicionales.
 */
class CampoFormulario extends Model
{
    use TieneAuditoria;

    protected $table = 'campos_formulario';

    protected $fillable = [
        'formulario_id',
        'tipo_campo_id',
        'pregunta',
        'descripcion',
        'obligatorio',
        'regex',
        'mensaje_error',
        'orden',
        'campo_padre_id',
        'condicional',
        'min',
        'max',
        'promueve_a',
    ];

    protected function casts(): array
    {
        return [
            'obligatorio' => 'boolean',
            'min' => 'decimal:2',
            'max' => 'decimal:2',
        ];
    }

    public function formulario(): BelongsTo
    {
        return $this->belongsTo(Formulario::class);
    }

    public function tipoCampo(): BelongsTo
    {
        return $this->belongsTo(TipoCampo::class);
    }

    public function opciones(): HasMany
    {
        return $this->hasMany(OpcionCampo::class, 'campo_formulario_id')->orderBy('orden');
    }

    /** El campo del que depende (condicional). */
    public function padre(): BelongsTo
    {
        return $this->belongsTo(self::class, 'campo_padre_id');
    }

    /** Campos que se muestran según la respuesta de este. */
    public function hijos(): HasMany
    {
        return $this->hasMany(self::class, 'campo_padre_id');
    }
}
