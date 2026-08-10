<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Identidad\DisposicionPanel;
use App\Models\Identidad\Usuario;
use App\Panel\DisposicionDelPanel;
use Tests\Concerns\CreaEscuelaDePrueba;
use Tests\TenantTestCase;

/**
 * Cada quien acomoda su panel, y lo que acomodó no puede enseñarle de más.
 *
 * ── Lo que estas pruebas cuidan ────────────────────────────────────────────
 * La disposición se aplica al FINAL, sobre la lista que el permiso ya filtró.
 * Esa separación es la única razón por la que guardar una clave cualquiera no
 * es un agujero: si algún día se aplicara antes de filtrar —o el guardado
 * empezara a decidir qué se ve—, una preferencia escrita a mano se convertiría
 * en una manera de sacar tarjetas ajenas. No lo avisaría nada: la pantalla se
 * vería bien y de más.
 *
 * Lo demás son los tres casos que rompen un acomodo guardado en cuanto la
 * escuela cambia: una tarjeta nueva que aparece, una que desaparece porque le
 * quitaron el permiso, y el mismo usuario con dos perfiles.
 */
class DisposicionDelPanelTest extends TenantTestCase
{
    use CreaEscuelaDePrueba;

    public function test_reordena_y_redimensiona_lo_que_se_guardo(): void
    {
        $usuario = $this->usuarioConAlcance();

        $this->servicio()->guardar($usuario, [
            ['clave' => 'segunda', 'ancho' => 4],
            ['clave' => 'primera', 'ancho' => 2],
        ]);

        $resultado = $this->servicio()->aplicar($this->tarjetas(['primera', 'segunda']), $usuario);

        $this->assertSame(['segunda', 'primera'], array_column($resultado, 'clave'));
        $this->assertSame(4, $resultado[0]['ancho'], 'La segunda quedó al ancho doble.');
    }

    /**
     * Una tarjeta que no estaba cuando se guardó sale AL FINAL, no se pierde.
     *
     * Es el caso de todos los días: se agrega una tarjeta al sistema, o alguien
     * gana un permiso. Si las desconocidas se descartaran, el panel de quien ya
     * había acomodado se quedaría congelado en el pasado sin que nadie entienda
     * por qué a él no le aparece lo nuevo.
     */
    public function test_una_tarjeta_sin_preferencia_va_al_final(): void
    {
        $usuario = $this->usuarioConAlcance();

        $this->servicio()->guardar($usuario, [['clave' => 'segunda', 'ancho' => 2]]);

        $resultado = $this->servicio()->aplicar($this->tarjetas(['primera', 'segunda', 'tercera']), $usuario);

        $this->assertSame(['segunda', 'primera', 'tercera'], array_column($resultado, 'clave'));
    }

    /**
     * Y una guardada que hoy ya no se ve no deja hueco ni la resucita.
     *
     * Éste es el que sostiene que el guardado no necesite comprobar permisos:
     * la clave se queda escrita, pero al aplicarse no encuentra pareja.
     */
    public function test_una_clave_guardada_que_ya_no_se_ve_se_ignora(): void
    {
        $usuario = $this->usuarioConAlcance();

        $this->servicio()->guardar($usuario, [
            ['clave' => 'ajena', 'ancho' => 4],
            ['clave' => 'primera', 'ancho' => 2],
        ]);

        $resultado = $this->servicio()->aplicar($this->tarjetas(['primera']), $usuario);

        $this->assertSame(['primera'], array_column($resultado, 'clave'));
    }

    /** Un ancho que no es de los dos permitidos se cae al normal. */
    public function test_el_ancho_se_acota_en_el_servidor(): void
    {
        $usuario = $this->usuarioConAlcance();

        $this->servicio()->guardar($usuario, [['clave' => 'primera', 'ancho' => 99]]);

        $this->assertSame(2, DisposicionPanel::query()->where('clave', 'primera')->value('ancho'));
    }

    /**
     * Cada perfil guarda el suyo.
     *
     * Alguien que es coordinadora por la mañana y docente por la tarde ve dos
     * paneles distintos; acomodar uno no puede descolocar el otro.
     */
    public function test_cada_perfil_guarda_su_propio_acomodo(): void
    {
        $usuario = $this->usuarioConAlcance();
        $otroRol = $usuario->rol_activo_id + 1000;

        $this->servicio()->guardar($usuario, [
            ['clave' => 'segunda', 'ancho' => 4],
            ['clave' => 'primera', 'ancho' => 2],
        ]);

        // El mismo usuario operando con otro perfil: sin acomodo propio, ve el
        // orden de fábrica y no el que dejó en el primero.
        $usuario->rol_activo_id = $otroRol;

        $resultado = $this->servicio()->aplicar($this->tarjetas(['primera', 'segunda']), $usuario);

        $this->assertSame(['primera', 'segunda'], array_column($resultado, 'clave'));
        $this->assertSame(2, $resultado[1]['ancho'], 'El ancho doble era del otro perfil.');
    }

    /** Guardar reemplaza: no se van acumulando acomodos viejos. */
    public function test_guardar_reemplaza_el_acomodo_anterior(): void
    {
        $usuario = $this->usuarioConAlcance();

        $this->servicio()->guardar($usuario, [
            ['clave' => 'primera', 'ancho' => 2],
            ['clave' => 'segunda', 'ancho' => 4],
        ]);
        $this->servicio()->guardar($usuario, [['clave' => 'segunda', 'ancho' => 2]]);

        $this->assertSame(
            ['segunda'],
            DisposicionPanel::query()->where('usuario_id', $usuario->id)->pluck('clave')->all(),
        );
    }

    public function test_olvidar_devuelve_el_panel_de_fabrica(): void
    {
        $usuario = $this->usuarioConAlcance();

        $this->servicio()->guardar($usuario, [['clave' => 'segunda', 'ancho' => 4]]);
        $this->servicio()->olvidar($usuario);

        $resultado = $this->servicio()->aplicar($this->tarjetas(['primera', 'segunda']), $usuario);

        $this->assertSame(['primera', 'segunda'], array_column($resultado, 'clave'));
        $this->assertSame(2, $resultado[1]['ancho']);
    }

    // ── Andamiaje ──────────────────────────────────────────────────────────

    private function servicio(): DisposicionDelPanel
    {
        return app(DisposicionDelPanel::class);
    }

    /**
     * Tarjetas de mentira: sólo hacen falta la clave y el ancho, que es lo
     * único que el servicio mira.
     *
     * @param  array<int, string>  $claves
     * @return array<int, array<string, mixed>>
     */
    private function tarjetas(array $claves): array
    {
        return array_map(fn (string $clave) => ['clave' => $clave, 'ancho' => 2], $claves);
    }
}
