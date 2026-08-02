<?php

declare(strict_types=1);

namespace App\Models\Lms;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * examenes (TENANT) — las reglas de aplicación de una actividad de tipo examen.
 *
 * Las fechas, la ponderación y el amarre al parcial viven en la ACTIVIDAD: un
 * examen es una actividad más, y duplicar aquí su fecha de cierre sería crear
 * dos verdades que tarde o temprano se contradicen. Aquí solo va lo que es
 * propio de examinar: intentos, reloj, sorteo y cuándo se revela el resultado.
 */
class Examen extends Model
{
    use SoftDeletes;
    use TieneAuditoria;

    /** Con qué intento se queda la calificación. */
    public const CUENTA_MEJOR = 'mejor';

    public const CUENTA_ULTIMO = 'ultimo';

    public const CUENTA_PRIMERO = 'primero';

    /** Cuándo ve el alumno su resultado. */
    public const RESULTADO_NUNCA = 'nunca';

    public const RESULTADO_AL_ENTREGAR = 'al_entregar';

    public const RESULTADO_AL_CERRAR = 'al_cerrar';

    protected $table = 'examenes';

    protected $fillable = [
        'actividad_id',
        'intentos_permitidos',
        'minutos_limite',
        'reactivos_a_presentar',
        'barajar_reactivos',
        'barajar_opciones',
        'una_por_pagina',
        'intento_que_cuenta',
        'mostrar_resultado',
    ];

    protected function casts(): array
    {
        return [
            'intentos_permitidos' => 'integer',
            'minutos_limite' => 'integer',
            'reactivos_a_presentar' => 'integer',
            'barajar_reactivos' => 'boolean',
            'barajar_opciones' => 'boolean',
            'una_por_pagina' => 'boolean',
        ];
    }

    public function actividad(): BelongsTo
    {
        return $this->belongsTo(Actividad::class, 'actividad_id');
    }

    /** Los reactivos que arma este examen, con su peso y orden propios. */
    public function reactivos(): BelongsToMany
    {
        return $this->belongsToMany(Reactivo::class, 'examen_reactivo')
            ->withPivot(['puntos', 'orden'])
            ->orderBy('examen_reactivo.orden');
    }

    public function intentos(): HasMany
    {
        return $this->hasMany(Intento::class, 'examen_id');
    }

    /** @var array<int, float>|null Puntos por reactivo, resueltos una sola vez. */
    private ?array $pesos = null;

    /**
     * Cuánto vale un reactivo DENTRO de este examen.
     *
     * El pivote manda sobre el valor del banco: la misma pregunta puede pesar
     * distinto en un parcial que en un extraordinario.
     *
     * Se consulta la tabla pivote en lugar de leer `$reactivo->pivot` porque ese
     * atributo solo existe cuando el reactivo se cargó a través de la relación.
     * Depender de cómo venga cargado el modelo hace que el mismo método devuelva
     * cosas distintas según quién lo llame, y el que se equivoca cae al valor del
     * banco sin avisar: el examen entero se califica sobre una escala que nadie
     * configuró.
     */
    public function puntosDe(Reactivo $reactivo): float
    {
        $this->pesos ??= DB::table('examen_reactivo')
            ->where('examen_id', $this->id)
            ->pluck('puntos', 'reactivo_id')
            ->filter(fn ($p) => $p !== null)
            ->map(fn ($p) => (float) $p)
            ->all();

        return $this->pesos[$reactivo->id] ?? (float) $reactivo->puntos;
    }

    /** Si el examen se cierra solo o queda esperando al docente. */
    public function seCalificaSolo(): bool
    {
        return $this->reactivos->every(fn (Reactivo $r) => $r->tipo->autocalificable());
    }

    /**
     * Si el alumno todavía puede abrir otro intento.
     *
     * Los intentos ya iniciados cuentan aunque no se hayan entregado: abandonar
     * uno a medias no puede regalar otro, o el límite no limitaría nada.
     */
    public function permiteOtroIntento(int $intentosUsados): bool
    {
        return $intentosUsados < $this->intentos_permitidos;
    }
}
