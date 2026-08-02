<?php

declare(strict_types=1);

namespace App\Models\Plataforma;

use App\Enums\DestinoEvento;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * evento_destinos (TENANT) — a quién alcanza un evento del calendario.
 *
 * Una fila por público. Se suman entre sí: basta encajar en una para ver el
 * evento. Sin soft delete a propósito —quitar un destino es corregir a quién le
 * llega un aviso, no un hecho que haya que conservar—.
 */
class EventoDestino extends Model
{
    protected $table = 'evento_destinos';

    protected $fillable = ['evento_id', 'tipo', 'destino_id'];

    protected function casts(): array
    {
        return [
            'tipo' => DestinoEvento::class,
            'destino_id' => 'integer',
        ];
    }

    public function evento(): BelongsTo
    {
        return $this->belongsTo(EventoCalendario::class, 'evento_id');
    }
}
