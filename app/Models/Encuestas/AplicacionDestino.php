<?php

declare(strict_types=1);

namespace App\Models\Encuestas;

use App\Enums\DestinoEvento;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * aplicacion_destinos (TENANT) — a quién le llega la encuesta.
 *
 * Mismos criterios que los avisos y el calendario, con el mismo enum y el mismo
 * resolutor (`AlcanceDeDestinos`): dirigir algo «a los de tercero de Derecho»
 * tiene que significar lo mismo en todo el sistema.
 */
class AplicacionDestino extends Model
{
    protected $table = 'aplicacion_destinos';

    protected $fillable = ['aplicacion_id', 'tipo', 'destino_id'];

    protected function casts(): array
    {
        return ['tipo' => DestinoEvento::class];
    }

    public function aplicacion(): BelongsTo
    {
        return $this->belongsTo(AplicacionEncuesta::class, 'aplicacion_id');
    }
}
