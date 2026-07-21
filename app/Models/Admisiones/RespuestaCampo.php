<?php

declare(strict_types=1);

namespace App\Models\Admisiones;

use App\Models\Concerns\TieneAuditoria;
use App\Models\Formularios\CampoFormulario;
use App\Models\Identidad\Persona;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * respuestas_campo (TENANT) — una respuesta de formulario por fila. Cierra el
 * motor del Módulo 3.
 */
class RespuestaCampo extends Model
{
    use TieneAuditoria;

    protected $table = 'respuestas_campo';

    protected $fillable = [
        'campo_formulario_id',
        'formulario_version',
        'persona_id',
        'matricula_oferta_id',
        'aspirante_id',
        'valor',
        'documento_ruta',
    ];

    public function campo(): BelongsTo
    {
        return $this->belongsTo(CampoFormulario::class, 'campo_formulario_id');
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class);
    }

    public function matriculaOferta(): BelongsTo
    {
        return $this->belongsTo(MatriculaOferta::class, 'matricula_oferta_id');
    }

    public function aspirante(): BelongsTo
    {
        return $this->belongsTo(Aspirante::class);
    }
}
