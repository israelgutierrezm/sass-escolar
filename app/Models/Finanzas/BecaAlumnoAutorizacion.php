<?php

declare(strict_types=1);

namespace App\Models\Finanzas;

use App\Models\Concerns\TieneAuditoria;
use App\Models\Identidad\Usuario;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * beca_alumno_autorizaciones (TENANT) — una firma pendiente o dada.
 *
 * La fila nace VACÍA al otorgar la beca. Es lo que permite decir qué falta —«la
 * dirección todavía no firma»— en vez de sólo cuántas firmas hay: con filas
 * creadas al firmar, una beca sin autorizar y una que no requería ninguna se
 * verían igual.
 */
class BecaAlumnoAutorizacion extends Model
{
    use TieneAuditoria;

    protected $table = 'beca_alumno_autorizaciones';

    protected $fillable = ['beca_alumno_id', 'nivel_id', 'usuario_id', 'autorizada_en', 'motivo'];

    protected function casts(): array
    {
        return ['autorizada_en' => 'datetime'];
    }

    public function becaAlumno(): BelongsTo
    {
        return $this->belongsTo(BecaAlumno::class, 'beca_alumno_id');
    }

    public function nivel(): BelongsTo
    {
        return $this->belongsTo(NivelAutorizacionBeca::class, 'nivel_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    public function estaFirmada(): bool
    {
        return $this->autorizada_en !== null;
    }
}
