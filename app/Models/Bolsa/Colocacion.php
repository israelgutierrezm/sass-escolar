<?php

declare(strict_types=1);

namespace App\Models\Bolsa;

use App\Models\Admisiones\MatriculaOferta;
use App\Models\Concerns\TieneAuditoria;
use App\Models\Identidad\Persona;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * colocaciones (TENANT) — a alguien lo contrataron.
 *
 * Es el hecho que el módulo existe para producir: el indicador de empleabilidad
 * por programa académico y por generación, que es lo que piden las acreditadoras.
 *
 * Con `postulacion_id` en null, la colocación NO salió de la bolsa: la escuela
 * se enteró dándole seguimiento al egresado. Contar sólo las que vienen de una
 * postulación mediría el trabajo de la oficina de vinculación, no el destino de
 * los egresados.
 */
class Colocacion extends Model
{
    use TieneAuditoria;

    protected $table = 'colocaciones';

    protected $fillable = [
        'postulacion_id',
        'persona_id',
        'matricula_oferta_id',
        'empresa_id',
        'puesto',
        'salario',
        'fecha_ingreso',
        'relacionado_con_programa_academico',
        'notas',
    ];

    protected function casts(): array
    {
        return [
            'fecha_ingreso' => 'date',
            'salario' => 'decimal:2',
            'relacionado_con_programa_academico' => 'boolean',
        ];
    }

    public function postulacion(): BelongsTo
    {
        return $this->belongsTo(Postulacion::class, 'postulacion_id');
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'persona_id');
    }

    public function matricula(): BelongsTo
    {
        return $this->belongsTo(MatriculaOferta::class, 'matricula_oferta_id');
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'empresa_id');
    }

    /** ¿Llegó por la bolsa o por seguimiento de egresados? */
    public function salioDeLaBolsa(): bool
    {
        return $this->postulacion_id !== null;
    }
}
