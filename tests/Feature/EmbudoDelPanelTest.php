<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Admisiones\Aspirante;
use App\Models\Admisiones\EtapaCrm;
use App\Models\Identidad\Persona;
use App\Models\Identidad\Usuario;
use App\Panel\Tarjetas\EmbudoDeAdmision;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Concerns\CreaEscuelaDePrueba;
use Tests\TenantTestCase;

/**
 * La barra del embudo se mide contra el TOTAL, no contra la etapa más poblada.
 *
 * Antes el largo salía de dividir cada etapa entre la mayor, y eso sólo dice
 * quién es el más grande: la primera etapa llenaba el ancho completo llevara
 * detrás noventa prospectos o nueve, y la última se veía igual de flaca en los
 * dos casos. El embudo dejaba de responder a lo único que se le pregunta —dónde
 * se está juntando la gente—.
 *
 * Se prueba aquí y no mirando el panel porque el error es MUDO: con datos
 * repartidos parejo, como los de la escuela de ejemplo, las dos fórmulas dibujan
 * exactamente lo mismo y sólo divergen cuando una etapa se dispara.
 */
class EmbudoDelPanelTest extends TenantTestCase
{
    use CreaEscuelaDePrueba;

    public function test_la_parte_es_del_total_y_no_de_la_etapa_mas_poblada(): void
    {
        $etapas = $this->etapasEnOrden();

        // 90 parados en la primera y 10 en la segunda: repartido a propósito
        // desigual, que es donde las dos fórmulas dejan de coincidir.
        $this->prospectosEn($etapas[0], 90);
        $this->prospectosEn($etapas[1], 10);

        $series = $this->series();

        $this->assertSame(90, $series[0]['parte'], 'La primera lleva el 90% del embudo, no el 100%.');
        $this->assertSame(10, $series[1]['parte'], 'La segunda es el 10% del total.');
    }

    /** Las etapas sin nadie siguen viniendo: el hueco es el dato. */
    public function test_una_etapa_vacia_viene_en_cero(): void
    {
        $etapas = $this->etapasEnOrden();
        $this->prospectosEn($etapas[0], 3);

        $series = $this->series();

        $this->assertSame(0, $series[1]['valor']);
        $this->assertSame(0, $series[1]['parte']);
    }

    /**
     * Sin un solo prospecto la tarjeta no se dibuja.
     *
     * Es lo que evita la división entre cero de `parte`, y de paso un embudo con
     * seis barras vacías que no le dice nada a nadie.
     */
    public function test_sin_prospectos_no_hay_tarjeta(): void
    {
        $this->assertNull(app(EmbudoDeAdmision::class)->datos($this->quienVeTodoElEmbudo()));
    }

    // ── Andamiaje ──────────────────────────────────────────────────────────

    /** @return array<int, array<string, mixed>> */
    private function series(): array
    {
        return app(EmbudoDeAdmision::class)->datos($this->quienVeTodoElEmbudo())['series'];
    }

    /**
     * Quien coordina captación, no un promotor.
     *
     * El servicio acota por asignación: un promotor sólo cuenta los prospectos
     * que tiene asignados, y aquí los de prueba no tienen asesor —darían cero y
     * la tarjeta ni se dibujaría—. Lo que se prueba es el reparto, no el
     * alcance, así que se usa el usuario que los ve todos.
     */
    private function quienVeTodoElEmbudo(): Usuario
    {
        $usuario = $this->usuarioConAlcance();

        Role::findByName('administrativo', 'web')->givePermissionTo(
            Permission::firstOrCreate(['name' => 'gestionar-captacion', 'guard_name' => 'web'])
        );

        return $usuario->fresh();
    }

    /**
     * Las etapas del embudo, sembrándolas si la base viene vacía.
     *
     * `etapas_crm` es catálogo configurable: lo llena un seeder al dar de alta
     * la escuela, no la migración. En pruebas se crean aquí las dos que hacen
     * falta —da igual cómo se llamen, lo que se ejercita es el reparto—.
     *
     * @return array<int, EtapaCrm>
     */
    private function etapasEnOrden(): array
    {
        if (EtapaCrm::query()->doesntExist()) {
            EtapaCrm::create(['clave' => 'contacto', 'nombre' => 'Contacto inicial', 'orden' => 1]);
            EtapaCrm::create(['clave' => 'inscrito', 'nombre' => 'Inscrito', 'orden' => 2]);
        }

        return EtapaCrm::query()->orderBy('orden')->get()->all();
    }

    private function prospectosEn(EtapaCrm $etapa, int $cuantos): void
    {
        for ($i = 0; $i < $cuantos; $i++) {
            $persona = Persona::create([
                'nombre' => 'Prospecto '.$i,
                'primer_apellido' => 'De prueba',
            ]);

            Aspirante::create([
                'persona_id' => $persona->id,
                'etapa_crm_id' => $etapa->id,
            ]);
        }
    }
}
