<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\AvisoParaElUsuario;
use App\Http\Controllers\ConfiguracionEscolarController;
use App\Models\Academico\Carrera;
use App\Models\Academico\PlanEstudio;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreaEscuelaDePrueba;
use Tests\TenantTestCase;

/**
 * A cuántos planes alcanza un cambio de escala.
 *
 * La escala se guarda en el plan, pero la decisión rara vez es de un plan
 * suelto: se toma para una carrera —«aquí calificamos con enteros»— o para un
 * nivel entero —«los posgrados van con dos decimales»—. Aplicarla plan por plan
 * es donde se olvida uno y queda calificando distinto sin que nadie lo note
 * hasta un acta.
 */
class AlcanceDeLaEscalaTest extends TenantTestCase
{
    use CreaEscuelaDePrueba;

    private ConfiguracionEscolarController $controlador;

    protected function setUp(): void
    {
        parent::setUp();

        $this->controlador = app(ConfiguracionEscolarController::class);
    }

    /** Con alcance «plan», sólo ese. */
    public function test_alcance_plan_no_toca_a_los_hermanos(): void
    {
        [$plan, $hermano] = $this->dosPlanesDeLaMismaCarrera();

        $this->guardar($plan, decimales: 0, alcance: 'plan');

        $this->assertSame(0, $plan->fresh()->decimales_calificacion);
        $this->assertSame(2, $hermano->fresh()->decimales_calificacion);
    }

    /** Con «carrera», todos los planes de esa carrera. */
    public function test_alcance_carrera_toca_a_todos_sus_planes(): void
    {
        [$plan, $hermano] = $this->dosPlanesDeLaMismaCarrera();

        $this->guardar($plan, decimales: 0, alcance: 'carrera');

        $this->assertSame(0, $plan->fresh()->decimales_calificacion);
        $this->assertSame(0, $hermano->fresh()->decimales_calificacion);
    }

    /**
     * Con «nivel», los de todas las carreras de ese nivel.
     *
     * Es el caso que se pidió y el que no se podía hacer sin repetirlo carrera
     * por carrera: los planes no llevan el nivel, hay que pasar por ellas.
     */
    public function test_alcance_nivel_cruza_carreras(): void
    {
        [$plan] = $this->dosPlanesDeLaMismaCarrera();
        $nivel = $plan->carrera->nivel_estudios_id;

        $deOtraCarreraMismoNivel = $this->planDeOtraCarrera($nivel);
        $deOtroNivel = $this->planDeOtraCarrera($nivel + 99);

        $this->guardar($plan, decimales: 3, alcance: 'nivel');

        $this->assertSame(3, $deOtraCarreraMismoNivel->fresh()->decimales_calificacion);
        $this->assertSame(2, $deOtroNivel->fresh()->decimales_calificacion, 'No debe alcanzar a otro nivel.');
    }

    /** Tres decimales se aceptan; cuatro no. */
    public function test_el_maximo_configurable_es_tres(): void
    {
        [$plan] = $this->dosPlanesDeLaMismaCarrera();

        $this->guardar($plan, decimales: 3, alcance: 'plan');
        $this->assertSame(3, $plan->fresh()->decimales_calificacion);

        $this->expectException(ValidationException::class);
        $this->guardar($plan, decimales: 4, alcance: 'plan');
    }

    /**
     * La aprobatoria tiene que caber en la escala.
     *
     * Fuera de ella o nadie aprueba nunca o aprueba todo el mundo, y las dos
     * cosas pasan calladas hasta que se cierran actas.
     */
    public function test_rechaza_una_aprobatoria_fuera_de_la_escala(): void
    {
        [$plan] = $this->dosPlanesDeLaMismaCarrera();

        $this->expectException(AvisoParaElUsuario::class);

        $this->guardar($plan, decimales: 1, alcance: 'plan', aprobatoria: 15);
    }

    /**
     * El nivel que se enseña sale del catálogo DE LA ESCUELA.
     *
     * La pantalla agrupa por nivel, y el nombre se pedía a
     * `Landlord\NivelEstudio` —donde vivían los niveles antes de que cada
     * escuela administrara los suyos—. No fallaba: los ids existían, en la
     * tabla equivocada, así que las carreras salían como «Nivel desconocido
     * (#81)» estando perfectamente bien y la pantalla invitaba a «arreglar»
     * datos que no estaban rotos.
     */
    public function test_el_nivel_sale_del_catalogo_de_la_escuela(): void
    {
        [$plan] = $this->dosPlanesDeLaMismaCarrera();

        $nivel = $this->fila('niveles_estudio', [
            'clave' => 'POS-'.uniqid(),
            'nombre' => 'Posgrado de prueba',
            'orden' => 9,
        ]);

        $plan->carrera->update(['nivel_estudios_id' => $nivel]);

        $carrera = $this->carreraEnPantalla($plan->carrera_id);

        $this->assertSame('Posgrado de prueba', $carrera['nivel']);
        $this->assertSame(9, $carrera['nivel_orden']);
    }

    /**
     * Y un nivel que ya no está se dice CON su id.
     *
     * La carrera siempre tiene nivel —la columna no admite nulos—, pero la
     * referencia al catálogo no lleva llave foránea y puede quedar señalando a
     * uno borrado. El id es lo único que permite ir a buscar cuál era.
     */
    public function test_un_nivel_borrado_se_dice_con_su_id(): void
    {
        [$plan] = $this->dosPlanesDeLaMismaCarrera();

        $plan->carrera->update(['nivel_estudios_id' => 999999]);

        $this->assertSame(
            'Nivel desconocido (#999999)',
            $this->carreraEnPantalla($plan->carrera_id)['nivel'],
        );
    }

    // ── Andamiaje ──────────────────────────────────────────────────────────

    /**
     * Cómo llega una carrera a la pantalla.
     *
     * @return array<string, mixed>
     */
    private function carreraEnPantalla(int $carreraId): array
    {
        $peticion = $this->peticionDe($this->usuarioConAlcance(), '/escolar/configuracion');
        $props = $this->propsDe($this->controlador->index($peticion), $peticion);

        $carrera = collect($props['carreras'])->firstWhere('id', $carreraId);

        $this->assertNotNull($carrera, 'La carrera no llegó a la pantalla.');

        return $carrera;
    }

    /** @return array{0: PlanEstudio, 1: PlanEstudio} */
    private function dosPlanesDeLaMismaCarrera(): array
    {
        $escuela = $this->alumnoInscrito();
        $plan = PlanEstudio::findOrFail($escuela['plan']);

        $hermano = PlanEstudio::create([
            'carrera_id' => $plan->carrera_id,
            'clave' => 'PLA-'.uniqid(),
            'nombre' => 'Plan hermano',
            'rvoe' => 'RVOE-001',
            'autorizacion_reconocimiento_id' => $this->deCatalogo('autorizaciones_reconocimiento'),
            'tipo_periodo_id' => $this->deCatalogo('tipos_periodo'),
            'calificacion_minima' => 0,
            'calificacion_maxima' => 10,
            'calificacion_minima_aprobatoria' => 6,
            'minimo_creditos' => 0,
        ]);

        return [$plan, $hermano];
    }

    private function planDeOtraCarrera(int $nivel): PlanEstudio
    {
        $unico = uniqid();

        $carrera = Carrera::create([
            'institucion_id' => Carrera::first()->institucion_id,
            'identificador' => "ID-{$unico}",
            'clave' => "CAR-{$unico}",
            'nombre' => 'Otra carrera',
            'nivel_estudios_id' => $nivel,
        ]);

        return PlanEstudio::create([
            'carrera_id' => $carrera->id,
            'clave' => "PLA-{$unico}",
            'nombre' => 'Plan de otra carrera',
            'rvoe' => 'RVOE-002',
            'autorizacion_reconocimiento_id' => $this->deCatalogo('autorizaciones_reconocimiento'),
            'tipo_periodo_id' => $this->deCatalogo('tipos_periodo'),
            'calificacion_minima' => 0,
            'calificacion_maxima' => 10,
            'calificacion_minima_aprobatoria' => 6,
            'minimo_creditos' => 0,
        ]);
    }

    private function guardar(
        PlanEstudio $plan,
        int $decimales,
        string $alcance,
        float $aprobatoria = 6,
    ): void {
        $peticion = Request::create("/escolar/configuracion/planes/{$plan->id}", 'PUT', [
            'calificacion_minima' => 0,
            'calificacion_maxima' => 10,
            'calificacion_minima_aprobatoria' => $aprobatoria,
            'decimales_calificacion' => $decimales,
            'aplicar_a' => $alcance,
        ]);

        $peticion->setUserResolver(fn () => $this->usuarioConAlcance());

        $this->controlador->guardar($peticion, $plan);
    }
}
