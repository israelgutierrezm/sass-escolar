<?php

declare(strict_types=1);

namespace App\Models\Movilidad;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * estancias (TENANT) — el periodo efectivo del intercambio.
 *
 * Una por postulación. Dos serían el mismo hecho contado dos veces y la
 * revalidación no sabría de cuál cuelga.
 *
 * La institución NO se repite aquí: sale por
 * postulación → convocatoria → convenio → institución. Copiarla crearía la
 * posibilidad de que difieran, y entonces habría que decidir a cuál creerle.
 */
class Estancia extends Model
{
    use TieneAuditoria;

    protected $table = 'estancias';

    protected $fillable = ['postulacion_id', 'fecha_inicio', 'fecha_fin', 'concluida_en', 'notas'];

    protected function casts(): array
    {
        return [
            'fecha_inicio' => 'date',
            'fecha_fin' => 'date',
            'concluida_en' => 'date',
        ];
    }

    public function postulacion(): BelongsTo
    {
        return $this->belongsTo(PostulacionMovilidad::class, 'postulacion_id');
    }

    public function estaConcluida(): bool
    {
        return $this->concluida_en !== null;
    }

    /** La institución donde estuvo, sin duplicar el dato. */
    public function institucion(): ?InstitucionAliada
    {
        return $this->postulacion?->convocatoria?->convenio?->institucion;
    }
}
