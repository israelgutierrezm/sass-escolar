<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Busca filas que apuntan a registros que ya no existen.
 *
 * ── Por qué hace falta si las foráneas ya están declaradas ─────────────────
 * Porque MySQL sólo las comprueba al ESCRIBIR. Una resiembra hecha con
 * `SET FOREIGN_KEY_CHECKS=0` —o un `TRUNCATE` de un catálogo— deja filas
 * envenenadas que la base acepta y nadie vuelve a mirar.
 *
 * No dan síntomas durante meses. Se manifiestan de dos formas, las dos malas:
 *
 * 1. **Al tocar el esquema.** Agregar una columna reconstruye la tabla y MySQL
 *    revalida sus foráneas de golpe; entonces una migración inocente muere con
 *    «Cannot add or update a child row». Ya pasó en este proyecto al agregarle
 *    `rubrica_id` a `actividades`.
 * 2. **En pantalla, en silencio.** Una relación que resuelve a null hace que un
 *    listado muestre «—» donde debería ir un nombre, o que un total no cuadre,
 *    sin que nada falle.
 *
 * ── Reportar y reparar son dos comandos, no uno ────────────────────────────
 * Por omisión sólo INFORMA. Reparar toca datos de una escuela en producción, y
 * eso no puede ser el efecto de teclear un comando de diagnóstico.
 *
 * ── Y reparar no es borrar ─────────────────────────────────────────────────
 * `--reparar` sólo pone en NULL las columnas que ADMITEN null, que es
 * exactamente lo que la propia foránea habría hecho con su `ON DELETE SET NULL`.
 * Las columnas obligatorias no se tocan: una entrega cuya inscripción ya no
 * existe no se puede «arreglar» —la fila entera dejó de tener sentido— y
 * borrarla es una decisión de la escuela, no de un comando. Se listan aparte
 * para que alguien las mire.
 */
class AuditarDatos extends Command
{
    protected $signature = 'acadion:auditar-datos
        {--tenant= : Una escuela; sin esto, todas}
        {--reparar : Pone en NULL las referencias rotas que admiten null}';

    protected $description = 'Busca filas que apuntan a registros que ya no existen';

    /** Alias de la tabla referenciada dentro de la subconsulta. Ver `rotas()`. */
    private const REF = '__referida';

    public function handle(): int
    {
        $escuelas = $this->option('tenant') !== null
            ? Tenant::query()->whereKey($this->option('tenant'))->get()
            : Tenant::all();

        if ($escuelas->isEmpty()) {
            $this->error('No hay escuelas que revisar.');

            return self::FAILURE;
        }

        $totalRotas = 0;

        foreach ($escuelas as $escuela) {
            $this->newLine();
            $this->info("Escuela: {$escuela->getTenantKey()}");

            /*
             * Cada escuela aislada. Una con el esquema a medias —una migración
             * que quedó a mitad— no puede dejar sin revisar a las demás.
             */
            try {
                $totalRotas += $escuela->run(fn () => $this->revisar());
            } catch (\Throwable $e) {
                $this->error('  No se pudo revisar: '.$e->getMessage());
            }
        }

        $this->newLine();

        if ($totalRotas === 0) {
            $this->info('Ninguna referencia rota.');

            return self::SUCCESS;
        }

        if (! $this->option('reparar')) {
            $this->comment("Se encontraron {$totalRotas} fila(s) con referencias rotas.");
            $this->line('Para reparar las que admiten NULL:  php artisan acadion:auditar-datos --reparar');
        }

        /*
         * Éxito aunque haya hallazgos: esto es un diagnóstico, no una prueba.
         * Devolver error haría fallar cualquier despliegue que lo encadene, y el
         * dato roto lleva meses ahí — no es una urgencia que deba tumbar nada.
         */
        return self::SUCCESS;
    }

    /** Revisa la escuela ya inicializada. Devuelve cuántas filas están rotas. */
    private function revisar(): int
    {
        $conexion = DB::connection('tenant');
        $base = $conexion->getDatabaseName();

        // Se leen las foráneas DECLARADAS, no una lista escrita a mano: así el
        // comando cubre solo las tablas que se agreguen mañana.
        $foraneas = $conexion->select('
            SELECT k.TABLE_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, c.IS_NULLABLE
            FROM information_schema.KEY_COLUMN_USAGE k
            JOIN information_schema.COLUMNS c
              ON c.TABLE_SCHEMA = k.TABLE_SCHEMA
             AND c.TABLE_NAME = k.TABLE_NAME
             AND c.COLUMN_NAME = k.COLUMN_NAME
            WHERE k.TABLE_SCHEMA = ? AND k.REFERENCED_TABLE_NAME IS NOT NULL
            ORDER BY k.TABLE_NAME, k.COLUMN_NAME
        ', [$base]);

        $anulables = [];
        $obligatorias = [];
        $total = 0;

        foreach ($foraneas as $fk) {
            $rotas = $this->contarRotas($conexion, $fk);

            if ($rotas === null) {
                $this->warn("  ?  {$fk->TABLE_NAME}.{$fk->COLUMN_NAME}: no se pudo comprobar");

                continue;
            }

            if ($rotas === 0) {
                continue;
            }

            $total += $rotas;

            $fila = [
                'tabla' => $fk->TABLE_NAME,
                'columna' => $fk->COLUMN_NAME,
                'apunta_a' => $fk->REFERENCED_TABLE_NAME,
                'referencia' => $fk->REFERENCED_COLUMN_NAME,
                'filas' => $rotas,
            ];

            $fk->IS_NULLABLE === 'YES' ? $anulables[] = $fila : $obligatorias[] = $fila;
        }

        $this->reportar('Se pueden poner en NULL', $anulables);
        $this->reportar('La fila entera quedó sin sentido (NO se tocan)', $obligatorias);

        if ($this->option('reparar') && $anulables !== []) {
            $this->reparar($conexion, $anulables);
        }

        if ($total === 0) {
            $this->line('  sin referencias rotas');
        }

        return $total;
    }

    /**
     * Cuántas filas apuntan a nada. Null si la consulta no se pudo hacer.
     *
     * @param  object  $fk
     */
    private function contarRotas($conexion, $fk): ?int
    {
        try {
            return $this->rotas(
                $conexion,
                $fk->TABLE_NAME,
                $fk->COLUMN_NAME,
                $fk->REFERENCED_TABLE_NAME,
                $fk->REFERENCED_COLUMN_NAME,
            )->count();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Las filas cuya referencia no existe.
     *
     * ── El alias de la subconsulta NO es cosmético ─────────────────────────
     * Sin él, una foránea que apunta a su PROPIA tabla —`roles.rol_padre_id` →
     * `roles.id`, `encuestas.origen_id` → `encuestas.id`— pierde la
     * correlación: los dos lados del `whereColumn` se resuelven contra la tabla
     * interna, la comparación queda como `roles.id = roles.rol_padre_id`
     * evaluada adentro, y da falso para toda fila que no sea su propio padre.
     * Resultado: TODAS las jerarquías válidas se reportan como rotas.
     *
     * Y esto no era sólo un reporte feo. Con `--reparar`, habría puesto en NULL
     * el padre de cada rol funcional —tirando la herencia de permisos de la
     * escuela entera— y el origen de cada encuesta versionada. Un comando de
     * diagnóstico que destruye lo que viene a revisar.
     *
     * Se cazó contrastando el reporte contra una consulta hecha a mano: decía
     * que `roles.rol_padre_id` tenía una fila rota y el único rol con padre lo
     * tenía perfectamente.
     */
    private function rotas($conexion, string $tabla, string $columna, string $referida, string $referencia)
    {
        return $conexion->table($tabla)
            ->whereNotNull($columna)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from($referida.' as '.self::REF)
                ->whereColumn(self::REF.'.'.$referencia, $tabla.'.'.$columna));
    }

    /** @param  array<int, array<string, mixed>>  $filas */
    private function reportar(string $titulo, array $filas): void
    {
        if ($filas === []) {
            return;
        }

        $this->newLine();
        $this->line("  {$titulo}:");

        foreach ($filas as $f) {
            $this->line(sprintf(
                '    %-42s %5d fila(s) -> %s',
                "{$f['tabla']}.{$f['columna']}",
                $f['filas'],
                $f['apunta_a'],
            ));
        }
    }

    /**
     * Pone en NULL lo que se puede.
     *
     * En una transacción por escuela: si una tabla falla a la mitad, no queda
     * media reparación hecha y media no — que sería peor que el estado inicial,
     * porque ya nadie sabría qué se tocó.
     *
     * @param  array<int, array<string, mixed>>  $anulables
     */
    private function reparar($conexion, array $anulables): void
    {
        $this->newLine();
        $this->line('  Reparando…');

        $conexion->transaction(function () use ($conexion, $anulables) {
            foreach ($anulables as $f) {
                // La MISMA consulta que contó, no una copia: si divergieran, se
                // repararía algo distinto de lo que se reportó — y aquí eso
                // significa poner en NULL filas que estaban bien.
                $afectadas = $this->rotas(
                    $conexion,
                    $f['tabla'],
                    $f['columna'],
                    $f['apunta_a'],
                    $f['referencia'],
                )->update([$f['columna'] => null]);

                $this->line("    {$f['tabla']}.{$f['columna']}: {$afectadas} puesta(s) en NULL");
            }
        });

        $this->info('  Listo. Lo obligatorio se quedó como estaba: eso lo decide la escuela.');
    }
}
