<?php

declare(strict_types=1);

namespace App\Models\Nomina;

use App\Models\Academico\Campus;
use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * adscripciones (TENANT) — qué puesto ocupa un expediente, dónde y desde cuándo.
 *
 * ── No duplica `persona_rol.campus_id` ────────────────────────────────────
 * Aquél acota lo que un usuario PUEDE VER; ésta dice qué puesto ocupa en el
 * organigrama, con su historia. Alguien puede tener permisos globales y estar
 * adscrito a un solo campus, y al revés.
 *
 * ── Se cierra, no se borra ────────────────────────────────────────────────
 * Un cambio de puesto pone `vigente_hasta` a la vieja y abre otra. Borrar la
 * anterior perdería desde cuándo ocupó cada cosa, que es la mitad de para qué
 * existe esta tabla.
 */
class Adscripcion extends Model
{
    use TieneAuditoria;

    protected $table = 'adscripciones';

    protected $fillable = [
        'expediente_laboral_id',
        'puesto_id',
        'campus_id',
        'vigente_desde',
        'vigente_hasta',
        'es_principal',
    ];

    protected function casts(): array
    {
        return [
            'vigente_desde' => 'date',
            'vigente_hasta' => 'date',
            'es_principal' => 'boolean',
        ];
    }

    public function expediente(): BelongsTo
    {
        return $this->belongsTo(ExpedienteLaboral::class, 'expediente_laboral_id');
    }

    public function puesto(): BelongsTo
    {
        return $this->belongsTo(Puesto::class, 'puesto_id');
    }

    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class, 'campus_id');
    }

    /** Sin fecha de fin, o con una que todavía no llega. */
    public function estaVigente(): bool
    {
        return $this->vigente_hasta === null || ! $this->vigente_hasta->lt(now()->startOfDay());
    }

    public function scopeVigentes(Builder $consulta): Builder
    {
        return $consulta->where(fn (Builder $q) => $q
            ->whereNull('vigente_hasta')
            ->orWhereDate('vigente_hasta', '>=', now()->toDateString()));
    }

    /**
     * «SU adscripción»: la que cubría el día que se fue —o el de hoy, si sigue.
     *
     * ── Por qué la fecha de corte no es siempre «hoy» ─────────────────────
     * `darDeBaja` cierra las adscripciones abiertas con la fecha de la baja, así
     * que a quien ya se fue NINGUNA le sigue vigente. Preguntar por «hoy» —que
     * es lo que hace `scopeVigentes`— deja al personal dado de baja sin puesto y
     * sin campus, y entonces el reporte de rotación no puede decir de qué
     * plantel se fue nadie, que es para lo que existe.
     *
     * Es el mismo criterio que `ExpedienteLaboral::esquemaEn` usa para el
     * sueldo: se resuelve A UNA FECHA, y la fecha de un expediente cerrado es
     * la de su cierre.
     *
     * ── Y por qué es una sola definición ──────────────────────────────────
     * Estuvo escrita TRES veces —el recorte por campus, los filtros de campus y
     * puesto, y la subconsulta que pinta las columnas— y las tres divergieron:
     *
     *  - El recorte y los filtros no miraban la vigencia, así que casaban contra
     *    adscripciones ya cerradas: el coordinador de un plantel veía el
     *    expediente de quien HOY trabaja en otro, y la propia columna «Campus»
     *    de esa fila se lo decía. Filtrar por «Campus Centro» devolvía filas que
     *    se contradecían a sí mismas.
     *  - La subconsulta sí la miraba, pero contra `curdate()`, y por eso las
     *    bajas salían con puesto y campus en blanco.
     *
     * Las tres nombran ahora `ExpedienteLaboral::adscripcionesQueCuentan()`, o
     * esta condición cuando el consumidor es SQL crudo y no puede usar la
     * relación.
     *
     * @param  string  $alias  cómo se llama `adscripciones` en la consulta
     * @param  string  $expediente  cómo se llama `expedientes_laborales`
     */
    public static function laQueCuenta(
        string $alias = 'adscripciones',
        string $expediente = 'expedientes_laborales',
    ): string {
        $corte = "coalesce($expediente.fecha_baja, curdate())";

        return "$alias.vigente_desde <= $corte "
            ."and ($alias.vigente_hasta is null or $alias.vigente_hasta >= $corte)";
    }
}
