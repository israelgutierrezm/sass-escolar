<?php

declare(strict_types=1);

namespace App\Models\Lms;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * portafolio_archivos (TENANT) — los archivos de una evidencia.
 *
 * Varios por evidencia: la foto del montaje, el video del ensayo y el PDF del
 * reporte documentan UNA sola cosa. Y una evidencia puede no tener ninguno —una
 * reflexión escrita es evidencia legítima—, que es por qué no son una columna.
 *
 * Van al disco PRIVADO, como el resto de lo que sube un alumno: son trabajos
 * escolares con su nombre encima.
 */
class PortafolioArchivo extends Model
{
    use TieneAuditoria;

    protected $table = 'portafolio_archivos';

    protected $fillable = ['evidencia_id', 'ruta', 'nombre', 'bytes', 'mime'];

    protected function casts(): array
    {
        return ['bytes' => 'integer'];
    }

    public function evidencia(): BelongsTo
    {
        return $this->belongsTo(PortafolioEvidencia::class, 'evidencia_id');
    }

    /** Legible: «1.2 GB». Mismo criterio que `Grabacion::pesoLegible`. */
    public function pesoLegible(): ?string
    {
        if ($this->bytes === null) {
            return null;
        }

        if ($this->bytes < 1024 * 1024) {
            return round($this->bytes / 1024).' KB';
        }

        if ($this->bytes < 1024 * 1024 * 1024) {
            return round($this->bytes / 1024 / 1024).' MB';
        }

        return round($this->bytes / 1024 / 1024 / 1024, 1).' GB';
    }
}
