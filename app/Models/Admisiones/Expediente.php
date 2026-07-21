<?php

declare(strict_types=1);

namespace App\Models\Admisiones;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * expedientes (TENANT) — documentos del alumno ya inscrito, por oferta.
 */
class Expediente extends Model
{
    use TieneAuditoria;

    protected $table = 'expedientes';

    protected $fillable = [
        'matricula_oferta_id',
        'nombre',
        'ruta',
        'laserfiche_entry_id',
        'comentario',
    ];

    public function matriculaOferta(): BelongsTo
    {
        return $this->belongsTo(MatriculaOferta::class, 'matricula_oferta_id');
    }
}
