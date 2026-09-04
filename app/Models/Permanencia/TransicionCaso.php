<?php

declare(strict_types=1);

namespace App\Models\Permanencia;

use App\Models\Identidad\Usuario;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * transiciones_caso (TENANT) — quién movió el caso y cuándo.
 *
 * ── Inmutable y sin borrado lógico ─────────────────────────────────────────
 * Una bitácora que se puede borrar no es una bitácora. Y sin `TieneAuditoria`:
 * la fila ES la auditoría, así que `created_by` duplicaría a `quien` y
 * `updated_by` no cambiaría nunca.
 *
 * El primer renglón lleva `estado_origen` en NULL: es la apertura, y sin él
 * «cuánto tarda un caso en asignarse» no tendría desde cuándo contar. Es la
 * misma lección que dejó la bitácora de postulaciones de la bolsa.
 */
class TransicionCaso extends Model
{
    protected $table = 'transiciones_caso';

    protected $fillable = ['caso_id', 'estado_origen', 'estado_destino', 'motivo', 'quien', 'ip', 'momento'];

    protected function casts(): array
    {
        return [
            'momento' => 'datetime',
            // Casteados al enum para que la pantalla lea la ETIQUETA y no la
            // clave cruda: «contacto_pendiente» con su guión bajo en un
            // expediente se lee como un volcado de base de datos.
            'estado_origen' => EstadoCaso::class,
            'estado_destino' => EstadoCaso::class,
        ];
    }

    public function caso(): BelongsTo
    {
        return $this->belongsTo(CasoPermanencia::class, 'caso_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'quien');
    }
}
