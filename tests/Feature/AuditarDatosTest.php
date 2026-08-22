<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\AuditarDatos;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TenantTestCase;

/**
 * La consulta con la que `acadion:auditar-datos` decide qué está roto.
 *
 * ── Por qué se prueba el método y no la salida del comando ─────────────────
 * Porque el defecto que motiva esta prueba no se ve en la salida: se ve en QUÉ
 * filas escoge. Un reporte con un número de más parece un detalle cosmético;
 * lo que estaba en juego es que `--reparar` usa la MISMA consulta para poner
 * columnas en NULL. Escoger de más aquí es destruir datos buenos.
 *
 * ── El caso que lo destapó ─────────────────────────────────────────────────
 * Una foránea que apunta a su propia tabla —`roles.rol_padre_id` → `roles.id`—
 * necesita alias en la subconsulta. Sin él, los dos lados de la comparación se
 * resuelven contra la tabla interna, la correlación se pierde y TODA jerarquía
 * válida se reporta como rota. Con `--reparar` eso habría dejado sin padre a
 * cada rol funcional de la escuela, o sea sin los permisos que hereda.
 */
class AuditarDatosTest extends TenantTestCase
{
    /** @param  array<int, array<string, mixed>>  $anulables */
    private function reparar(array $anulables): array
    {
        $metodo = new ReflectionMethod(AuditarDatos::class, 'reparar');
        $metodo->setAccessible(true);

        $comando = app(AuditarDatos::class);
        $comando->setOutput(new OutputStyle(
            new ArrayInput([]),
            new NullOutput,
        ));

        return $metodo->invoke($comando, DB::connection('mysql'), $anulables);
    }

    /** Invoca la consulta privada tal como la usan el conteo y la reparación. */
    private function rotas(string $tabla, string $columna, string $referida, string $referencia)
    {
        $metodo = new ReflectionMethod(AuditarDatos::class, 'rotas');
        $metodo->setAccessible(true);

        return $metodo->invoke(
            app(AuditarDatos::class),
            DB::connection('mysql'),
            $tabla,
            $columna,
            $referida,
            $referencia,
        );
    }

    private function rol(string $clave, ?int $padre = null): int
    {
        return (int) DB::table('roles')->insertGetId([
            'name' => $clave,
            'nombre' => $clave,
            'guard_name' => 'web',
            'protegido' => false,
            'rol_padre_id' => $padre,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_un_padre_que_si_existe_no_se_reporta_como_roto(): void
    {
        $padre = $this->rol('auditoria-faceta');
        $hijo = $this->rol('auditoria-funcional', $padre);

        $rotas = $this->rotas('roles', 'rol_padre_id', 'roles', 'id')->pluck('id')->all();

        // Ésta es la que caía: sin alias, el hijo salía en la lista.
        $this->assertNotContains($hijo, $rotas, 'Un rol con padre existente se reportó como roto.');
    }

    public function test_un_padre_que_ya_no_existe_si_se_reporta(): void
    {
        $huerfano = $this->rol('auditoria-huerfano');

        // La foránea impide crear la fila rota, que es justo lo que hace
        // invisible al problema: sólo aparece cuando alguien resembró con las
        // comprobaciones apagadas. Se reproduce igual.
        DB::connection('mysql')->statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('roles')->where('id', $huerfano)->update(['rol_padre_id' => 987654321]);
        DB::connection('mysql')->statement('SET FOREIGN_KEY_CHECKS=1');

        $rotas = $this->rotas('roles', 'rol_padre_id', 'roles', 'id')->pluck('id')->all();

        $this->assertContains($huerfano, $rotas, 'Un rol cuyo padre no existe pasó desapercibido.');
    }

    public function test_una_foranea_normal_sigue_distinguiendo_lo_bueno_de_lo_roto(): void
    {
        // El alias no puede haber roto el caso corriente, que es el 99 %.
        $campus = DB::table('campus')->insertGetId([
            'clave' => 'AUD', 'nombre' => 'Campus auditoría',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $bueno = $this->rol('auditoria-con-campus');
        $roto = $this->rol('auditoria-sin-campus');
        $persona = DB::table('personas')->insertGetId([
            'nombre' => 'Auditoría', 'primer_apellido' => 'De Prueba',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('persona_rol')->insert([
            ['persona_id' => $persona, 'rol_id' => $bueno, 'campus_id' => $campus],
            ['persona_id' => $persona, 'rol_id' => $roto, 'campus_id' => $campus],
        ]);

        $filaRota = DB::table('persona_rol')->where('rol_id', $roto)->value('id');

        DB::connection('mysql')->statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('persona_rol')->where('id', $filaRota)->update(['campus_id' => 987654321]);
        DB::connection('mysql')->statement('SET FOREIGN_KEY_CHECKS=1');

        $rotas = $this->rotas('persona_rol', 'campus_id', 'campus', 'id')->pluck('id')->all();

        $buena = DB::table('persona_rol')->where('rol_id', $bueno)->value('id');
        $this->assertContains($filaRota, $rotas);
        $this->assertNotContains($buena, $rotas);
    }

    /**
     * Una columna que la base se niega a anular NO puede tumbar a las demás.
     *
     * Pasó de verdad contra el demo: `adeudos` tiene un CHECK que exige
     * exactamente un titular —matrícula o aspirante—, así que anular una
     * matrícula rota deja la fila sin ninguno y MySQL lo rechaza. Con la
     * reparación entera en una sola transacción, esa columna tiraba las once
     * buenas y la escuela se quedaba sin reparar para siempre.
     */
    public function test_una_columna_imposible_no_impide_reparar_las_demas(): void
    {
        // Reparable: un rol acotado a un campus que ya no existe.
        $rol = $this->rol('auditoria-reparable');
        $persona = DB::table('personas')->insertGetId([
            'nombre' => 'Auditoría', 'primer_apellido' => 'Reparable',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $concepto = DB::table('conceptos_pago')->insertGetId([
            'clave' => 'AUD'.substr(uniqid(), -6), 'nombre' => 'Concepto de auditoría',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::connection('mysql')->statement('SET FOREIGN_KEY_CHECKS=0');

        $vinculo = DB::table('persona_rol')->insertGetId([
            'persona_id' => $persona, 'rol_id' => $rol, 'campus_id' => 987654321,
        ]);

        // Imposible: un adeudo cuya matrícula no existe y sin aspirante, así
        // que el CHECK del titular prohíbe justo el NULL que lo arreglaría.
        $adeudo = DB::table('adeudos')->insertGetId([
            'matricula_oferta_id' => 987654321,
            'concepto_id' => $concepto,
            'monto' => 100, 'monto_total' => 100,
            'fecha_generacion' => now()->toDateString(),
            'fecha_vencimiento' => now()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::connection('mysql')->statement('SET FOREIGN_KEY_CHECKS=1');

        $resultado = $this->reparar([
            ['tabla' => 'adeudos', 'columna' => 'matricula_oferta_id',
                'apunta_a' => 'matricula_oferta', 'referencia' => 'id', 'filas' => 1],
            ['tabla' => 'persona_rol', 'columna' => 'campus_id',
                'apunta_a' => 'campus', 'referencia' => 'id', 'filas' => 1],
        ]);

        // La buena se reparó aunque la primera de la lista fallara.
        $this->assertNull(
            DB::table('persona_rol')->where('id', $vinculo)->value('campus_id'),
            'La columna reparable se quedó sin reparar por culpa de la imposible.',
        );

        // Y la imposible se quedó como estaba, reportada y no en silencio.
        $this->assertSame(
            987654321,
            (int) DB::table('adeudos')->where('id', $adeudo)->value('matricula_oferta_id'),
        );
        $this->assertCount(1, $resultado['imposibles']);
        $this->assertStringContainsString('adeudos.matricula_oferta_id', $resultado['imposibles'][0]);
        $this->assertStringContainsString('chk_adeudos_titular', $resultado['imposibles'][0]);
    }
}
