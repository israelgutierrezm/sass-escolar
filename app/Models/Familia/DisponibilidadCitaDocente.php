<?php

declare(strict_types=1);

namespace App\Models\Familia;

use App\Models\Concerns\TieneAuditoria;
use App\Models\Identidad\Persona;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * disponibilidad_cita_docente (TENANT) — las ventanas de atención a padres que
 * el docente ofrece. Ver `docs/plan-citas-familia-docente.md`.
 *
 * SEPARADA de `disponibilidad_docente` (esa es cuándo puede DAR CLASE): una cita
 * con un padre ocurre justo cuando el docente NO está en aula. Es semanal y
 * habitual —las horas de atención no cambian cada ciclo—.
 */
class DisponibilidadCitaDocente extends Model
{
    use TieneAuditoria;

    protected $table = 'disponibilidad_cita_docente';

    public const PRESENCIAL = 'presencial';

    public const EN_LINEA = 'en_linea';

    public const TELEFONICA = 'telefonica';

    public const MODALIDADES = [
        self::PRESENCIAL => 'Presencial',
        self::EN_LINEA => 'En línea',
        self::TELEFONICA => 'Telefónica',
    ];

    protected $fillable = [
        'persona_id',
        'dia_semana',
        'hora_inicio',
        'hora_fin',
        'modalidad',
        'duracion_min',
        'lugar',
    ];

    protected function casts(): array
    {
        return [
            'dia_semana' => 'integer',
            'duracion_min' => 'integer',
        ];
    }

    public function docente(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'persona_id');
    }

    /** Minutos desde la medianoche, para comparar sin pelearse con los formatos. */
    public function inicioEnMinutos(): int
    {
        return self::aMinutos((string) $this->hora_inicio);
    }

    public function finEnMinutos(): int
    {
        return self::aMinutos((string) $this->hora_fin);
    }

    public static function aMinutos(string $hora): int
    {
        [$h, $m] = array_pad(array_map('intval', explode(':', $hora)), 2, 0);

        return $h * 60 + $m;
    }
}
