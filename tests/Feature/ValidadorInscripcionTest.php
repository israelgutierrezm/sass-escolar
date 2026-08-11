<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Admisiones\MatriculaOferta;
use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\ControlEscolar\Ciclo;
use App\Services\ValidadorInscripcion;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreaEscuelaDePrueba;
use Tests\TenantTestCase;

/**
 * Qué impide inscribir a un alumno en una materia.
 *
 * Dejar pasar una inscripción que no debía se paga tarde y caro: el alumno
 * cursa un semestre entero y el problema aparece al titularse, cuando ya no
 * tiene arreglo. Y bloquear de más es igual de malo en la ventanilla, con el
 * alumno enfrente.
 *
 * Se comprueba que se devuelven TODOS los motivos y no sólo el primero: quien
 * inscribe necesita saber de una vez todo lo que falta.
 */
class ValidadorInscripcionTest extends TenantTestCase
{
    use CreaEscuelaDePrueba;

    private ValidadorInscripcion $validador;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validador = app(ValidadorInscripcion::class);
    }

    public function test_una_inscripcion_normal_no_tiene_impedimentos(): void
    {
        [$matricula, $materia] = $this->escenario();

        $this->assertSame([], $this->validador->impedimentos($matricula, $materia));
        $this->assertTrue($this->validador->puedeInscribir($matricula, $materia));
    }

    public function test_no_se_puede_inscribir_dos_veces_la_misma_materia(): void
    {
        [$matricula, $materia, $ciclo] = $this->escenario();

        $this->inscribir($matricula->id, $materia->id, $ciclo);

        $this->assertContains(
            'El alumno ya está inscrito en esta materia.',
            $this->validador->impedimentos($matricula, $materia),
        );
    }

    /**
     * La baja no borra la inscripción, le pone situación «baja» para conservar
     * historia. Si contara como vigente, sacar a alguien de una materia sería
     * irreversible: no podría volver ni corrigiendo una baja hecha por error.
     */
    public function test_una_baja_previa_no_impide_volver_a_inscribirse(): void
    {
        [$matricula, $materia, $ciclo] = $this->escenario();

        $this->inscribir($matricula->id, $materia->id, $ciclo, $this->situacionCon('situaciones_inscripcion', 'baja'));

        $this->assertSame([], $this->validador->impedimentos($matricula, $materia));
    }

    /** El acta y el historial académico se llevan contra el plan del alumno. */
    public function test_no_se_puede_inscribir_una_materia_de_otro_plan(): void
    {
        [$matricula, , $ciclo, $escuela] = $this->escenario();

        $otraEscuela = $this->alumnoInscrito();
        $ajena = $this->materiaAbierta($otraEscuela['plan'], $escuela['campus'], $ciclo);

        $impedimentos = $this->validador->impedimentos($matricula, AsignaturaGrupo::findOrFail($ajena['materia']));

        $this->assertContains('La materia pertenece a otro plan de estudios.', $impedimentos);
    }

    public function test_el_cupo_lleno_impide_inscribir(): void
    {
        [$matricula, $materia, $ciclo] = $this->escenario(cupo: 1);

        // Otro alumno ocupa la única plaza.
        $otro = $this->alumnoInscrito();
        $this->inscribir($otro['matricula'], $materia->id, $ciclo);

        $this->assertContains(
            'El grupo alcanzó su cupo (1).',
            $this->validador->impedimentos($matricula, $materia),
        );
    }

    public function test_la_ventana_de_inscripcion_cerrada_impide_inscribir(): void
    {
        [$matricula, $materia, $ciclo] = $this->escenario();

        Ciclo::findOrFail($ciclo)->update([
            'inscripcion_desde' => '2020-01-01',
            'inscripcion_hasta' => '2020-01-31',
        ]);

        $this->assertContains(
            'La ventana de inscripción del ciclo está cerrada.',
            $this->validador->impedimentos($matricula, $materia->fresh(['grupo.ciclo'])),
        );
    }

    /**
     * Sin fechas capturadas la ventana no restringe. Es la diferencia entre «no
     * configurado» y «cerrado», y confundirlas dejaría a toda escuela que no usa
     * la función sin poder inscribir a nadie.
     */
    public function test_un_ciclo_sin_ventana_configurada_no_restringe(): void
    {
        [$matricula, $materia] = $this->escenario();

        $this->assertSame([], $this->validador->impedimentos($matricula, $materia));
    }

    public function test_la_seriacion_pendiente_impide_inscribir(): void
    {
        [$matricula, $materia, $ciclo, $escuela] = $this->escenario();

        $previa = $this->materiaAbierta($escuela['plan'], $escuela['campus'], $ciclo);

        $this->fila('seriacion', [
            'plan_materia_id' => $materia->plan_materia_id,
            'requiere_plan_materia_id' => $previa['planMateria'],
            'tipo' => 'aprobada',
        ]);

        $impedimentos = $this->validador->impedimentos($matricula, $materia->fresh());

        $this->assertNotEmpty($impedimentos);
        $this->assertStringContainsString('Materia de prueba', implode(' ', $impedimentos));
    }

    public function test_con_el_requisito_aprobado_en_el_historial_si_se_puede(): void
    {
        [$matricula, $materia, $ciclo, $escuela] = $this->escenario();

        $previa = $this->materiaAbierta($escuela['plan'], $escuela['campus'], $ciclo);

        $this->fila('seriacion', [
            'plan_materia_id' => $materia->plan_materia_id,
            'requiere_plan_materia_id' => $previa['planMateria'],
            'tipo' => 'aprobada',
        ]);

        $this->fila('historial', [
            'matricula_oferta_id' => $matricula->id,
            'plan_materia_id' => $previa['planMateria'],
            'ciclo_id' => $ciclo,
            'tipo_evaluacion_id' => $this->deCatalogo('tipos_evaluacion'),
            // La regla mira la CLAVE del estatus, no su id: «aprobada» es lo
            // que distingue el requisito cubierto del cursado sin aprobar.
            'estatus_id' => $this->situacionCon('estatus_historial', 'aprobada'),
            'calificacion' => 9,
        ]);

        $this->assertSame([], $this->validador->impedimentos($matricula, $materia->fresh()));
    }

    /** Una materia revalidada de otra institución cubre el requisito. */
    public function test_una_equivalencia_revalidada_cubre_la_seriacion(): void
    {
        [$matricula, $materia, $ciclo, $escuela] = $this->escenario();

        $previa = $this->materiaAbierta($escuela['plan'], $escuela['campus'], $ciclo);

        $this->fila('seriacion', [
            'plan_materia_id' => $materia->plan_materia_id,
            'requiere_plan_materia_id' => $previa['planMateria'],
            'tipo' => 'aprobada',
        ]);

        $this->fila('equivalencias', [
            'matricula_oferta_id' => $matricula->id,
            'plan_materia_id' => $previa['planMateria'],
            'institucion_procedencia' => 'Otra escuela',
        ]);

        $this->assertSame([], $this->validador->impedimentos($matricula, $materia->fresh()));
    }

    /**
     * Se devuelven todos los motivos de una vez: descubrirlos de uno en uno
     * obliga a repetir el intento tantas veces como problemas haya, con el
     * alumno esperando en la ventanilla.
     */
    public function test_se_devuelven_todos_los_motivos_juntos(): void
    {
        [$matricula, $materia, $ciclo] = $this->escenario(cupo: 1);

        // Ya inscrito, cupo lleno por él mismo y ventana cerrada.
        $this->inscribir($matricula->id, $materia->id, $ciclo);
        Ciclo::findOrFail($ciclo)->update([
            'inscripcion_desde' => '2020-01-01',
            'inscripcion_hasta' => '2020-01-31',
        ]);

        $impedimentos = $this->validador->impedimentos($matricula, $materia->fresh(['grupo.ciclo']));

        $this->assertGreaterThanOrEqual(3, count($impedimentos));
    }

    // ── Andamiaje ──────────────────────────────────────────────────────────

    /**
     * @return array{0: MatriculaOferta, 1: AsignaturaGrupo, 2: int, 3: array<string, int>}
     */
    private function escenario(int $cupo = 30): array
    {
        $escuela = $this->alumnoInscrito();
        $ciclo = $this->cicloDePrueba();
        $abierta = $this->materiaAbierta($escuela['plan'], $escuela['campus'], $ciclo, $cupo);

        return [
            MatriculaOferta::with('oferta')->findOrFail($escuela['matricula']),
            AsignaturaGrupo::with(['planMateria.asignatura', 'grupo.ciclo'])->findOrFail($abierta['materia']),
            $ciclo,
            $escuela,
        ];
    }

    private function inscribir(int $matriculaId, int $materiaId, int $ciclo, ?int $situacion = null): void
    {
        DB::table('inscripcion')->insert([
            'matricula_oferta_id' => $matriculaId,
            'asignatura_grupo_id' => $materiaId,
            'ciclo_id' => $ciclo,
            'tipo' => 'ordinaria',
            'forma_inscripcion' => 'administrativa',
            'situacion_id' => $situacion ?? $this->deCatalogo('situaciones_inscripcion'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
