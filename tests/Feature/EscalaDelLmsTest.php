<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Academico\EsquemaEvaluacion;
use App\Models\Academico\PlanEstudio;
use App\Models\ControlEscolar\CalificacionComponente;
use App\Models\Lms\Actividad;
use App\Models\Lms\Curso;
use App\Models\Lms\Entrega;
use App\Http\Controllers\DocenciaController;
use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Services\Lms\CalculadorComponente;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreaEscuelaDePrueba;
use Tests\TenantTestCase;

/**
 * El LMS califica por PUNTOS; la escuela pone actas en su ESCALA.
 *
 * ── Por qué importa tanto ──────────────────────────────────────────────────
 * Todo el módulo daba por hecho que la escala era 0–10 y multiplicaba por diez.
 * En una escuela que califica sobre 100 eso convierte un examen perfecto en un
 * 10, y como la calificación de la actividad ENTRA SOLA al componente del
 * parcial, ese número no se queda en la pantalla: llega al acta y al kárdex.
 *
 * No revienta nada —un 10 es una calificación perfectamente válida—, y por eso
 * hace falta una prueba: es un error que se asienta en silencio.
 */
class EscalaDelLmsTest extends TenantTestCase
{
    use CreaEscuelaDePrueba;

    private CalculadorComponente $calculador;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculador = new CalculadorComponente;
    }

    /** La escala de siempre sigue dando lo de siempre. */
    public function test_sobre_diez_no_cambia_nada(): void
    {
        $caso = $this->materiaConLms(minima: 0, maxima: 10);
        $this->entregar($caso, obtenidos: 32, puntos: 40);

        $this->assertSame(8.0, $this->calificacionDelComponente($caso));
    }

    /**
     * El caso que motivó todo: una escuela que califica sobre 100.
     *
     * 32 de 40 puntos son un 80, no un 8. Con el 8 el alumno aparecía
     * reprobadísimo en un plan donde se aprueba con 70.
     */
    public function test_sobre_cien_no_se_encoge_a_diez(): void
    {
        $caso = $this->materiaConLms(minima: 0, maxima: 100, aprobatoria: 70);
        $this->entregar($caso, obtenidos: 32, puntos: 40);

        $this->assertSame(80.0, $this->calificacionDelComponente($caso));
    }

    /**
     * En una escala que no empieza en cero, no entregar nada no es un cero.
     *
     * De 5 a 10: la mitad de los puntos es 7.5, no 5. Una regla de tres sobre
     * la máxima daría 5 —el suelo— para quien contestó medio examen bien.
     */
    public function test_una_escala_que_empieza_en_cinco_mapea_lineal(): void
    {
        $caso = $this->materiaConLms(minima: 5, maxima: 10, aprobatoria: 6);
        $this->entregar($caso, obtenidos: 20, puntos: 40);

        $this->assertSame(7.5, $this->calificacionDelComponente($caso));
    }

    /**
     * La precisión también sale del plan.
     *
     * Un plan de enteros no debe recibir del LMS un 8.33 que la captura a mano
     * habría rechazado.
     */
    public function test_respeta_los_decimales_del_plan(): void
    {
        $caso = $this->materiaConLms(minima: 0, maxima: 10, decimales: 0);
        // 25 de 30 son 8.333…
        $this->entregar($caso, obtenidos: 25, puntos: 30);

        $this->assertSame(8.0, $this->calificacionDelComponente($caso));
    }

    /** Y con dos decimales, el mismo caso conserva los suyos. */
    public function test_con_dos_decimales_no_se_recorta(): void
    {
        $caso = $this->materiaConLms(minima: 0, maxima: 10, decimales: 2);
        $this->entregar($caso, obtenidos: 25, puntos: 30);

        $this->assertSame(8.33, $this->calificacionDelComponente($caso));
    }

    /** El ponderado por puntos sigue siendo por puntos, en cualquier escala. */
    public function test_pondera_por_puntos_en_la_escala_del_plan(): void
    {
        $caso = $this->materiaConLms(minima: 0, maxima: 100, aprobatoria: 70);

        // Una tarea de 10 con 10, y un examen de 90 con 45: 55 de 100.
        $this->entregar($caso, obtenidos: 10, puntos: 10);
        $this->entregar($caso, obtenidos: 45, puntos: 90);

        $this->assertSame(55.0, $this->calificacionDelComponente($caso));
    }

    /**
     * La escala llega a la pantalla del docente.
     *
     * Se prueba porque el modo de romperlo no se ve: la materia carga el plan
     * con `plan:id,nombre`, y un plan a medias no revienta —devuelve null en
     * cada columna que falta y la pantalla se queda con el 0–10 por omisión—.
     */
    public function test_la_pantalla_del_docente_recibe_la_escala(): void
    {
        $caso = $this->materiaConLms(minima: 0, maxima: 100, aprobatoria: 70);

        $usuario = $this->usuarioConAlcance(rol: 'docente');
        $this->fila('docentes', [
            'persona_id' => $usuario->persona_id,
            'situacion_id' => $this->deCatalogo('situaciones_docente'),
        ]);
        DB::table('docente_asignatura_grupo')->insert([
            'persona_id' => $usuario->persona_id,
            'tipo' => 'titular',
            'asignatura_grupo_id' => $caso['grupo'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $peticion = $this->peticionDe($usuario, '/docencia/materias/'.$caso['grupo']);
        $grupo = AsignaturaGrupo::findOrFail($caso['grupo']);

        $props = $this->propsDe(app(DocenciaController::class)->materia($peticion, $grupo), $peticion);

        // El (float) es por el viaje a JSON: 100.0 vuelve como int 100.
        $this->assertSame(100.0, (float) $props['escala']['maxima'], 'La máxima del plan, no un 10 por omisión.');
        $this->assertSame(70.0, (float) $props['escala']['aprobatoria']);
    }

    // ── El plan por su cuenta ──────────────────────────────────────────────

    /** Sin puntos posibles no hay calificación: un cero diría que reprobó. */
    public function test_sin_puntos_posibles_no_inventa_un_cero(): void
    {
        $plan = PlanEstudio::findOrFail($this->alumnoInscrito()['plan']);

        $this->assertNull($plan->enEscala(10, 0));
        $this->assertNull($plan->enEscala(null, 40));
    }

    /**
     * Sin plan se cae al 0–10 de antes.
     *
     * Es lo que ya hay en la base: cambiarlo por la falta de una relación
     * movería calificaciones ya asentadas.
     */
    public function test_sin_plan_se_queda_en_diez(): void
    {
        $this->assertSame(8.0, PlanEstudio::enEscalaCon(null, 32, 40));
        $this->assertNull(PlanEstudio::enEscalaCon(null, 32, 0));
    }

    // ── Andamiaje ──────────────────────────────────────────────────────────

    /**
     * Una materia abierta con su curso de LMS, un alumno inscrito y un
     * componente ponderado al que colgarle actividades.
     *
     * @return array<string, mixed>
     */
    private function materiaConLms(
        float $minima = 0,
        float $maxima = 10,
        float $aprobatoria = 6,
        int $decimales = 2,
    ): array {
        $escuela = $this->alumnoInscrito();

        PlanEstudio::findOrFail($escuela['plan'])->update([
            'calificacion_minima' => $minima,
            'calificacion_maxima' => $maxima,
            'calificacion_minima_aprobatoria' => $aprobatoria,
            'decimales_calificacion' => $decimales,
        ]);

        $ciclo = $this->cicloDePrueba();
        $abierta = $this->materiaAbierta($escuela['plan'], $escuela['campus'], $ciclo);

        $esquema = EsquemaEvaluacion::create([
            'plan_materia_id' => $abierta['planMateria'],
            'componente' => 'Actividades',
            'porcentaje' => 100,
            'orden' => 1,
        ]);

        $curso = Curso::create([
            'plan_materia_id' => $abierta['planMateria'],
            'asignatura_grupo_id' => $abierta['materia'],
            'titulo' => 'Curso de prueba',
            'publicado' => true,
        ]);

        $inscripcion = $this->fila('inscripcion', [
            'matricula_oferta_id' => $escuela['matricula'],
            'asignatura_grupo_id' => $abierta['materia'],
            'ciclo_id' => $ciclo,
            'tipo' => 'ordinaria',
            'forma_inscripcion' => 'administrativa',
            'situacion_id' => $this->deCatalogo('situaciones_inscripcion'),
        ]);

        return [
            'grupo' => $abierta['materia'],
            'curso' => $curso->id,
            'esquema' => $esquema->id,
            'inscripcion' => $inscripcion,
        ];
    }

    /** Una actividad de N puntos, ya entregada y calificada con M. */
    private function entregar(array $caso, float $obtenidos, float $puntos): void
    {
        $actividad = Actividad::create([
            'curso_id' => $caso['curso'],
            'tipo' => 'actividad',
            'titulo' => 'Actividad de prueba',
            'esquema_evaluacion_id' => $caso['esquema'],
            'puntos' => $puntos,
            'publicada' => true,
        ]);

        Entrega::create([
            'actividad_id' => $actividad->id,
            'inscripcion_id' => $caso['inscripcion'],
            'estado' => 'calificada',
            'entregada_en' => now(),
            'calificacion' => $obtenidos,
            'calificada_en' => now(),
        ]);

        $this->calculador->recalcular((int) $caso['inscripcion'], (int) $caso['esquema']);
    }

    private function calificacionDelComponente(array $caso): ?float
    {
        $fila = CalificacionComponente::query()
            ->where('inscripcion_id', $caso['inscripcion'])
            ->where('esquema_evaluacion_id', $caso['esquema'])
            ->first();

        return $fila?->calificacion === null ? null : (float) $fila->calificacion;
    }
}
