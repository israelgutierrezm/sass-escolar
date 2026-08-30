<?php

declare(strict_types=1);

namespace App\Models\Finanzas;

use App\Models\Academico\NivelEstudio;
use App\Models\Academico\ProgramaAcademico;
use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * emisor_asignaciones (TENANT) — qué factura cada razón social.
 *
 * Es pivote y no una columna del emisor porque una misma persona moral cubre
 * varias cosas a la vez: todo el nivel de licenciatura Y además una maestría
 * concreta. Con una columna habría que dar de alta el mismo RFC tres veces.
 */
class EmisorAsignacion extends Model
{
    use TieneAuditoria;

    /** Toda la escuela. Es el respaldo cuando no hay nada más específico. */
    public const APLICA_GLOBAL = 'global';

    /** Un nivel de estudios completo: bachillerato, licenciatura, posgrado. */
    public const APLICA_NIVEL = 'nivel';

    /** Un programa académico concreta. Es lo más específico y por tanto lo que gana. */
    public const APLICA_CARRERA = 'programa_academico';

    protected $table = 'emisor_asignaciones';

    protected $fillable = ['emisor_id', 'aplica_a_tipo', 'aplica_a_id'];

    public function emisor(): BelongsTo
    {
        return $this->belongsTo(EmisorFiscal::class, 'emisor_id');
    }

    /**
     * A qué apunta. Se resuelve a mano y no con `morphTo` porque `aplica_a_id`
     * no tiene FK —apunta a `programas_academicos` del tenant o a `niveles_estudio` de la
     * landlord— y porque `aplica_a_tipo` guarda un tipo de dominio ('nivel')
     * que sobrevive a un cambio de namespace.
     */
    public function destinatario(): ?Model
    {
        if ($this->aplica_a_id === null) {
            return null;
        }

        return match ($this->aplica_a_tipo) {
            self::APLICA_CARRERA => ProgramaAcademico::find($this->aplica_a_id),
            self::APLICA_NIVEL => NivelEstudio::find($this->aplica_a_id),
            default => null,
        };
    }

    public function nombreDelDestinatario(): string
    {
        if ($this->aplica_a_tipo === self::APLICA_GLOBAL) {
            return 'Toda la escuela';
        }

        return $this->destinatario()?->nombre ?? 'No encontrado (#'.$this->aplica_a_id.')';
    }
}
