<?php

declare(strict_types=1);

namespace App\Models\Reportes;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A quién le llega un reporte programado.
 *
 * ── Dos tipos, y ninguno es una dirección suelta ─────────────────────────
 * `persona` (alguien concreto de la escuela) y `rol` (todos los que lo tengan
 * activo). El plan preveía un tercero, `correo`, para el contador externo sin
 * cuenta; no se construyó y el porqué está en la migración: o recibe un enlace
 * que no puede abrir —y quien lo configure creerá que su contador lo recibe— o
 * recibe el adjunto, y entonces un padrón con CURP sale todos los lunes a una
 * dirección que la escuela no controla.
 *
 * ── `destino_id` sin foránea ─────────────────────────────────────────────
 * Apunta a `personas` o a `roles` según el tipo, igual que `evento_destinos`.
 * Es lo que permitirá agregar «por campus» sin migrar; a cambio, lo que apunte a
 * algo borrado se enseña como «Ya no existe».
 */
class DestinatarioReporte extends Model
{
    use TieneAuditoria;

    protected $table = 'destinatarios_reporte';

    public const PERSONA = 'persona';

    public const ROL = 'rol';

    protected $fillable = ['programacion_id', 'tipo', 'destino_id'];

    protected function casts(): array
    {
        return ['destino_id' => 'integer'];
    }

    public function programacion(): BelongsTo
    {
        return $this->belongsTo(ProgramacionReporte::class, 'programacion_id');
    }
}
