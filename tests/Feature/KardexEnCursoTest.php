<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\AlumnoController;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\ControlEscolar\Historial;
use Tests\Concerns\CreaEscuelaDePrueba;
use Tests\TenantTestCase;

/**
 * Lo que el alumno está cursando ahora, visible en su kárdex.
 *
 * Antes el kárdex sólo mostraba lo asentado, así que las materias del ciclo
 * vigente no aparecían por ningún lado hasta que se cerraba el acta: quien abría
 * el expediente a mitad de semestre veía un hueco donde el alumno tenía seis
 * materias.
 *
 * No se guardan en `historial`: de esa tabla salen el promedio, los créditos y
 * el XML que se manda a la SEP, y sembrarla con renglones sin calificación
 * obligaría a que cada consulta se acordara de excluirlos. Se calculan al vuelo.
 */
class KardexEnCursoTest extends TenantTestCase
{
    use CreaEscuelaDePrueba;

    private AlumnoController $controlador;

    protected function setUp(): void
    {
        parent::setUp();

        $this->controlador = app(AlumnoController::class);
    }

    public function test_una_materia_inscrita_sin_acta_aparece_en_curso(): void
    {
        $escuela = $this->alumnoInscrito();
        $ciclo = $this->cicloDePrueba();
        $materia = $this->materiaAbierta($escuela['plan'], $escuela['campus'], $ciclo);
        $this->inscribir($escuela['matricula'], $materia['materia'], $ciclo);

        $renglones = $this->enCurso($escuela['matricula']);

        $this->assertCount(1, $renglones);
        $this->assertSame('En curso', $renglones[0]['estatus']);
        $this->assertNull($renglones[0]['calificacion'], 'Todavía no hay nota que mostrar.');
        $this->assertTrue($renglones[0]['en_curso']);
    }

    /**
     * Al asentar el acta la materia pasa a `historial`. Si siguiera saliendo como
     * en curso, el kárdex la mostraría dos veces —una con su calificación y otra
     * sin ella— el mismo ciclo.
     */
    public function test_lo_ya_asentado_no_se_repite(): void
    {
        $escuela = $this->alumnoInscrito();
        $ciclo = $this->cicloDePrueba();
        $materia = $this->materiaAbierta($escuela['plan'], $escuela['campus'], $ciclo);
        $this->inscribir($escuela['matricula'], $materia['materia'], $ciclo);
        $this->asentar($escuela['matricula'], $materia['planMateria'], $ciclo);

        $this->assertSame([], $this->enCurso($escuela['matricula']));
    }

    /**
     * Quien recursa algo que reprobó tiene un renglón viejo con su calificación y
     * está cursándola otra vez ahora: las dos cosas son ciertas, y por eso lo que
     * se compara es materia MÁS ciclo y no sólo la materia.
     */
    public function test_recursar_se_ve_junto_a_lo_que_ya_se_cursó(): void
    {
        $escuela = $this->alumnoInscrito();
        $anterior = $this->cicloDePrueba('VIEJO');
        $vigente = $this->cicloDePrueba('NUEVO');
        $materia = $this->materiaAbierta($escuela['plan'], $escuela['campus'], $vigente);

        // Reprobada el ciclo pasado, cursándose de nuevo en el vigente.
        $this->asentar($escuela['matricula'], $materia['planMateria'], $anterior);
        $this->inscribir($escuela['matricula'], $materia['materia'], $vigente);

        $renglones = $this->enCurso($escuela['matricula']);

        $this->assertCount(1, $renglones, 'El intento vigente sí se muestra.');
        $this->assertSame($materia['planMateria'], $renglones[0]['plan_materia_id']);
    }

    /** Una baja dejó de cursarse: mostrarla como en curso sería mentir. */
    public function test_una_baja_no_esta_en_curso(): void
    {
        $escuela = $this->alumnoInscrito();
        $ciclo = $this->cicloDePrueba();
        $materia = $this->materiaAbierta($escuela['plan'], $escuela['campus'], $ciclo);

        $this->inscribir(
            $escuela['matricula'],
            $materia['materia'],
            $ciclo,
            $this->situacionCon('situaciones_inscripcion', 'baja'),
        );

        $this->assertSame([], $this->enCurso($escuela['matricula']));
    }

    // ── Andamiaje ──────────────────────────────────────────────────────────

    /** @return array<int, array<string, mixed>> */
    private function enCurso(int $matriculaId): array
    {
        $alumno = MatriculaOferta::findOrFail($matriculaId);

        $historial = Historial::query()
            ->with('planMateria')
            ->where('matricula_oferta_id', $matriculaId)
            ->get();

        return $this->controlador->materiasEnCurso($alumno, $historial);
    }

    private function inscribir(int $matricula, int $asignaturaGrupo, int $ciclo, ?int $situacion = null): int
    {
        return $this->fila('inscripcion', [
            'matricula_oferta_id' => $matricula,
            'asignatura_grupo_id' => $asignaturaGrupo,
            'ciclo_id' => $ciclo,
            'tipo' => 'ordinaria',
            'forma_inscripcion' => 'administrativa',
            'situacion_id' => $situacion ?? $this->deCatalogo('situaciones_inscripcion'),
        ]);
    }

    private function asentar(int $matricula, int $planMateria, int $ciclo): int
    {
        return $this->fila('historial', [
            'matricula_oferta_id' => $matricula,
            'plan_materia_id' => $planMateria,
            'ciclo_id' => $ciclo,
            'calificacion' => 5,
            'estatus_id' => $this->situacionCon('estatus_historial', 'reprobada'),
            'tipo_evaluacion_id' => $this->deCatalogo('tipos_evaluacion'),
        ]);
    }
}
