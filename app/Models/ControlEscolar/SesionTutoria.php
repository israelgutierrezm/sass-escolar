<?php

declare(strict_types=1);

namespace App\Models\ControlEscolar;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * sesiones_tutoria (TENANT) — una sesión anotada por el tutor educativo.
 *
 * Cuelga de la TUTORÍA y no del alumno: así lo anotado por un tutor sigue
 * siendo suyo cuando el alumno cambia de tutor.
 */
class SesionTutoria extends Model
{
    use TieneAuditoria;

    protected $table = 'sesiones_tutoria';

    /**
     * Por qué se vieron.
     *
     * Se guarda la CLAVE y no el texto para poder contar: «este semestre, la
     * mitad de mis sesiones fueron por bajo rendimiento» es la clase de dato
     * que justifica que exista una tutoría.
     */
    public const MOTIVOS = [
        'seguimiento' => 'Seguimiento general',
        'bajo_rendimiento' => 'Bajo rendimiento',
        'inasistencias' => 'Inasistencias',
        'personal' => 'Situación personal',
        'orientacion' => 'Orientación vocacional',
        'tramite' => 'Trámite escolar',
    ];

    public const MODALIDADES = [
        'presencial' => 'Presencial',
        'linea' => 'En línea',
        'telefonica' => 'Telefónica',
    ];

    protected $fillable = [
        'tutoria_id',
        'fecha',
        'modalidad',
        'motivo',
        'tema',
        'acuerdos',
        'asistio',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'asistio' => 'boolean',
        ];
    }

    public function tutoria(): BelongsTo
    {
        return $this->belongsTo(Tutoria::class, 'tutoria_id');
    }
}
