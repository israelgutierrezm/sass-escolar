<?php

declare(strict_types=1);

namespace App\Models\Permanencia;

use App\Models\Admisiones\MatriculaOferta;
use App\Models\Concerns\TieneAuditoria;
use App\Models\Identidad\Usuario;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * exclusiones_regla_alerta (TENANT) — a quién NO se le aplica, aunque le alcance.
 *
 * ── Por qué hace falta, si ya se puede descartar la alerta ─────────────────
 * Porque descartar una alerta es un acto sobre UNA alerta: mañana el motor
 * vuelve a evaluar y nace otra. Un alumno con una licencia médica autorizada
 * aparecería en la cola cada lunes para que alguien lo descarte otra vez, y a
 * la tercera semana quien revisa la cola deja de leerla.
 *
 * ── Y es un ACTO con dueño, no una casilla ─────────────────────────────────
 * Lleva su motivo obligatorio y quién la autorizó, porque dentro de un año
 * alguien va a preguntar por qué esta persona no aparecía en ningún reporte de
 * riesgo. Guardada como bandera —«excluido = 1»— nadie podría contestarlo. Es
 * el molde de `excepciones_expediente`.
 *
 * ── `regla_id` en NULL significa TODAS ─────────────────────────────────────
 * Es el caso de la licencia médica: no se excluye de la regla de asistencia, se
 * excluye del módulo mientras dure. Con una fila por regla habría que
 * acordarse de agregar la exclusión cada vez que la escuela escriba una regla
 * nueva, y la que se olvide vuelve a poner al alumno en la cola.
 */
class ExclusionReglaAlerta extends Model
{
    use TieneAuditoria;

    protected $table = 'exclusiones_regla_alerta';

    protected $fillable = [
        'regla_id',
        'matricula_oferta_id',
        'motivo',
        'vigente_hasta',
        'autorizada_por',
    ];

    protected function casts(): array
    {
        return ['vigente_hasta' => 'date'];
    }

    public function regla(): BelongsTo
    {
        return $this->belongsTo(ReglaAlerta::class, 'regla_id');
    }

    public function matricula(): BelongsTo
    {
        return $this->belongsTo(MatriculaOferta::class, 'matricula_oferta_id');
    }

    public function autorizadaPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'autorizada_por');
    }

    /**
     * Las que hoy siguen valiendo.
     *
     * Sin `vigente_hasta` no caduca: hay exclusiones que son permanentes —un
     * alumno en un programa que la escuela lleva por fuera—. Con fecha, el día
     * mismo TODAVÍA excluye: `<=` y no `<`, porque «vigente hasta el 30» se lee
     * como que el 30 aún cuenta.
     */
    public function scopeVigentes(Builder $c, ?string $dia = null): Builder
    {
        $fecha = $dia ?? now()->toDateString();

        return $c->where(fn (Builder $q) => $q
            ->whereNull('vigente_hasta')
            ->orWhereDate('vigente_hasta', '>=', $fecha));
    }
}
