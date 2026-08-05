<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\AspiranteController;
use App\Http\Controllers\DocenteController;
use App\Models\Admisiones\Aspirante;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\CreaEscuelaDePrueba;
use Tests\TenantTestCase;

/**
 * Cada quien ve lo de sus campus.
 *
 * `persona_rol.campus_id` existe desde el principio para decir «coordinador del
 * Campus Norte», pero cada pantalla tenía que acordarse de usarlo y varias no lo
 * hacían: el listado de aspirantes y el de docentes mostraban la escuela entera,
 * así que quien administra una sede veía —y podía editar— los prospectos y el
 * personal de la otra.
 *
 * Filtrar la lista no basta y por eso hay pruebas de las dos cosas: el id de un
 * registro ajeno viaja en la URL, y un POST a mano se salta cualquier filtro que
 * sólo viva en la consulta del listado.
 */
class AlcancePorCampusTest extends TenantTestCase
{
    use CreaEscuelaDePrueba;

    public function test_solo_ve_los_aspirantes_de_su_campus(): void
    {
        [$mio, $ajeno] = $this->dosCampus();
        $delMio = $this->aspirante($mio, 'Propio');
        $delAjeno = $this->aspirante($ajeno, 'Ajeno');

        $vistos = $this->aspirantesQueVe([$mio]);

        $this->assertContains($delMio, $vistos);
        $this->assertNotContains($delAjeno, $vistos);
    }

    /** Con dos campus asignados ve los dos: el alcance es la unión, no uno solo. */
    public function test_con_dos_campus_ve_los_dos(): void
    {
        [$uno, $otro] = $this->dosCampus();
        $a = $this->aspirante($uno, 'De uno');
        $b = $this->aspirante($otro, 'De otro');

        $vistos = $this->aspirantesQueVe([$uno, $otro]);

        $this->assertContains($a, $vistos);
        $this->assertContains($b, $vistos);
    }

    /** Sin campus asignado el rol es global: ve la escuela entera. */
    public function test_el_rol_global_los_ve_todos(): void
    {
        [$uno, $otro] = $this->dosCampus();
        $a = $this->aspirante($uno, 'De uno');
        $b = $this->aspirante($otro, 'De otro');

        $vistos = $this->aspirantesQueVe([]);

        $this->assertContains($a, $vistos);
        $this->assertContains($b, $vistos);
    }

    /**
     * Un prospecto que todavía no eligió a dónde quiere entrar no es de nadie.
     * Esconderlo de todos lo convierte en un contacto que nunca se atiende.
     */
    public function test_el_aspirante_sin_campus_lo_ve_cualquiera(): void
    {
        [$mio] = $this->dosCampus();
        $sinCampus = $this->aspirante(null, 'Sin decidir');

        $this->assertContains($sinCampus, $this->aspirantesQueVe([$mio]));
    }

    /** El id viaja en la URL: filtrar el listado no cierra la ficha. */
    public function test_no_se_abre_la_ficha_de_un_aspirante_ajeno(): void
    {
        [$mio, $ajeno] = $this->dosCampus();
        $delAjeno = Aspirante::findOrFail($this->aspirante($ajeno, 'Ajeno'));

        $this->expectException(HttpException::class);

        app(AspiranteController::class)->show(
            $this->peticionDe($this->usuarioConAlcance([$mio])),
            $delAjeno,
            app(\App\Services\ConvertidorAspirante::class),
        );
    }

    public function test_solo_ve_los_docentes_de_su_campus(): void
    {
        [$mio, $ajeno] = $this->dosCampus();
        $delMio = $this->docente($mio);
        $delAjeno = $this->docente($ajeno);
        $sinCampus = $this->docente(null);

        $peticion = $this->peticionDe($this->usuarioConAlcance([$mio]), '/escolar/docentes');

        $ids = collect($this->propsDe(app(DocenteController::class)->index($peticion), $peticion)['docentes']['data'] ?? [])
            ->pluck('id')->all();

        $this->assertContains($delMio, $ids);
        $this->assertNotContains($delAjeno, $ids);
        $this->assertContains($sinCampus, $ids, 'Uno recién dado de alta no debe desaparecer de quien le asigna campus.');
    }

    // ── Andamiaje ──────────────────────────────────────────────────────────

    /** @return array<int, int> */
    private function dosCampus(): array
    {
        $unico = uniqid();
        $institucion = $this->fila('instituciones', ['clave' => "INS-{$unico}", 'nombre' => 'Institución']);

        return [
            $this->fila('campus', ['clave' => "A-{$unico}", 'nombre' => 'Campus A', 'institucion_id' => $institucion]),
            $this->fila('campus', ['clave' => "B-{$unico}", 'nombre' => 'Campus B', 'institucion_id' => $institucion]),
        ];
    }

    private function aspirante(?int $campus, string $nombre): int
    {
        $persona = $this->fila('personas', ['nombre' => $nombre, 'primer_apellido' => 'Prospecto']);

        return $this->fila('aspirantes', [
            'persona_id' => $persona,
            'campus_id' => $campus,
            'situacion_id' => $this->deCatalogo('situaciones_aspirante'),
        ]);
    }

    private function docente(?int $campus): int
    {
        $persona = $this->fila('personas', ['nombre' => 'Docente', 'primer_apellido' => 'De prueba']);

        DB::table('docentes')->insert([
            'persona_id' => $persona,
            'situacion_id' => $this->deCatalogo('situaciones_docente'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($campus !== null) {
            DB::table('campus_docente')->insert(['persona_id' => $persona, 'campus_id' => $campus]);
        }

        return $persona;
    }

    /** @return array<int, int> */
    private function aspirantesQueVe(array $campusIds): array
    {
        $peticion = $this->peticionDe($this->usuarioConAlcance($campusIds), '/aspirantes');

        return collect($this->propsDe(app(AspiranteController::class)->index($peticion), $peticion)['aspirantes']['data'] ?? [])
            ->pluck('id')->all();
    }
}
