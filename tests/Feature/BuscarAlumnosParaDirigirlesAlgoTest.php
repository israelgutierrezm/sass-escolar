<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\BuscadorAlumnosController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Tests\Concerns\CreaEscuelaDePrueba;
use Tests\TenantTestCase;

/**
 * El buscador de alumnos que comparten calendario, avisos y encuestas.
 *
 * ── Lo que estaba roto ────────────────────────────────────────────────────
 * La búsqueda vivía dentro del calendario, y las pantallas de avisos y de
 * encuestas apuntaban a `/api/buscar/alumnos`: una dirección que nunca existió.
 * `BuscadorRemoto` sólo tiene `finally`, sin `catch`, así que el 404 dejaba la
 * caja en blanco —igual que si no hubiera resultados—. Elegir «alumnos
 * señalados uno por uno» simplemente no funcionaba en dos de los tres módulos,
 * y nada lo decía.
 */
class BuscarAlumnosParaDirigirlesAlgoTest extends TenantTestCase
{
    use CreaEscuelaDePrueba;

    private function buscar(string $texto): array
    {
        $respuesta = app(BuscadorAlumnosController::class)(
            Request::create('/buscar/alumnos', 'GET', ['q' => $texto])
        );

        return json_decode($respuesta->getContent(), true);
    }

    /** Un usuario cuyo rol activo tiene exactamente ese permiso y ninguno más. */
    private function usuarioCon(string $permiso)
    {
        $usuario = $this->usuarioConAlcance([], 'rol-'.substr(md5($permiso), 0, 8));

        DB::table('role_has_permissions')->insertOrIgnore([
            'role_id' => $usuario->rol_activo_id,
            'permission_id' => Permission::findOrCreate($permiso, 'web')->id,
        ]);

        return $usuario;
    }

    public function test_encuentra_por_nombre_y_por_matricula(): void
    {
        $escuela = $this->alumnoInscrito();
        $matricula = DB::table('matricula_oferta')->where('id', $escuela['matricula'])->value('matricula');

        DB::table('personas')->where('id', $escuela['persona'])->update([
            'nombre' => 'Ixchel', 'primer_apellido' => 'Balam',
        ]);

        $this->assertNotEmpty($this->buscar('Ixchel'), 'No se encontró por nombre.');
        $this->assertNotEmpty($this->buscar($matricula), 'No se encontró por matrícula.');
    }

    public function test_con_una_sola_letra_no_devuelve_medio_padron(): void
    {
        // Con alguien a quien SÍ encontraría: sin este alumno la prueba pasaba
        // por no haber a quién traer, no por el corte.
        $escuela = $this->alumnoInscrito();
        DB::table('personas')->where('id', $escuela['persona'])->update([
            'nombre' => 'Ana', 'primer_apellido' => 'Zapata',
        ]);

        $this->assertNotEmpty($this->buscar('An'), 'Con dos letras debería encontrarla.');
        $this->assertSame([], $this->buscar('A'));
    }

    public function test_quien_estudia_dos_carreras_aparece_una_vez(): void
    {
        $escuela = $this->alumnoInscrito();

        DB::table('personas')->where('id', $escuela['persona'])->update([
            'nombre' => 'Tenoch', 'primer_apellido' => 'Iturbide',
        ]);

        // Segunda matrícula de la MISMA persona, en OTRA oferta: el destino se
        // guarda contra la persona, así que verla dos veces en la lista sería
        // elegir dos veces al mismo alumno.
        // En otro campus: el único de `oferta` es (carrera, plan, campus).
        $otroCampus = $this->fila('campus', [
            'clave' => 'C2-'.uniqid(),
            'nombre' => 'Segundo campus',
        ]);

        $otraOferta = $this->fila('oferta', [
            'carrera_id' => $escuela['carrera'],
            'plan_id' => $escuela['plan'],
            'campus_id' => $otroCampus,
            'estatus' => 'activa',
        ]);

        $this->fila('matricula_oferta', [
            'persona_id' => $escuela['persona'],
            'oferta_id' => $otraOferta,
            'matricula' => 'MAT-SEGUNDA-'.uniqid(),
            'fecha_ingreso' => '2026-01-01',
            'situacion_id' => $this->deCatalogo('situaciones_alumno'),
            'estatus' => 'activo',
        ]);

        $this->assertCount(1, $this->buscar('Tenoch'));
    }

    /**
     * La puerta la abren tres permisos, y ésa es la corrección de fondo: antes
     * la búsqueda colgaba de `gestionar-calendario`, así que quien administra
     * avisos o encuestas no tenía ningún endpoint que funcionara.
     */
    public function test_los_tres_oficios_entran_por_la_misma_puerta(): void
    {
        foreach (['gestionar-calendario', 'gestionar-avisos', 'gestionar-encuestas'] as $permiso) {
            $usuario = $this->usuarioCon($permiso);

            $this->assertTrue(
                Gate::forUser($usuario)->allows('dirigir-a-alumnos'),
                "Con «{$permiso}» no se puede buscar alumnos a quién dirigirse.",
            );
        }
    }

    public function test_quien_no_dirige_nada_a_nadie_no_entra(): void
    {
        $usuario = $this->usuarioCon('ver-alumnos');

        $this->assertFalse(Gate::forUser($usuario)->allows('dirigir-a-alumnos'));
    }
}
