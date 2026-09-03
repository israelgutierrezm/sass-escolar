<?php

declare(strict_types=1);

namespace App\Models\ProcesosFormativos;

use App\Models\Academico\Campus;
use App\Models\Academico\NivelEstudio;
use App\Models\Academico\PlanEstudio;
use App\Models\Academico\ProgramaAcademico;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * reglas_proceso (TENANT) — a QUIÉN aplica una regla.
 *
 * El contenido —cuántas horas, qué créditos, qué papeles— vive en sus
 * VERSIONES. Separarlo es lo que permite cambiar el requisito sin volver a
 * declarar el alcance, y conservar lo que decía antes.
 *
 * ── Null significa «cualquiera» ────────────────────────────────────────────
 * Todos los ejes menos el tipo son opcionales. Una regla sin ningún eje es la
 * general de la escuela; una con `plan_id` es la de ese plan. Exigir que se
 * declaren todos obligaría a crear una regla por combinación —cientos— y a
 * mantenerlas.
 */
class ReglaProceso extends Model
{
    use TieneAuditoria;

    /**
     * El peso de cada eje al decidir cuál regla gana.
     *
     * ── Es LEXICOGRÁFICO, no una suma cualquiera ──────────────────────────
     * Cada peso es mayor que la suma de todos los que están debajo
     * (32 > 16+8+4+2+1), así que declarar el PLAN gana siempre sobre cualquier
     * combinación de ejes menos específicos. Es la misma forma de «gana el más
     * específico» que este proyecto ya usaba en el cobro —oferta → plan →
     * programa → global—, sólo que con dos ejes más.
     *
     * El orden no es arbitrario: un plan pertenece a un programa, que pertenece
     * a un nivel. Campus, generación y modalidad cortan por otro lado y por eso
     * van debajo.
     */
    public const PESOS = [
        'plan_id' => 32,
        'programa_academico_id' => 16,
        'nivel_estudios_id' => 8,
        'campus_id' => 4,
        'generacion' => 2,
        'modalidad' => 1,
    ];

    protected $table = 'reglas_proceso';

    protected $attributes = ['activa' => true];

    protected $fillable = [
        'nombre',
        'tipo_proceso_id',
        'campus_id',
        'nivel_estudios_id',
        'programa_academico_id',
        'plan_id',
        'modalidad',
        'generacion_desde',
        'generacion_hasta',
        'activa',
        'notas',
    ];

    protected function casts(): array
    {
        return ['activa' => 'boolean'];
    }

    public function tipoProceso(): BelongsTo
    {
        return $this->belongsTo(TipoProcesoFormativo::class, 'tipo_proceso_id');
    }

    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class, 'campus_id');
    }

    public function nivel(): BelongsTo
    {
        return $this->belongsTo(NivelEstudio::class, 'nivel_estudios_id');
    }

    public function programaAcademico(): BelongsTo
    {
        return $this->belongsTo(ProgramaAcademico::class, 'programa_academico_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(PlanEstudio::class, 'plan_id');
    }

    public function versiones(): HasMany
    {
        return $this->hasMany(ReglaProcesoVersion::class, 'regla_id');
    }

    public function scopeActivas(Builder $c): Builder
    {
        return $c->where('activa', true);
    }

    /**
     * Cuánto de específica es. Más alto, gana.
     *
     * La generación cuenta como UN eje aunque sean dos columnas: «de 2020 a
     * 2024» es una sola condición, y contarla doble la haría pesar más que el
     * campus sin ninguna razón.
     */
    public function especificidad(): int
    {
        $peso = 0;

        foreach (self::PESOS as $eje => $valor) {
            $declarado = $eje === 'generacion'
                ? ($this->generacion_desde !== null || $this->generacion_hasta !== null)
                : $this->{$eje} !== null;

            $peso += $declarado ? $valor : 0;
        }

        return $peso;
    }

    /**
     * ¿Esta regla alcanza a esta matrícula?
     *
     * Cada eje declarado tiene que coincidir; los que están en null no acotan.
     * Es un Y, al revés que los alcances de una organización —que son un O—:
     * aquí la regla describe UN grupo, allá cada fila abre otra puerta.
     */
    public function alcanzaA(MatriculaOferta $matricula): bool
    {
        $oferta = $matricula->oferta;

        if ($oferta === null) {
            return false;
        }

        if ($this->campus_id !== null && $this->campus_id !== $oferta->campus_id) {
            return false;
        }

        if ($this->programa_academico_id !== null && $this->programa_academico_id !== $oferta->programa_academico_id) {
            return false;
        }

        if ($this->plan_id !== null && $this->plan_id !== $oferta->plan_id) {
            return false;
        }

        if ($this->nivel_estudios_id !== null
            && $this->nivel_estudios_id !== $oferta->programaAcademico?->nivel_estudios_id) {
            return false;
        }

        if ($this->modalidad !== null && $this->modalidad !== $oferta->modalidad) {
            return false;
        }

        return $this->cubreLaGeneracion($matricula->generacion);
    }

    /**
     * El rango de generaciones, comparado como TEXTO.
     *
     * Sin generación capturada, una regla que la acote NO alcanza: dar por
     * buena la coincidencia dejaría entrar a quien no sabemos de qué generación
     * es, que es justo lo que el rango existe para separar.
     */
    public function cubreLaGeneracion(?string $generacion): bool
    {
        if ($this->generacion_desde === null && $this->generacion_hasta === null) {
            return true;
        }

        if ($generacion === null || $generacion === '') {
            return false;
        }

        if ($this->generacion_desde !== null && $generacion < $this->generacion_desde) {
            return false;
        }

        return $this->generacion_hasta === null || $generacion <= $this->generacion_hasta;
    }

    /** Cómo se lee su alcance en pantalla. «Cualquiera» donde no acota. */
    public function comoSeLee(): string
    {
        $partes = [
            $this->plan?->nombre,
            $this->programaAcademico?->nombre,
            $this->nivel?->nombre,
            $this->campus?->nombre,
            $this->modalidad,
            $this->rangoDeGeneraciones(),
        ];

        $declarado = array_values(array_filter($partes));

        return $declarado === [] ? 'Toda la escuela' : implode(' · ', $declarado);
    }

    private function rangoDeGeneraciones(): ?string
    {
        if ($this->generacion_desde === null && $this->generacion_hasta === null) {
            return null;
        }

        if ($this->generacion_hasta === null) {
            return "generación {$this->generacion_desde} en adelante";
        }

        if ($this->generacion_desde === null) {
            return "hasta la generación {$this->generacion_hasta}";
        }

        return "generaciones {$this->generacion_desde}–{$this->generacion_hasta}";
    }
}
