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

    /**
     * Las columnas donde NULL significa MÁS, no menos.
     *
     * Casi siempre poner en NULL una referencia rota quita algo: un adeudo se
     * queda sin ciclo, una imagen sin quien la subió. Pero en el alcance por
     * campus el null es «sin acotar», así que reparar convierte un rol atado a
     * un campus que ya no existe —y que por eso no veía nada— en un rol GLOBAL.
     *
     * Es la corrección correcta: la restricción apuntaba a la nada y nadie
     * puede trabajar así. Pero es un ensanchamiento de alcance hecho por un
     * comando de mantenimiento, y eso no puede pasar callado.
     *
     * @var array<string, string>
     */
    private const NULL_ES_MAS = [
        'persona_rol.campus_id' => 'esos roles quedan con alcance GLOBAL, no acotado. '
            .'Revisa a quién le tocaba qué campus y vuelve a asignárselo desde /plataforma/usuarios.',
    ];

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
        $escuelasQueFallaron = [];

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
                $escuelasQueFallaron[] = $escuela->getTenantKey();
            }
        }

        $this->newLine();

        /*
         * Una escuela que reventó a media revisión NO se puede reportar como
         * limpia. Pasó de verdad: un CHECK rechazó una reparación, la excepción
         * subió hasta aquí, el contador se quedó en cero y el comando terminó
         * diciendo «Ninguna referencia rota» sobre una base con 199. Un
         * diagnóstico que miente al fallar es peor que uno que no corre.
         */
        if ($escuelasQueFallaron !== []) {
            $this->error('No se pudo terminar en: '.implode(', ', $escuelasQueFallaron).'.');
            $this->line('Lo reportado arriba está incompleto; vuelve a correrlo tras resolver el error.');

            return self::FAILURE;
        }

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
            $resultado = $this->reparar($conexion, $anulables);

            $this->newLine();
            $this->info("  {$resultado['reparadas']} referencia(s) puestas en NULL.");

            if ($resultado['imposibles'] !== []) {
                $this->line('  Y estas no se pudieron, por lo que dice la base:');

                foreach ($resultado['imposibles'] as $imposible) {
                    $this->line("    {$imposible}");
                }
            }

            $this->line('  Lo obligatorio se quedó como estaba: eso lo decide la escuela.');
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
     * ── Columna por columna, y NO todo o nada ──────────────────────────────
     * Antes esto iba en una sola transacción por escuela, con el argumento de
     * que media reparación es peor que ninguna. El argumento no se sostuvo al
     * primer contacto con datos reales: `adeudos` tiene un CHECK que exige
     * EXACTAMENTE un titular —matrícula o aspirante—, así que poner en NULL una
     * matrícula rota deja la fila sin ninguno y la base lo rechaza. Con la
     * transacción única, esa sola columna tumbaba las once buenas y la escuela
     * se quedaba sin reparar para siempre.
     *
     * Cada columna es un solo `UPDATE`, o sea atómico por su cuenta, así que no
     * hay «media columna». Y reparar es idempotente: volver a correrlo termina
     * lo que faltó.
     *
     * ── Que la columna admita NULL no basta ────────────────────────────────
     * Un CHECK puede prohibir precisamente ese NULL, y eso no se lee en
     * `IS_NULLABLE`. En vez de interpretar las cláusulas CHECK —frágil y
     * distinto en cada motor—, se intenta y se reporta lo que la base rechace:
     * esas columnas caen en el mismo saco que las obligatorias, porque la fila
     * perdió sentido y borrarla es decisión de la escuela.
     *
     * @param  array<int, array<string, mixed>>  $anulables
     * @return array{reparadas: int, imposibles: array<int, string>}
     */
    private function reparar($conexion, array $anulables): array
    {
        $this->newLine();
        $this->line('  Reparando…');

        $reparadas = 0;
        $imposibles = [];

        foreach ($anulables as $f) {
            $donde = "{$f['tabla']}.{$f['columna']}";

            try {
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

                $reparadas += $afectadas;
                $this->line("    {$donde}: {$afectadas} puesta(s) en NULL");

                if ($afectadas > 0 && array_key_exists($donde, self::NULL_ES_MAS)) {
                    $this->warn('      ojo: '.self::NULL_ES_MAS[$donde]);
                }
            } catch (\Throwable $e) {
                $motivo = $this->porQueNoSePudo($e);
                $imposibles[] = "{$donde} — {$motivo}";
                $this->warn("    {$donde}: no se pudo. {$motivo}");
            }
        }

        return ['reparadas' => $reparadas, 'imposibles' => $imposibles];
    }

    /**
     * Por qué la base rechazó el NULL, en una línea que se pueda leer.
     *
     * El mensaje del driver trae la consulta entera y los parámetros; en una
     * lista de doce columnas eso es ilegible. Lo que hace falta saber es cuál
     * es la regla que lo impide.
     */
    private function porQueNoSePudo(\Throwable $e): string
    {
        if (preg_match("/Check constraint '([^']+)' is violated/", $e->getMessage(), $coincide) === 1) {
            return "la restricción «{$coincide[1]}» lo impide: sin esa referencia la fila queda sin sentido.";
        }

        /*
         * 1452 al poner algo en NULL suena a imposible, y tiene una explicación
         * concreta: MySQL revalida TODAS las foráneas de la fila en cualquier
         * UPDATE, no sólo la columna que se toca. Si esa misma fila arrastra
         * otra referencia rota en una columna que NO admite null, la fila entera
         * se vuelve intocable hasta que alguien resuelva esa otra.
         *
         * Comprobado en el demo: la beca 5 tiene el ciclo roto (anulable) y la
         * matrícula rota (obligatoria); la conversación 13, las dos personas
         * (anulables) y la materia (obligatoria). Son exactamente las que
         * fallaron.
         */
        if (str_contains($e->getMessage(), '1452')) {
            return 'esa fila arrastra otra referencia rota en una columna obligatoria, '
                .'y MySQL revalida todas las foráneas de la fila al actualizarla. '
                .'Se destraba resolviendo la obligatoria (o borrando la fila).';
        }

        return trim(strtok($e->getMessage(), '(') ?: $e->getMessage());
    }
}
