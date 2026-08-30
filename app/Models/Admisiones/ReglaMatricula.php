<?php

declare(strict_types=1);

namespace App\Models\Admisiones;

use App\Models\Academico\PlanEstudio;
use App\Models\Academico\ProgramaAcademico;
use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * reglas_matricula (TENANT-CONFIG) — formato de matrícula de la escuela.
 *
 * Cada escuela arma su matrícula distinto y ninguna se parece a la de al lado:
 *   ClaveNivel+ClaveProgramaAcademico+ClavePlan+Año+consecutivo del año
 *   Año+ClaveProgramaAcademico+consecutivo histórico del programa académico
 *   ClaveProgramaAcademico+Año+consecutivo del campus por año
 * Por eso la regla es DATO y no código, y se configura en Admisiones.
 */
class ReglaMatricula extends Model
{
    use TieneAuditoria;

    /** Ámbitos donde puede definirse una regla, del más específico al más general. */
    public const AMBITOS = ['plan', 'programa_academico', 'global'];

    /**
     * Sobre qué se cuenta. Lista VACÍA es un solo contador para toda la
     * escuela; con dimensiones, un contador por cada combinación.
     *
     * El orden importa y es éste, no el que el usuario haya elegido en
     * pantalla: la llave del contador se arma con él, y si dependiera del orden
     * de captura, «campus+programa académico» y «programa académico+campus» abrirían dos contadores
     * distintos para la misma regla.
     */
    public const CONSECUTIVO_DIMENSIONES = ['campus', 'nivel', 'programa_academico', 'plan'];

    /** Cada cuándo vuelve al 1. */
    public const REINICIOS = ['nunca', 'anio', 'ciclo'];

    /**
     * Los tokens que la plantilla puede usar, con lo que significan.
     *
     * Viven aquí y no en el generador porque la PANTALLA los enseña: quien
     * configura la regla no tiene por qué adivinarlos.
     *
     * @var array<string, string>
     */
    public const TOKENS = [
        '{AAAA}' => 'Año en cuatro dígitos (2026)',
        '{AA}' => 'Año en dos dígitos (26)',
        '{MM}' => 'Mes en dos dígitos (08)',
        '{CICLO}' => 'Clave del ciclo escolar en curso',
        '{NIVEL}' => 'Clave del nivel de estudios (LIC, MAE…)',
        '{PROGRAMA}' => 'Clave del programa académico',
        '{PLAN}' => 'Clave del plan de estudios',
        '{CAMPUS}' => 'Clave del campus',
        '{####}' => 'Consecutivo. Tantos dígitos como «#» pongas: {####} → 0007',
    ];

    /**
     * Los tokens de texto admiten recorte: `{PROGRAMA:2}` deja las 2 primeras
     * letras de la clave. Es para las escuelas cuya clave de programa académico mide
     * cinco caracteres y en la matrícula sólo caben dos.
     */
    public const TOKENS_RECORTABLES = ['{NIVEL}', '{PROGRAMA}', '{PLAN}', '{CAMPUS}', '{CICLO}'];

    protected $table = 'reglas_matricula';

    protected $fillable = [
        'nombre',
        'ambito',
        'ambito_id',
        'plantilla',
        'consecutivo_dimensiones',
        'consecutivo_reinicia',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
            'consecutivo_dimensiones' => 'array',
        ];
    }

    /**
     * Las dimensiones en el orden canónico, sin repetidas ni desconocidas.
     *
     * Lo que llega de la pantalla viene en el orden en que se marcaron las
     * casillas; la llave del contador necesita uno estable. Ver la nota en
     * `CONSECUTIVO_DIMENSIONES`.
     *
     * @return array<int, string>
     */
    public function dimensiones(): array
    {
        $elegidas = $this->consecutivo_dimensiones ?? [];

        return array_values(array_filter(
            self::CONSECUTIVO_DIMENSIONES,
            fn (string $d) => in_array($d, $elegidas, true),
        ));
    }

    /**
     * A qué se aplica, dicho en palabras.
     *
     * `ambito_id` apunta a un programa académico o a un plan según `ambito`, así que no
     * hay una relación de Eloquent que sirva para los dos: se resuelve aquí.
     */
    public function alcance(): string
    {
        return match ($this->ambito) {
            'plan' => 'Plan: '.(PlanEstudio::find($this->ambito_id)?->nombre ?? '—'),
            'programa_academico' => 'Programa académico: '.(ProgramaAcademico::find($this->ambito_id)?->nombre ?? '—'),
            default => 'Toda la escuela',
        };
    }

    public function programaAcademico(): BelongsTo
    {
        return $this->belongsTo(ProgramaAcademico::class, 'ambito_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(PlanEstudio::class, 'ambito_id');
    }
}
