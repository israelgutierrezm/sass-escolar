<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\EsquemaEvaluacionController;
use App\Models\Academico\EsquemaEvaluacion;
use App\Models\Academico\PlanEstudio;
use App\Models\Academico\PlanMateria;
use App\Models\Academico\PlantillaEvaluacion;
use App\Services\AplicadorPlantillaEvaluacion;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreaEscuelaDePrueba;
use Tests\TenantTestCase;

/**
 * Retirar un componente del esquema de evaluación.
 *
 * ── Lo que fallaba ────────────────────────────────────────────────────────
 * `EsquemaEvaluacion` lleva `TieneAuditoria`, o sea borrado LÓGICO: la foránea
 * de `calificaciones_componente` nunca se dispara. Borrar un componente con
 * calificaciones capturadas devolvía éxito y dejaba los números colgando de una
 * fila invisible. No es un error ruidoso: el esquema pasa a sumar 90 %, la
 * calificación final deja de calcularse, y si alguien agrega otro componente
 * para volver a llegar a 100, lo que el docente capturó queda enterrado sin que
 * nada lo señale.
 *
 * ── Y lo contrario también sería un defecto ───────────────────────────────
 * Guardar la hoja de captura escribe una fila por alumno, con NULL donde el
 * docente no llegó. Si esas contaran como calificaciones, abrir la pantalla una
 * vez congelaría el esquema para siempre. Las dos direcciones se prueban.
 */
class RetirarComponenteDeEvaluacionTest extends TenantTestCase
{
    use CreaEscuelaDePrueba;

    /** @return array{componente: EsquemaEvaluacion, materia: PlanMateria, plan: PlanEstudio, inscripcion: int} */
    private function escenario(): array
    {
        $escuela = $this->alumnoInscrito();
        $ciclo = $this->cicloDePrueba();
        $abierta = $this->materiaAbierta($escuela['plan'], $escuela['campus'], $ciclo);

        $inscripcion = $this->fila('inscripcion', [
            'matricula_oferta_id' => $escuela['matricula'],
            'asignatura_grupo_id' => $abierta['materia'],
            'ciclo_id' => $ciclo,
            'tipo' => 'ordinaria',
            'forma_inscripcion' => 'administrativa',
            'situacion_id' => $this->deCatalogo('situaciones_inscripcion'),
        ]);

        $componente = EsquemaEvaluacion::create([
            'plan_materia_id' => $abierta['planMateria'],
            'componente' => 'examen_p1',
            'parcial' => 1,
            'porcentaje' => 40,
            'orden' => 1,
        ]);

        return [
            'componente' => $componente,
            'materia' => PlanMateria::findOrFail($abierta['planMateria']),
            'plan' => PlanEstudio::findOrFail($escuela['plan']),
            'inscripcion' => $inscripcion,
        ];
    }

    private function capturar(int $inscripcion, EsquemaEvaluacion $componente, ?float $valor): int
    {
        return $this->fila('calificaciones_componente', [
            'inscripcion_id' => $inscripcion,
            'esquema_evaluacion_id' => $componente->id,
            'calificacion' => $valor,
            'fuente' => 'manual',
        ]);
    }

    private function retirar(array $caso)
    {
        return app(EsquemaEvaluacionController::class)
            ->destroy($caso['plan'], $caso['materia'], $caso['componente']);
    }

    public function test_sin_nada_capturado_el_componente_se_retira(): void
    {
        $caso = $this->escenario();

        $this->retirar($caso);

        $this->assertSoftDeleted('esquema_evaluacion', ['id' => $caso['componente']->id]);
    }

    public function test_con_una_calificacion_capturada_no_se_retira_y_se_explica(): void
    {
        $caso = $this->escenario();
        $this->capturar($caso['inscripcion'], $caso['componente'], 8.5);

        $respuesta = $this->retirar($caso);

        $this->assertDatabaseHas('esquema_evaluacion', [
            'id' => $caso['componente']->id,
            'deleted_at' => null,
        ]);

        $motivo = $respuesta->getSession()->get('error');
        $this->assertNotNull($motivo, 'Se retiró el componente sin decir nada.');
        $this->assertStringContainsString('calificación capturada', $motivo);
    }

    public function test_lo_capturado_sigue_intacto_y_visible(): void
    {
        $caso = $this->escenario();
        $calificacion = $this->capturar($caso['inscripcion'], $caso['componente'], 8.5);

        $this->retirar($caso);

        // No basta con que no quede colgando: perderla también «arreglaría» el
        // huérfano, y sería peor. Lo que debe cumplirse es que el 8.5 siga ahí
        // y siga colgando de un componente que se ve.
        $fila = DB::table('calificaciones_componente as c')
            ->join('esquema_evaluacion as e', 'e.id', '=', 'c.esquema_evaluacion_id')
            ->where('c.id', $calificacion)
            ->whereNull('c.deleted_at')
            ->whereNull('e.deleted_at')
            ->value('c.calificacion');

        $this->assertNotNull($fila, 'La calificación capturada se perdió o quedó colgando.');
        $this->assertSame(8.5, (float) $fila);
    }

    public function test_una_celda_en_blanco_no_congela_el_esquema(): void
    {
        $caso = $this->escenario();
        $blanco = $this->capturar($caso['inscripcion'], $caso['componente'], null);

        $this->retirar($caso);

        $this->assertSoftDeleted('esquema_evaluacion', ['id' => $caso['componente']->id]);
        // Y el rastro del blanco se va con él, en vez de quedar suelto.
        $this->assertSoftDeleted('calificaciones_componente', ['id' => $blanco]);
    }

    /**
     * La otra mitad de la misma definición.
     *
     * `AplicadorPlantillaEvaluacion` se niega a re-aplicar una plantilla sobre
     * una materia con calificaciones capturadas, y con razón: cambiarle el
     * criterio a media evaluación movería números que un docente ya puso. Pero
     * contaba FILAS, no calificaciones — así que un docente que abrió la hoja y
     * guardó sin llenar nada dejaba la materia bloqueada para siempre, sin
     * haber calificado a nadie y sin que nada lo explicara.
     */
    public function test_una_celda_en_blanco_no_impide_reaplicar_la_plantilla(): void
    {
        $caso = $this->escenario();
        $this->capturar($caso['inscripcion'], $caso['componente'], null);

        $plantilla = PlantillaEvaluacion::create([
            'clave' => 'PLT-'.uniqid(),
            'nombre' => 'Plantilla de prueba',
            'activa' => true,
        ]);
        $this->fila('plantilla_componentes', [
            'plantilla_id' => $plantilla->id,
            'componente' => 'unico',
            'parcial' => 1,
            'porcentaje' => 100,
            'orden' => 1,
        ]);

        app(AplicadorPlantillaEvaluacion::class)->aplicarAMateria($plantilla, $caso['materia']);

        $this->assertDatabaseHas('esquema_evaluacion', [
            'plan_materia_id' => $caso['materia']->id,
            'componente' => 'unico',
            'deleted_at' => null,
        ]);
    }

    public function test_con_algo_capturado_de_verdad_la_plantilla_no_se_reaplica(): void
    {
        $caso = $this->escenario();
        $this->capturar($caso['inscripcion'], $caso['componente'], 7.0);

        $plantilla = PlantillaEvaluacion::create([
            'clave' => 'PLT-'.uniqid(),
            'nombre' => 'Plantilla de prueba',
            'activa' => true,
        ]);
        $this->fila('plantilla_componentes', [
            'plantilla_id' => $plantilla->id,
            'componente' => 'unico',
            'parcial' => 1,
            'porcentaje' => 100,
            'orden' => 1,
        ]);

        $this->expectException(\RuntimeException::class);

        app(AplicadorPlantillaEvaluacion::class)->aplicarAMateria($plantilla, $caso['materia']);
    }

    public function test_una_actividad_que_pondera_ahi_tambien_lo_sostiene(): void
    {
        $caso = $this->escenario();

        $curso = $this->fila('cursos', ['titulo' => 'Curso de prueba']);
        $this->fila('actividades', [
            'curso_id' => $curso,
            'esquema_evaluacion_id' => $caso['componente']->id,
            'titulo' => 'Ensayo',
            'tipo' => 'tarea',
            'puntos' => 10,
            'orden' => 1,
        ]);

        $respuesta = $this->retirar($caso);

        $this->assertDatabaseHas('esquema_evaluacion', [
            'id' => $caso['componente']->id,
            'deleted_at' => null,
        ]);
        $this->assertStringContainsString('actividad', (string) $respuesta->getSession()->get('error'));
    }
}
