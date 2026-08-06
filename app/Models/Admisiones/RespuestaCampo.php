<?php

declare(strict_types=1);

namespace App\Models\Admisiones;

use App\Models\Concerns\TieneAuditoria;
use App\Models\Formularios\CampoFormulario;
use App\Models\Identidad\Persona;
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * Las respuestas de un titular, sea cual sea.
     *
     * Vive aquí y no repetida en cada consulta porque la regla es sutil: para
     * una PERSONA no basta con `persona_id`, hay que exigir además que las dos
     * columnas de capacidad estén vacías. Sin eso, el expediente de un docente
     * arrastraría las respuestas que esa misma persona dio siendo aspirante o
     * alumno —son suyas, pero contestadas en otra calidad y a otras preguntas—.
     */
    public function scopeParaTitular(Builder $query, Aspirante|MatriculaOferta|Persona $titular): Builder
    {
        return match (true) {
            $titular instanceof Aspirante => $query->where('aspirante_id', $titular->id),
            $titular instanceof MatriculaOferta => $query->where('matricula_oferta_id', $titular->id),
            default => $query->where('persona_id', $titular->id)
                ->whereNull('aspirante_id')
                ->whereNull('matricula_oferta_id'),
        };
    }

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
