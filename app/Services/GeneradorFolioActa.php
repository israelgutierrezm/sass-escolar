<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\Plataforma\Configuracion;
use Illuminate\Support\Facades\DB;

/**
 * Genera el folio del acta de calificaciones.
 *
 * Mismo problema y misma solución que GeneradorMatricula: el folio se imprime
 * y se firma, así que dos titulares cerrando actas al mismo tiempo no pueden
 * obtener el mismo número. El consecutivo sale de `contadores_acta` con un
 * incremento ATÓMICO, nunca de un MAX(folio)+1.
 *
 * El formato es configurable por escuela desde `configuraciones` (claves
 * `acta.formato_folio` y `acta.ambito_consecutivo`) en vez de una tabla de
 * reglas propia: a diferencia de la matrícula —que la escuela quiere distinta
 * por carrera y por plan— el folio del acta es un consecutivo de archivo, uno
 * solo para toda la escuela.
 */
class GeneradorFolioActa
{
    public const CLAVE_FORMATO = 'acta.formato_folio';
    public const CLAVE_AMBITO = 'acta.ambito_consecutivo';

    private const FORMATO_POR_DEFECTO = 'ACT-{AAAA}-{#####}';
    private const AMBITO_POR_DEFECTO = 'anio';

    /**
     * Devuelve el siguiente folio. CONSUME el consecutivo: llamarlo dos veces
     * entrega dos folios distintos, así que solo debe invocarse al cerrar.
     */
    public function generar(AsignaturaGrupo $materiaGrupo, ?int $anio = null): string
    {
        $anio ??= (int) now()->format('Y');

        $materiaGrupo->loadMissing(['grupo.campus', 'grupo.ciclo']);

        $formato = $this->configuracion(self::CLAVE_FORMATO, self::FORMATO_POR_DEFECTO);
        $ambito = $this->configuracion(self::CLAVE_AMBITO, self::AMBITO_POR_DEFECTO);

        $consecutivo = $this->siguienteConsecutivo(
            $this->claveContador($ambito, $materiaGrupo, $anio)
        );

        return $this->renderizar($formato, $materiaGrupo, $anio, $consecutivo);
    }

    private function configuracion(string $clave, string $porDefecto): string
    {
        $valor = Configuracion::query()->find($clave)?->valor;

        return ($valor === null || trim($valor) === '') ? $porDefecto : $valor;
    }

    /** Cada cuánto reinicia la numeración. */
    private function claveContador(string $ambito, AsignaturaGrupo $materiaGrupo, int $anio): string
    {
        return match ($ambito) {
            'global' => 'acta|global',
            'campus' => 'acta|campus:'.($materiaGrupo->grupo?->campus_id ?? 0),
            'ciclo' => 'acta|ciclo:'.($materiaGrupo->grupo?->ciclo_id ?? 0),
            // `anio` es el default y también el respaldo ante un valor mal
            // escrito en configuraciones: numerar de más nunca rompe, y una
            // excepción aquí impediría cerrar un acta ya capturada.
            default => "acta|anio:{$anio}",
        };
    }

    /**
     * Incremento atómico. Ver la nota extensa en GeneradorMatricula: depende de
     * que `contadores_acta` NO tenga columna AUTO_INCREMENT, porque un INSERT
     * sobre una tabla que la tenga pisa LAST_INSERT_ID() y devuelve el número
     * equivocado.
     */
    private function siguienteConsecutivo(string $clave): int
    {
        DB::statement(
            'INSERT INTO contadores_acta (clave, valor, created_at, updated_at)
             VALUES (?, LAST_INSERT_ID(1), NOW(), NOW())
             ON DUPLICATE KEY UPDATE valor = LAST_INSERT_ID(valor + 1), updated_at = NOW()',
            [$clave]
        );

        return (int) DB::selectOne('SELECT LAST_INSERT_ID() AS valor')->valor;
    }

    /** Tokens del formato; {###} rellena con ceros según su longitud. */
    private function renderizar(string $formato, AsignaturaGrupo $materiaGrupo, int $anio, int $consecutivo): string
    {
        $salida = strtr($formato, [
            '{AAAA}' => (string) $anio,
            '{AA}' => substr((string) $anio, -2),
            '{CAMPUS}' => (string) ($materiaGrupo->grupo?->campus?->clave ?? ''),
            '{CICLO}' => (string) ($materiaGrupo->grupo?->ciclo?->clave ?? ''),
        ]);

        return preg_replace_callback(
            '/\{(#+)\}/',
            fn (array $m) => str_pad((string) $consecutivo, strlen($m[1]), '0', STR_PAD_LEFT),
            $salida
        ) ?? $salida;
    }
}
