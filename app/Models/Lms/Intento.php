<?php

declare(strict_types=1);

namespace App\Models\Lms;

use App\Models\Academico\PlanEstudio;
use App\Models\Concerns\TieneAuditoria;
use App\Models\ControlEscolar\Inscripcion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * intentos (TENANT) — una vez que un alumno presentó un examen.
 *
 * Cada intento es su propia fila y conserva lo que contestó ESA vez, junto con
 * el orden en que se le presentaron los reactivos. Guardar solo el mejor
 * resultado haría imposible atender una inconformidad: no habría manera de
 * reconstruir qué examen vio el alumno ni qué respondió.
 */
class Intento extends Model
{
    use SoftDeletes;
    use TieneAuditoria;

    protected $table = 'intentos';

    protected $fillable = [
        'examen_id',
        'inscripcion_id',
        'entrega_id',
        'numero',
        'iniciado_en',
        'entregado_en',
        'expira_en',
        'puntos_obtenidos',
        'puntos_posibles',
        'requiere_revision',
        'orden_reactivos',
        'capturas_detectadas',
        'capturas',
    ];

    protected function casts(): array
    {
        return [
            'numero' => 'integer',
            'iniciado_en' => 'datetime',
            'entregado_en' => 'datetime',
            'expira_en' => 'datetime',
            'puntos_obtenidos' => 'decimal:2',
            'puntos_posibles' => 'decimal:2',
            'requiere_revision' => 'boolean',
            'orden_reactivos' => 'array',
            'capturas_detectadas' => 'integer',
            'capturas' => 'array',
        ];
    }

    public function examen(): BelongsTo
    {
        return $this->belongsTo(Examen::class, 'examen_id');
    }

    public function inscripcion(): BelongsTo
    {
        return $this->belongsTo(Inscripcion::class, 'inscripcion_id');
    }

    public function entrega(): BelongsTo
    {
        return $this->belongsTo(Entrega::class, 'entrega_id');
    }

    public function respuestas(): HasMany
    {
        return $this->hasMany(Respuesta::class, 'intento_id');
    }

    public function entregado(): bool
    {
        return $this->entregado_en !== null;
    }

    /** Si el reloj ya se le acabó y sigue sin entregar. */
    public function expirado(): bool
    {
        return ! $this->entregado() && $this->expira_en !== null && now()->gt($this->expira_en);
    }

    /**
     * Lo obtenido llevado a la escala con la que califica la escuela.
     *
     * Se toma del plan de la materia en la que está inscrito el alumno, no de
     * un 0–10 fijo: es el mismo número que verá luego en su acta, y enseñarle
     * dos escalas distintas para el mismo examen es enseñarle un error.
     *
     * Null mientras no haya nada que escalar.
     */
    public function enEscala(): ?float
    {
        return PlanEstudio::enEscalaCon(
            $this->inscripcion?->asignaturaGrupo?->planMateria?->plan,
            $this->puntos_obtenidos === null ? null : (float) $this->puntos_obtenidos,
            (float) $this->puntos_posibles,
        );
    }
}
