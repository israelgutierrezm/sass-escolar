<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/**
 * Base para las pruebas que tocan el esquema de una escuela.
 *
 * ── Por qué MySQL y no SQLite en memoria ───────────────────────────────────
 * Sería más rápido, y se intentó. Las migraciones de tenant están escritas
 * contra MySQL —índices de texto completo, `INSERT IGNORE`, `UPDATE ... JOIN`—
 * y en SQLite abortan. Reescribirlas para que sirvieran a las pruebas habría
 * significado probar un esquema que no es el que corre en producción, que es
 * justo lo contrario de para lo que existe una prueba.
 *
 * ── Dos bases, como en la realidad ─────────────────────────────────────────
 * `acadion_testing` es la escuela y `acadion_testing_central` la central. Van
 * separadas porque comparten nombres de tabla (`cache`, `jobs`) y una sola base
 * se pisaría. Las crea `tests/bootstrap.php` y las conexiones vienen de
 * `phpunit.xml`; los modelos con `CentralConnection` —los catálogos de la SEP—
 * caen en la segunda.
 *
 * ── Se migra una vez ───────────────────────────────────────────────────────
 * Son más de doscientas migraciones: aplicarlas en cada prueba las volvería
 * inservibles de lentas. El esquema se levanta la primera vez —y en las
 * corridas siguientes `migrate` no tiene nada que aplicar—, y cada prueba se
 * envuelve en una transacción que se deshace al terminar, así ninguna ve lo que
 * escribió la anterior.
 */
abstract class TenantTestCase extends TestCase
{
    use DatabaseTransactions;

    /** Las dos bases se deshacen: hay pruebas que siembran catálogos centrales. */
    protected array $connectionsToTransact = ['mysql', 'central'];

    /** El esquema se levanta una vez por ejecución de la suite. */
    private static bool $esquemaListo = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$esquemaListo) {
            return;
        }

        Artisan::call('migrate', ['--database' => 'central', '--path' => 'database/migrations', '--force' => true]);
        Artisan::call('migrate', ['--database' => 'mysql', '--path' => 'database/migrations/tenant', '--force' => true]);

        if (! Schema::connection('mysql')->hasTable('avisos')) {
            $this->fail('El esquema de pruebas no se levantó: falta la tabla `avisos`.');
        }

        self::$esquemaListo = true;
    }
}
