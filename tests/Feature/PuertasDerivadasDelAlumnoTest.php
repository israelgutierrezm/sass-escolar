<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Identidad\Usuario;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreaEscuelaDePrueba;
use Tests\TenantTestCase;

/**
 * Quien publica puede MIRAR lo que publicó, sin poder pedirlo.
 *
 * ── El problema que resuelven estas dos puertas ────────────────────────────
 * Los recursos digitales y el catálogo de servicios se ven desde el portal del alumno, y
 * sus permisos son del alumno. Quien los administra quedaba fuera: curaba a
 * ciegas —sin ver el orden, qué salió como tarjeta, cómo quedó recortada la
 * portada— y sólo lo veía quien no puede corregirlo.
 *
 * ── Y por qué mirar y pedir se separan ─────────────────────────────────────
 * Derivar `solicitar-servicios` habría abierto la vista y de paso la capacidad
 * de crear solicitudes. Son dos cosas distintas: se deriva un permiso de VER
 * aparte, y crear o cancelar siguen siendo del alumno. Sin esta separación, dar
 * acceso al mostrador regalaba de contrabando el poder de pedir.
 */
class PuertasDerivadasDelAlumnoTest extends TenantTestCase
{
    use CreaEscuelaDePrueba;

    public function test_quien_administra_la_biblioteca_puede_verla(): void
    {
        $usuario = $this->usuarioCon('gestionar-recursos-digitales');

        // Su rol NO tiene el permiso del alumno —eso es lo que resuelve
        // `tienePermiso`, que es por donde pasa `Gate::before`…
        $this->assertFalse($usuario->tienePermiso('ver-recursos-digitales'));

        // …y aun así la puerta abre, por la vía derivada. Comprobar las dos
        // cosas es lo que distingue «se le concedió el permiso» de «entra por
        // otro camino»: sólo con la segunda, la prueba pasaría igual si alguien
        // le regalara el permiso al rol administrativo.
        $this->assertTrue($usuario->can('ver-recursos-digitales'));
    }

    public function test_quien_atiende_el_mostrador_ve_el_catalogo_pero_no_pide(): void
    {
        $usuario = $this->usuarioCon('atender-servicios');

        $this->assertTrue($usuario->can('ver-servicios-del-alumno'), 'Puede revisar su catálogo.');
        $this->assertFalse($usuario->can('solicitar-servicios'), 'Pero pedir sigue siendo del alumno.');
    }

    public function test_el_alumno_entra_por_su_propio_permiso(): void
    {
        $usuario = $this->usuarioCon('solicitar-servicios');

        $this->assertTrue($usuario->can('ver-servicios-del-alumno'));
        $this->assertTrue($usuario->can('solicitar-servicios'));
    }

    /** Y quien no tiene ninguno de los dos, sigue fuera. */
    public function test_un_tercero_no_entra_por_ninguna_de_las_dos_puertas(): void
    {
        $usuario = $this->usuarioCon('ver-alumnos');

        $this->assertFalse($usuario->can('ver-servicios-del-alumno'));
        $this->assertFalse($usuario->can('ver-recursos-digitales'));
    }

    // ── Andamiaje ──────────────────────────────────────────────────────────

    private function usuarioCon(string $permiso): Usuario
    {
        $usuario = $this->usuarioConAlcance();

        Role::findByName('administrativo', 'web')->syncPermissions([
            Permission::findOrCreate($permiso, 'web'),
        ]);

        // El registrar de Spatie cachea en un almacén propio que sobrevive
        // entre pruebas: sin olvidarlo, la segunda sigue viendo los permisos
        // de la primera.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $usuario->fresh();
    }
}
