<?php

declare(strict_types=1);

namespace App\Models\Academico;

use App\Enums\ModoRedondeo;
use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * planes_estudio (TENANT).
 */
class PlanEstudio extends Model
{
    use TieneAuditoria;

    protected $table = 'planes_estudio';

    protected $fillable = [
        'carrera_id',
        'clave',
        'abreviacion',
        'nombre',
        'rvoe',
        'fecha_rvoe',
        'autorizacion_reconocimiento_id',
        'tipo_periodo_id',
        'total_periodos',
        'calificacion_minima',
        'calificacion_maxima',
        'calificacion_minima_aprobatoria',
        'decimales_calificacion',
        'redondeo_calificacion',
        'minimo_creditos',
        'minimo_asignaturas',
        'total_creditos',
        'curp_responsable',
        'clave_matricula',
        'clave_matricula_consecutivo',
        'plantilla_evaluacion_id',
        'vigente',
    ];

    protected function casts(): array
    {
        return [
            'fecha_rvoe' => 'date',
            'vigente' => 'boolean',
            'minimo_creditos' => 'float',
            'total_creditos' => 'float',
            'redondeo_calificacion' => ModoRedondeo::class,
        ];
    }

    public function carrera(): BelongsTo
    {
        return $this->belongsTo(Carrera::class);
    }

    /** Criterio de evaluación por defecto para las materias de este plan. */
    public function plantillaEvaluacion(): BelongsTo
    {
        return $this->belongsTo(PlantillaEvaluacion::class, 'plantilla_evaluacion_id');
    }

    public function autorizacionReconocimiento(): BelongsTo
    {
        return $this->belongsTo(AutorizacionReconocimiento::class);
    }

    public function tipoPeriodo(): BelongsTo
    {
        return $this->belongsTo(TipoPeriodo::class);
    }

    /**
     * Etiqueta singular con la que se numera la malla según el tipo de periodo
     * del plan: «Semestre», «Cuatrimestre», etc. Los tipos que son adjetivo
     * (MODULAR, ANUAL) se traducen a su sustantivo. Sin tipo cae a «Periodo».
     */
    public function unidadPeriodo(): string
    {
        $nombre = $this->tipoPeriodo?->nombre;

        if ($nombre === null || $nombre === '') {
            return 'Periodo';
        }

        $especiales = ['MODULAR' => 'Módulo', 'ANUAL' => 'Año'];

        return $especiales[mb_strtoupper($nombre)]
            ?? mb_strtoupper(mb_substr($nombre, 0, 1)).mb_strtolower(mb_substr($nombre, 1));
    }

    public function planMaterias(): HasMany
    {
        return $this->hasMany(PlanMateria::class, 'plan_id');
    }

    /**
     * Las reglas con las que se valida una calificación de este plan.
     *
     * Viven aquí y no repetidas en cada controlador porque son DOS los sitios
     * que capturan calificaciones —la del docente y el kárdex a mano— y a los
     * dos se les había escapado la precisión: aceptaban un 8.756 en un acta
     * porque `numeric` no dice cuántos decimales.
     *
     * @return array<int, string>
     */
    public function reglasDeCalificacion(): array
    {
        $decimales = (int) ($this->decimales_calificacion ?? 2);

        return [
            'numeric',
            'min:'.(float) $this->calificacion_minima,
            'max:'.(float) $this->calificacion_maxima,
            // `decimal:0,N` acepta de cero a N decimales: con N = 0 exige un
            // entero, que es como califica buena parte de las escuelas.
            "decimal:0,{$decimales}",
        ];
    }

    /**
     * Las reglas cuando puede no haber plan.
     *
     * Una materia sin plan no debería existir, pero el código que la lee ya
     * contemplaba el caso y no es este cambio el que va a decidir romperlo. Sin
     * plan se cae a la escala más permisiva: rechazar una captura porque falta
     * una relación sería castigar a quien califica por un problema de catálogo.
     *
     * @return array<int, string>
     */
    public static function reglasPara(?self $plan): array
    {
        return $plan?->reglasDeCalificacion() ?? ['numeric'];
    }

    /** Cómo decirle a quien captura qué precisión se espera. */
    public function comoSeCalifica(): string
    {
        $decimales = (int) ($this->decimales_calificacion ?? 2);

        return match ($decimales) {
            0 => 'con números enteros, sin decimales',
            1 => 'con un decimal',
            default => "con hasta {$decimales} decimales",
        };
    }

    public function modoRedondeo(): ModoRedondeo
    {
        return $this->redondeo_calificacion ?? ModoRedondeo::MEDIO_ARRIBA;
    }

    /**
     * Deja un promedio en la precisión de este plan.
     *
     * ── Por qué existe ─────────────────────────────────────────────────────
     * Que la CAPTURA exija enteros no sirve de nada si el promedio se sigue
     * enseñando como 8.33: el plan dice que aquí no hay decimales y la pantalla
     * muestra dos. Peor, cada sitio traía su propio `round()` con una precisión
     * distinta —el expediente redondeaba a un decimal y el portal del padre a
     * dos—, así que el mismo alumno tenía dos promedios según quién mirara.
     *
     * El `null` se conserva: «todavía no tiene promedio» no es lo mismo que
     * cero, y convertirlo en 0.0 le inventaría un reprobado a quien no ha
     * cursado nada.
     */
    public function redondear(?float $valor): ?float
    {
        if ($valor === null) {
            return null;
        }

        return $this->modoRedondeo()->aplicar($valor, (int) ($this->decimales_calificacion ?? 2));
    }

    /**
     * Redondea cuando puede no haber plan.
     *
     * Sin plan se usa lo que el sistema hacía antes de que esto se pudiera
     * configurar —dos decimales, medio arriba—, para que la falta de una
     * relación no cambie un promedio.
     */
    public static function redondearCon(?self $plan, ?float $valor): ?float
    {
        if ($valor === null) {
            return null;
        }

        return $plan?->redondear($valor)
            ?? ModoRedondeo::MEDIO_ARRIBA->aplicar($valor, 2);
    }

    /** Cómo se le explica el redondeo a quien configura la escala. */
    public function comoSeRedondea(): string
    {
        return $this->modoRedondeo()->etiqueta();
    }
}
