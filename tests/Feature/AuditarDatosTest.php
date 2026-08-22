<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\AuditarDatos;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
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
}
