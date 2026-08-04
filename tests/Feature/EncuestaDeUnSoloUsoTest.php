<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\TipoPregunta;
use App\Http\Controllers\Encuestas\AplicacionController;
use App\Models\Encuestas\AplicacionEncuesta;
use App\Models\Encuestas\Encuesta;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Tests\TenantTestCase;

/**
 * Encuestas que se aplican una sola vez.
 *
 * Obligar a crear antes una plantilla tiene sentido para lo que se repite cada
 * semestre y estorba para lo que se pregunta una vez: «¿cómo estuvo la feria?»
 * no merece un molde reutilizable que nadie va a volver a usar. Lo que estas
 * pruebas fijan es que ese atajo no rompa lo que la plantilla sí garantiza.
 */
class EncuestaDeUnSoloUsoTest extends TenantTestCase
{
    private AplicacionController $controlador;

    protected function setUp(): void
    {
        parent::setUp();

        $this->controlador = app(AplicacionController::class);

        // `back()` y los mensajes flash necesitan sesión; en una petición real
        // la pone el middleware.
        Session::start();
    }

    public function test_se_crea_una_encuesta_sin_pasar_por_el_catalogo(): void
    {
        $aplicacion = $this->crear(['cuestionario_nuevo' => true, 'titulo' => 'Encuesta de la feria']);
        $encuesta = $aplicacion->encuesta;

        $this->assertSame('Encuesta de la feria', $encuesta->titulo);
        $this->assertFalse($encuesta->es_plantilla, 'No es un molde: no debe ofrecerse para reutilizar.');
        $this->assertSame(0, $encuesta->preguntas()->count(), 'Nace vacía; las preguntas se escriben después.');
    }

    /**
     * Duplicar algo que sólo va a servir aquí dejaría una copia huérfana en el
     * catálogo, sin aplicación y sin nadie que la reclame.
     */
    public function test_la_de_un_solo_uso_no_se_duplica(): void
    {
        $antes = Encuesta::count();

        $this->crear(['cuestionario_nuevo' => true, 'titulo' => 'Encuesta suelta']);

        // Una sola encuesta nueva: la que se acaba de escribir, sin copia.
        $this->assertSame($antes + 1, Encuesta::count());
    }

    /**
     * Desde plantilla SÍ se copia: si la aplicación apuntara al molde, editarlo
     * en marzo cambiaría lo que la gente contestó en febrero.
     */
    public function test_desde_plantilla_se_copia_y_la_plantilla_queda_intacta(): void
    {
        $plantilla = $this->plantilla();

        $aplicacion = $this->crear(['encuesta_id' => $plantilla->id, 'titulo' => 'Aplicación 2026-1']);

        $this->assertNotSame($plantilla->id, $aplicacion->encuesta_id, 'La aplicación usa su copia.');
        $this->assertSame($plantilla->id, $aplicacion->encuesta->origen_id, 'Y la copia recuerda de dónde salió.');
        $this->assertSame(1, $aplicacion->encuesta->preguntas()->count(), 'Con las preguntas copiadas.');
        $this->assertTrue($plantilla->fresh()->es_plantilla, 'La plantilla sigue siendo plantilla.');
    }

    /** El atajo no puede convertirse en una encuesta sin preguntas ni molde. */
    public function test_hay_que_elegir_una_de_las_dos_vias(): void
    {
        $this->expectException(ValidationException::class);

        // Ni cuestionario existente ni la marca de escribir uno nuevo.
        $this->crear(['titulo' => 'Sin preguntas de ningún lado']);
    }

    /**
     * La de un solo uso no debe aparecer donde se eligen moldes: el catálogo de
     * plantillas se llenaría de encuestas irrepetibles.
     */
    public function test_no_aparece_entre_las_plantillas(): void
    {
        $plantilla = $this->plantilla();
        $this->crear(['cuestionario_nuevo' => true, 'titulo' => 'Encuesta suelta']);

        $plantillas = Encuesta::plantillas()->pluck('titulo')->all();

        $this->assertContains($plantilla->titulo, $plantillas);
        $this->assertNotContains('Encuesta suelta', $plantillas);
    }

    /**
     * Si una encuesta pensada para una vez resulta que servía siempre,
     * duplicarla la convierte en plantilla sin tocar la original ni sus
     * resultados.
     */
    public function test_una_de_un_solo_uso_puede_ascender_a_plantilla(): void
    {
        $suelta = $this->crear(['cuestionario_nuevo' => true, 'titulo' => 'Encuesta suelta'])->encuesta;
        $suelta->preguntas()->create([
            'texto' => '¿Qué te pareció?',
            'tipo' => TipoPregunta::Escala,
            'config' => ['maximo' => 5],
            'orden' => 1,
        ]);

        $molde = $suelta->duplicar('Encuesta de eventos', comoPlantilla: true);

        $this->assertTrue($molde->es_plantilla);
        $this->assertSame(1, $molde->preguntas()->count());
        $this->assertFalse($suelta->fresh()->es_plantilla, 'La original no cambia de naturaleza.');
    }

    // ── Andamiaje ──────────────────────────────────────────────────────────

    /**
     * Crea la aplicación y DEVUELVE la creada.
     *
     * Buscarla después con `first()` haría que la prueba dependiera de que no
     * haya nada más en la base, y basta un residuo para que falle por el motivo
     * equivocado.
     *
     * @param  array<string, mixed>  $datos
     */
    private function crear(array $datos): AplicacionEncuesta
    {
        $peticion = Request::create('/encuestas/aplicaciones', 'POST', [
            'titulo' => 'Aplicación de prueba',
            'tipo' => AplicacionEncuesta::GENERAL,
            'obligatoria' => false,
            'anonima' => true,
            'destinos' => [['tipo' => 'todos', 'destino_id' => null]],
            ...$datos,
        ]);

        $this->controlador->guardar($peticion);

        return AplicacionEncuesta::query()->latest('id')->firstOrFail();
    }

    private function plantilla(): Encuesta
    {
        $encuesta = Encuesta::create(['titulo' => 'Evaluación docente estándar', 'es_plantilla' => true]);

        $encuesta->preguntas()->create([
            'texto' => 'El docente explica con claridad',
            'tipo' => TipoPregunta::Escala,
            'config' => ['maximo' => 5],
            'orden' => 1,
        ]);

        return $encuesta;
    }
}
