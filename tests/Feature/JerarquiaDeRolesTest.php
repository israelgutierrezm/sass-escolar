<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Identidad\Persona;
use App\Models\Identidad\Rol;
use App\Models\Identidad\Usuario;
use App\Support\CatalogoPermisos;
use Spatie\Permission\Models\Permission;
use Tests\TenantTestCase;

/**
 * Quién puede hacer qué.
 *
 * Es la única parte del sistema donde un error no se ve: nadie reporta que
 * puede entrar donde no debería. Se comprueban las dos direcciones —que la
 * herencia concede lo que tiene que conceder y que NO concede de más— porque un
 * fallo de cada lado tiene consecuencias opuestas y las dos son malas: o el
 * personal no puede trabajar, o un alumno lee expedientes.
 */
class JerarquiaDeRolesTest extends TenantTestCase
{
    /**
     * Un rol funcional hace todo lo de su faceta y además lo suyo. Es lo que
     * permite crear «encargado de admisiones» sin repetirle los permisos
     * comunes del personal.
     */
    public function test_un_rol_hereda_los_permisos_de_su_faceta(): void
    {
        $administrativo = $this->rol('administrativo');
        $encargado = $this->rol('encargado_admisiones', padre: $administrativo);

        $administrativo->givePermissionTo($this->permiso('ver-alumnos'));
        $encargado->givePermissionTo($this->permiso('gestionar-admisiones'));

        $efectivos = $encargado->permisosEfectivos()->pluck('name')->all();

        $this->assertEqualsCanonicalizing(['ver-alumnos', 'gestionar-admisiones'], $efectivos);
        $this->assertTrue($encargado->concede('ver-alumnos'));
        $this->assertTrue($encargado->concede('gestionar-admisiones'));
    }

    /** La herencia sube, no baja: la faceta no gana lo del hijo. */
    public function test_la_faceta_no_hereda_de_sus_hijos(): void
    {
        $administrativo = $this->rol('administrativo');
        $encargado = $this->rol('encargado_admisiones', padre: $administrativo);

        $encargado->givePermissionTo($this->permiso('gestionar-admisiones'));

        $this->assertFalse($administrativo->concede('gestionar-admisiones'));
    }

    public function test_la_herencia_recorre_toda_la_cadena(): void
    {
        $faceta = $this->rol('administrativo');
        $medio = $this->rol('coordinacion', padre: $faceta);
        $hoja = $this->rol('auxiliar', padre: $medio);

        $faceta->givePermissionTo($this->permiso('ver-alumnos'));

        $this->assertTrue($hoja->concede('ver-alumnos'));
        $this->assertCount(2, $hoja->ancestros());
    }

    /** Un permiso repetido en dos niveles no se cuenta dos veces. */
    public function test_los_permisos_repetidos_no_se_duplican(): void
    {
        $faceta = $this->rol('administrativo');
        $hijo = $this->rol('auxiliar', padre: $faceta);

        $permiso = $this->permiso('ver-alumnos');
        $faceta->givePermissionTo($permiso);
        $hijo->givePermissionTo($permiso);

        $this->assertCount(1, $hijo->permisosEfectivos());
    }

    /**
     * Un rol que desciende de sí mismo no tendría permisos efectivos
     * calculables. La cadena corta el ciclo en vez de colgarse, que es lo que
     * haría un recorrido ingenuo.
     */
    public function test_un_ciclo_en_la_jerarquia_no_cuelga_el_calculo(): void
    {
        $a = $this->rol('a');
        $b = $this->rol('b', padre: $a);

        // Se fuerza el ciclo saltándose la validación, como si la base quedara
        // mal por una edición directa.
        $a->update(['rol_padre_id' => $b->id]);

        $ancestros = $b->fresh()->ancestros();

        $this->assertLessThanOrEqual(2, count($ancestros), 'No se recorre en círculo.');
    }

    public function test_no_se_admite_un_padre_que_formaria_un_ciclo(): void
    {
        $faceta = $this->rol('administrativo');
        $hijo = $this->rol('auxiliar', padre: $faceta);

        $this->assertFalse($faceta->admitePadre($hijo), 'Su propio descendiente no puede ser su padre.');
        $this->assertFalse($faceta->admitePadre($faceta), 'Ni él mismo.');
        $this->assertTrue($hijo->admitePadre($faceta));
        $this->assertTrue($hijo->admitePadre(null), 'Quedarse sin padre lo convierte en faceta.');
    }

    /**
     * El ámbito es lo que decide qué SECCIONES ve alguien, y no basta filtrar
     * por permiso: `capturar-calificaciones` es de admin Y de docente, y por sí
     * solo colaba «Docencia» a un administrativo.
     */
    public function test_el_ambito_sale_de_la_faceta_y_no_del_rol(): void
    {
        $docente = $this->rol(CatalogoPermisos::DOCENTE);
        $titular = $this->rol('docente_titular', padre: $docente);

        $this->assertSame(CatalogoPermisos::DOCENTE, $titular->ambitoDePermisos());
        $this->assertSame(CatalogoPermisos::DOCENTE, $docente->ambitoDePermisos());
    }

    /**
     * Una faceta que inventó la escuela no tiene catálogo propio —nadie declaró
     * qué significa—, así que se trata como personal. Las facetas con portal
     * propio son justamente las protegidas.
     */
    public function test_una_faceta_inventada_cuenta_como_administrativa(): void
    {
        $inventada = $this->rol('consejo_academico');

        $this->assertSame(CatalogoPermisos::ADMINISTRATIVO, $inventada->ambitoDePermisos());
    }

    // ── El rol activo ──────────────────────────────────────────────────────

    /**
     * Defensa contra manipulación del cliente: el rol pedido tiene que estar
     * entre los que la persona tiene ACTIVOS, no entre los que existen.
     */
    public function test_no_se_puede_conmutar_a_un_rol_ajeno(): void
    {
        $docente = $this->rol('docente');
        $director = $this->rol('director_general');

        $usuario = $this->usuarioCon($docente);

        $this->assertTrue($usuario->puedeUsarRol($docente->id));
        $this->assertFalse($usuario->puedeUsarRol($director->id));
        $this->assertFalse($usuario->conmutarRol($director->id));
        $this->assertNotSame($director->id, $usuario->fresh()->rol_activo_id);
    }

    /** Un rol revocado deja de servir aunque la asignación siga en la tabla. */
    public function test_un_rol_desactivado_ya_no_se_puede_usar(): void
    {
        $rol = $this->rol('docente');
        $usuario = $this->usuarioCon($rol);

        $usuario->persona->rolesActivos()->updateExistingPivot($rol->id, ['activo' => false]);

        $this->assertFalse($usuario->fresh()->puedeUsarRol($rol->id));
    }

    public function test_conmutar_a_un_rol_propio_si_funciona(): void
    {
        $docente = $this->rol('docente');
        $tutor = $this->rol('tutor_educativo');

        $usuario = $this->usuarioCon($docente);
        $usuario->persona->rolesActivos()->attach($tutor->id, ['activo' => true]);

        $this->assertTrue($usuario->fresh()->conmutarRol($tutor->id));
        $this->assertSame($tutor->id, $usuario->fresh()->rol_activo_id);
    }

    // ── Andamiaje ──────────────────────────────────────────────────────────

    /**
     * La clave va tal cual y no con un sufijo único: `ambitoDePermisos()`
     * compara contra las claves del catálogo —«docente», «alumno»—, así que un
     * nombre inventado haría pasar por administrativo a cualquiera. Cada prueba
     * corre en su transacción, así que no chocan entre sí.
     */
    private function rol(string $clave, ?Rol $padre = null): Rol
    {
        return Rol::create([
            'name' => $clave,
            'nombre' => ucfirst($clave),
            'guard_name' => 'web',
            'rol_padre_id' => $padre?->id,
        ]);
    }

    private function permiso(string $nombre): Permission
    {
        return Permission::firstOrCreate(['name' => $nombre, 'guard_name' => 'web']);
    }

    private function usuarioCon(Rol $rol): Usuario
    {
        $persona = Persona::create(['nombre' => 'Prueba', 'primer_apellido' => 'Roles']);

        $persona->rolesActivos()->attach($rol->id, ['activo' => true]);

        return Usuario::create([
            'persona_id' => $persona->id,
            'usuario' => 'u'.$persona->id,
            'email' => "u{$persona->id}@escuela.test",
            'password' => 'secreto',
            'rol_activo_id' => $rol->id,
        ]);
    }
}
