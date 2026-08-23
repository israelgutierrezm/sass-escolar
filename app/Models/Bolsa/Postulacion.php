<?php

declare(strict_types=1);

namespace App\Models\Bolsa;

use App\Models\Admisiones\MatriculaOferta;
use App\Models\Concerns\TieneAuditoria;
use App\Models\Identidad\Persona;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * postulaciones (TENANT) — alguien aplicó a una vacante.
 *
 * `capturada_por` en null significa que se postuló SOLA desde su portal; con
 * valor, que vinculación la registró en ventanilla. Así se sabe además quién la
 * capturó, que es lo que se pregunta cuando algo sale mal.
 */
class Postulacion extends Model
{
    use TieneAuditoria;

    protected $table = 'postulaciones';

    protected $fillable = [
        'vacante_id',
        'persona_id',
        'matricula_oferta_id',
        'cv_ruta',
        'carta_presentacion',
        'etapa_id',
        'fecha_postulacion',
        'capturada_por',
    ];

    protected function casts(): array
    {
        return ['fecha_postulacion' => 'datetime'];
    }

    public function vacante(): BelongsTo
    {
        return $this->belongsTo(Vacante::class, 'vacante_id');
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'persona_id');
    }

    public function matricula(): BelongsTo
    {
        return $this->belongsTo(MatriculaOferta::class, 'matricula_oferta_id');
    }

    public function etapa(): BelongsTo
    {
        return $this->belongsTo(EtapaPostulacion::class, 'etapa_id');
    }

    public function bitacora(): HasMany
    {
        return $this->hasMany(PostulacionBitacora::class, 'postulacion_id');
    }

    /** ¿Llegó por el portal o por el mostrador? */
    public function esAutogestiva(): bool
    {
        return $this->capturada_por === null;
    }
}
