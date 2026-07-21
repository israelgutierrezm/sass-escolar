<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Academico\Oferta;
use App\Models\Admisiones\ReglaMatricula;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Genera la matrícula de un alumno según la regla configurada por la escuela.
 *
 * Se invoca en el ÚLTIMO paso de la conversión aspirante → alumno, dentro de
 * la misma transacción que crea la `matricula_oferta`.
 *
 * Dos garantías:
 *  1. La regla se resuelve de lo más específico a lo más general
 *     (plan → carrera → global), así una escuela puede tener un formato
 *     distinto para posgrado sin duplicar la regla en cada plan.
 *  2. El consecutivo se obtiene con un incremento ATÓMICO sobre
 *     `contadores_matricula`. Nunca se calcula con MAX(matricula)+1: bajo
 *     concurrencia dos administradores obtendrían el mismo número.
 */
class GeneradorMatricula
{
    /**
     * Devuelve la siguiente matrícula para una oferta. Consume el consecutivo:
     * llamarlo dos veces entrega dos números distintos.
     */
    public function generar(Oferta $oferta, ?int $anio = null): string
    {
        $anio ??= (int) now()->format('Y');

        $oferta->loadMissing(['carrera', 'plan', 'campus']);

        $regla = $this->resolverRegla($oferta);
        $consecutivo = $this->siguienteConsecutivo(
            $this->claveContador($regla, $oferta, $anio)
        );

        return $this->renderizar($regla->plantilla, $oferta, $anio, $consecutivo);
    }

    /**
     * Regla aplicable, de la más específica a la más general.
     */
    public function resolverRegla(Oferta $oferta): ReglaMatricula
    {
        $candidatas = [
            ['plan', $oferta->plan_id],
            ['carrera', $oferta->carrera_id],
            ['global', null],
        ];

        foreach ($candidatas as [$ambito, $ambitoId]) {
            $regla = ReglaMatricula::query()
                ->where('activo', true)
                ->where('ambito', $ambito)
                ->when($ambitoId === null,
                    fn ($q) => $q->whereNull('ambito_id'),
                    fn ($q) => $q->where('ambito_id', $ambitoId),
                )
                ->first();

            if ($regla !== null) {
                return $regla;
            }
        }

        throw new RuntimeException(
            'No hay una regla de matrícula configurada para esta oferta ni una regla global activa.'
        );
    }

    /**
     * Llave del contador: define cada cuánto se reinicia la numeración.
     */
    private function claveContador(ReglaMatricula $regla, Oferta $oferta, int $anio): string
    {
        return match ($regla->ambito_consecutivo) {
            'global' => 'global',
            'anio' => "anio:{$anio}",
            'carrera' => "carrera:{$oferta->carrera_id}",
            'plan' => "plan:{$oferta->plan_id}",
            'carrera_anio' => "carrera:{$oferta->carrera_id}|anio:{$anio}",
            'plan_anio' => "plan:{$oferta->plan_id}|anio:{$anio}",
            default => throw new RuntimeException(
                "Ámbito de consecutivo no reconocido: {$regla->ambito_consecutivo}"
            ),
        };
    }

    /**
     * Incremento atómico del consecutivo.
     *
     * Usa el patrón canónico de MySQL `INSERT ... ON DUPLICATE KEY UPDATE` con
     * LAST_INSERT_ID(): en una sola sentencia crea el contador o lo incrementa,
     * y deja el nuevo valor en el LAST_INSERT_ID de ESTA sesión, de modo que
     * dos conexiones concurrentes nunca leen el mismo número.
     *
     * Depende de que `contadores_matricula` NO tenga columna AUTO_INCREMENT: si
     * la tuviera, el INSERT pisaría LAST_INSERT_ID() con el id de la fila nueva
     * y el primer consecutivo de cada llave saldría mal, generando duplicados.
     */
    private function siguienteConsecutivo(string $clave): int
    {
        DB::statement(
            'INSERT INTO contadores_matricula (clave, valor, created_at, updated_at)
             VALUES (?, LAST_INSERT_ID(1), NOW(), NOW())
             ON DUPLICATE KEY UPDATE valor = LAST_INSERT_ID(valor + 1), updated_at = NOW()',
            [$clave]
        );

        return (int) DB::selectOne('SELECT LAST_INSERT_ID() AS valor')->valor;
    }

    /**
     * Sustituye los tokens de la plantilla. El consecutivo se rellena con
     * ceros según la cantidad de "#" del token: {####} → 0007.
     */
    private function renderizar(string $plantilla, Oferta $oferta, int $anio, int $consecutivo): string
    {
        $salida = strtr($plantilla, [
            '{AAAA}' => (string) $anio,
            '{AA}' => substr((string) $anio, -2),
            '{CARRERA}' => (string) $oferta->carrera?->clave,
            '{PLAN}' => (string) $oferta->plan?->clave,
            '{CAMPUS}' => (string) $oferta->campus?->clave,
        ]);

        return preg_replace_callback(
            '/\{(#+)\}/',
            fn (array $m) => str_pad((string) $consecutivo, strlen($m[1]), '0', STR_PAD_LEFT),
            $salida
        ) ?? $salida;
    }
}
