<?php

declare(strict_types=1);

namespace App\Models\Plataforma;

use App\Enums\DestinoEvento;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * avisos_destinos (TENANT) — a quién alcanza un aviso.
 *
 * Gemela de `evento_destinos` y con el MISMO enum (`DestinoEvento`): todos,
 * rol, campus, nivel, programa académico, plan, grupo, materia o alumno. Se copia la forma
 * y no se comparte la tabla —ver la migración—.
 *
 * Varios renglones SUMAN: «rol docente» + «campus norte» alcanza a los docentes
 * y a todo el campus norte, no a los docentes DEL campus norte. Cruzarlos sería
 * otra cosa y se captura de otra forma.
 */
class AvisoDestino extends Model
{
    protected $table = 'avisos_destinos';

    protected $fillable = ['aviso_id', 'tipo', 'destino_id'];

    protected function casts(): array
    {
        return ['tipo' => DestinoEvento::class];
    }

    public function aviso(): BelongsTo
    {
        return $this->belongsTo(Aviso::class, 'aviso_id');
    }
}
