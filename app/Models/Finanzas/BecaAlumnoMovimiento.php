<?php

declare(strict_types=1);

namespace App\Models\Finanzas;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * beca_alumno_movimientos (TENANT) — bitácora de una beca otorgada.
 *
 * Log append-only: una beca que se suspende o se pierde no borra su historia,
 * agrega el renglón que la explica. Es lo que permite responder después por qué
 * a un alumno se le cobró completo un mes.
 */
class BecaAlumnoMovimiento extends Model
{
    public const UPDATED_AT = null;

    public const OTORGADA = 'otorgada';

    public const RENOVADA = 'renovada';

    public const SUSPENDIDA = 'suspendida';

    public const REACTIVADA = 'reactivada';

    public const PERDIDA = 'perdida';

    public const NO_RENOVADA = 'no_renovada';

    public const CANCELADA = 'cancelada';

    protected $table = 'beca_alumno_movimientos';

    protected $fillable = [
        'beca_alumno_id',
        'accion',
        'detalle',
        'realizado_por',
        'realizado_por_nombre',
    ];

    public function becaAlumno(): BelongsTo
    {
        return $this->belongsTo(BecaAlumno::class, 'beca_alumno_id');
    }
}
