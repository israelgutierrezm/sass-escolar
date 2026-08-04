<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\GrupoController;
use App\Models\ControlEscolar\Grupo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreaEscuelaDePrueba;
use Tests\TenantTestCase;

/**
 * Qué se le exige a un grupo para existir.
 *
 * Antes podía nacer «sin plan fijo», tomando materias de varios, y se pagaba en
 * todo lo que viene después: el grado quedaba numerado con un rango genérico en
 * vez del periodo real del plan —«Periodo 3» donde la escuela dice
 * «Cuatrimestre 3»—, y la inscripción no podía sugerir las materias que tocan
 * porque no sabía de qué malla sacarlas. Un grupo que de verdad cruce planes se
 * abre como dos.
 */
class AltaDeGrupoTest extends TenantTestCase
{
    use CreaEscuelaDePrueba;

    private GrupoController $controlador;

    protected function setUp(): void
    {
        parent::setUp();

        $this->controlador = app(GrupoController::class);

        // El redirect con mensaje flash necesita sesión; en una petición real la
        // pone el middleware.
        Session::start();
    }

    public function test_un_grupo_completo_se_crea(): void
    {
        $escuela = $this->alumnoInscrito();

        // El grupo nace «abierto» y el controlador toma esa situación del
        // catálogo: sin una fila ahí, la creación falla por la llave y no por lo
        // que esta prueba mira.
        $this->deCatalogo('situaciones_grupo');

        $this->controlador->store($this->peticion([
            'campus_id' => $escuela['campus'],
            'plan_id' => $escuela['plan'],
        ]));

        $grupo = Grupo::query()->latest('id')->firstOrFail();

        $this->assertSame($escuela['plan'], $grupo->plan_id);
        $this->assertSame(1, $grupo->semestre);
    }

    /** Sin plan no hay periodo real ni malla de la que sugerir materias. */
    public function test_el_plan_es_obligatorio(): void
    {
        $escuela = $this->alumnoInscrito();

        $this->expectException(ValidationException::class);

        $this->controlador->store($this->peticion([
            'campus_id' => $escuela['campus'],
            'plan_id' => null,
        ]));
    }

    /**
     * El grado dice quiénes cursan el grupo, no qué se imparte: sin él no se
     * sabe a qué generación pertenece.
     */
    public function test_el_grado_es_obligatorio(): void
    {
        $escuela = $this->alumnoInscrito();

        $this->expectException(ValidationException::class);

        $this->controlador->store($this->peticion([
            'campus_id' => $escuela['campus'],
            'plan_id' => $escuela['plan'],
            'semestre' => null,
        ]));
    }

    // ── Andamiaje ──────────────────────────────────────────────────────────

    /** @param  array<string, mixed>  $datos */
    private function peticion(array $datos): Request
    {
        return Request::create('/escolar/grupos', 'POST', [
            'ciclo_id' => $this->cicloDePrueba(),
            'nivel_estudios_id' => DB::connection('central')->table('niveles_estudio')->value('id'),
            'semestre' => 1,
            'clave' => 'G-'.uniqid(),
            'cupo' => 30,
            ...$datos,
        ]);
    }
}
