<?php

declare(strict_types=1);

namespace App\Models\Permanencia;

use App\Models\Academico\Campus;
use App\Models\Academico\PlanEstudio;
use App\Models\Academico\ProgramaAcademico;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Concerns\TieneAuditoria;
use App\Models\ControlEscolar\Ciclo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * reglas_alerta (TENANT) — a quién alcanza una regla de señal.
 *
 * Lo que MIDE vive en sus versiones ({@see ReglaAlertaVersion}).
 *
 * ── Esto NO es `ResolutorDeRegla`, y la diferencia es el módulo entero ─────
 * En `ProcesosFormativos` gana UNA regla: la más específica, con jerarquía
 * lexicográfica de pesos, y las demás no existen. **Aquí evalúan TODAS las que
 * alcanzan al alumno**, porque «tres faltas seguidas» y «promedio bajo el
 * umbral» son dos preguntas distintas y las dos pueden ser ciertas.
 *
 * Por eso aquí no hay `PESOS` ni `especificidad()`: sólo `alcanzaA()`. Alguien
 * va a querer reusar aquel resolutor por parecido; con él, un alumno recibiría
 * la alerta de la regla más específica y las demás señales desaparecerían **sin
 * un solo error**, que es la peor forma de romperse.
 *
 * ── Lo que se deja en NULL no acota ────────────────────────────────────────
 * Una regla sin ningún eje es la general de la escuela. Es lo que permite que
 * la general y la excepción convivan sin escribir la general dos veces.
 *
 * ── La lista de ejes es CERRADA y no incluye atributos sensibles ───────────
 * Campus, nivel, programa, plan, ciclo, situación de la matrícula, modalidad,
 * generación y asignatura. No hay ejes de sexo, nacionalidad, beca ni ningún
 * proxy socioeconómico, y no es un olvido: acotar una regla por «tiene beca»
 * subiría el riesgo de quien recibe apoyo por el hecho de recibirlo, y eso
 * convierte una política de equidad en una marca. Es una de las tres
 * prohibiciones duras del módulo, y una prueba la vigila.
 */
class ReglaAlerta extends Model
{
    use TieneAuditoria;

    protected $table = 'reglas_alerta';

    /**
     * Nace APAGADA, y es lo contrario de lo que hace `ReglaProceso`.
     *
     * Allá una regla de servicio social que nace activa no le hace nada a nadie
     * hasta que un alumno abre su expediente. Aquí una regla activa empieza a
     * poner gente en la cola de trabajo de alguien en la siguiente corrida del
     * motor, sobre datos que la escuela quizá esté todavía cargando.
     *
     * El default importa aunque el controlador ya lo fuerce: el día que otro
     * camino cree una regla —una importación, un seeder de otra escuela— el
     * lado seguro tiene que ser el que no molesta a nadie.
     */
    protected $attributes = ['activa' => false];

    protected $fillable = [
        'nombre',
        'descripcion',
        'categoria_id',
        'proveedor',
        'campus_id',
        'nivel_estudios_id',
        'programa_academico_id',
        'plan_id',
        'ciclo_id',
        'situacion_alumno_id',
        'modalidad',
        'generacion_desde',
        'generacion_hasta',
        'asignatura_id',
        'activa',
        'notas',
    ];

    protected function casts(): array
    {
        return [
            'activa' => 'boolean',
            'generacion_desde' => 'integer',
            'generacion_hasta' => 'integer',
        ];
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(CategoriaSenal::class, 'categoria_id');
    }

    public function versiones(): HasMany
    {
        return $this->hasMany(ReglaAlertaVersion::class, 'regla_id')->orderBy('version');
    }

    public function exclusiones(): HasMany
    {
        return $this->hasMany(ExclusionReglaAlerta::class, 'regla_id');
    }

    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class, 'campus_id');
    }

    public function programaAcademico(): BelongsTo
    {
        return $this->belongsTo(ProgramaAcademico::class, 'programa_academico_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(PlanEstudio::class, 'plan_id');
    }

    public function ciclo(): BelongsTo
    {
        return $this->belongsTo(Ciclo::class, 'ciclo_id');
    }

    public function scopeActivas(Builder $c): Builder
    {
        return $c->where('activa', true);
    }

    /**
     * La versión que RIGE en una fecha.
     *
     * Es la única desambiguación que este modelo hace, y es entre versiones de
     * la MISMA regla: dos no pueden regir a la vez. Gana la vigente y, con
     * empate, la más reciente — con el desempate por `version` y no por lo que
     * devuelva la base, porque dos versiones que empiezan el mismo día existen
     * (las escribió gente distinta) y sin desempate la misma pregunta daría dos
     * respuestas en dos días.
     */
    public function versionVigente(?string $dia = null): ?ReglaAlertaVersion
    {
        $fecha = $dia ?? now()->toDateString();

        return $this->versiones
            ->filter(fn (ReglaAlertaVersion $v) => $v->rigeEl($fecha))
            ->sortByDesc('version')
            ->first();
    }

    /**
     * ¿Esta regla alcanza a esta matrícula?
     *
     * Lo que está en NULL no acota. Sin oferta no alcanza a nadie: no se puede
     * afirmar el campus ni el programa de una matrícula sin ella, y darla por
     * buena metería en la cola a quien no sabemos dónde estudia.
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

        if ($this->programa_academico_id !== null
            && $this->programa_academico_id !== $oferta->programa_academico_id) {
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

        if ($this->situacion_alumno_id !== null
            && $this->situacion_alumno_id !== $matricula->situacion_id) {
            return false;
        }

        return $this->cubreLaGeneracion($matricula->generacion);
    }

    /**
     * El rango de generaciones, comparado como NÚMERO.
     *
     * Sin generación capturada, una regla que la acote NO alcanza. Darla por
     * buena dejaría entrar a quien no sabemos de qué generación es, que es
     * justo lo que el rango existe para separar — y aquí el error se paga en la
     * cola de trabajo de alguien.
     */
    public function cubreLaGeneracion(?string $generacion): bool
    {
        if ($this->generacion_desde === null && $this->generacion_hasta === null) {
            return true;
        }

        if ($generacion === null || trim($generacion) === '') {
            return false;
        }

        /*
         * La generación se captura como texto y no siempre es un año pelado:
         * «2024», «2024-A», «2024B». Se toman los cuatro primeros dígitos, que
         * es lo único comparable como número; lo que no los tenga no se puede
         * situar en el rango y no alcanza.
         */
        if (! preg_match('/^\D*(\d{4})/', $generacion, $partes)) {
            return false;
        }

        $anio = (int) $partes[1];

        if ($this->generacion_desde !== null && $anio < $this->generacion_desde) {
            return false;
        }

        return $this->generacion_hasta === null || $anio <= $this->generacion_hasta;
    }

    /** Cómo se lee su alcance en una línea. Lo usa la pantalla y el detalle de la alerta. */
    public function comoSeLeeElAlcance(): string
    {
        $ejes = array_filter([
            $this->campus?->nombre,
            $this->programaAcademico?->nombre,
            $this->plan?->nombre,
            $this->ciclo?->clave,
            $this->modalidad,
            $this->generacion_desde !== null || $this->generacion_hasta !== null
                ? 'generaciones '.($this->generacion_desde ?? '…').'–'.($this->generacion_hasta ?? '…')
                : null,
        ]);

        return $ejes === [] ? 'Toda la escuela' : implode(' · ', $ejes);
    }
}
