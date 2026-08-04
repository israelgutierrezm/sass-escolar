<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Academico\EsquemaEvaluacion;
use App\Models\ControlEscolar\Acta;
use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\ControlEscolar\Historial;
use App\Models\ControlEscolar\Inscripcion;
use App\Services\AsentadorActa;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Concerns\CreaEscuelaDePrueba;
use Tests\TenantTestCase;

/**
 * El asentamiento del acta: donde una captura se vuelve historia escolar.
 *
 * Es la operación más delicada del sistema. A partir de aquí la calificación
 * deja de ser editable, viaja al kárdex y de ahí al certificado; y el acta se
 * imprime y se firma, así que su folio tiene que ser único de verdad.
 *
 * Lo que se comprueba, sobre todo, es lo que impide asentar: un acta cerrada
 * con capturas incompletas produce un kárdex que nadie puede reconstruir.
 */
class AsentadorActaTest extends TenantTestCase
{
    use CreaEscuelaDePrueba;

    private AsentadorActa $asentador;

    protected function setUp(): void
    {
        parent::setUp();

        $this->asentador = app(AsentadorActa::class);

        // El kárdex necesita saber qué es aprobar: son los dos estatus que el
        // servicio busca por clave.
        $this->situacionCon('estatus_historial', 'aprobada');
        $this->situacionCon('estatus_historial', 'reprobada');
    }

    public function test_no_se_puede_cerrar_con_calificaciones_incompletas(): void
    {
        $caso = $this->materiaConAlumno();

        $impedimentos = $this->asentador->impedimentos($caso['acta']);

        $this->assertNotEmpty($impedimentos);
        $this->assertStringContainsString('falta capturar', implode(' ', $impedimentos));
    }

    public function test_no_se_puede_cerrar_sin_alumnos_que_calificar(): void
    {
        $caso = $this->materiaConAlumno(conAlumno: false);

        $this->assertSame(['No hay alumnos inscritos que calificar.'], $this->asentador->impedimentos($caso['acta']));
    }

    /** Vale más una materia sin acta que un kárdex con números irreproducibles. */
    public function test_un_esquema_que_no_suma_cien_impide_cerrar(): void
    {
        $caso = $this->materiaConAlumno(porcentajes: [40, 40]);
        $this->capturar($caso, [10, 10]);

        $impedimentos = $this->asentador->impedimentos($caso['acta']);

        $this->assertStringContainsString('80%', implode(' ', $impedimentos));
    }

    public function test_al_cerrar_se_vuelca_el_kardex_con_folio(): void
    {
        $caso = $this->materiaConAlumno();
        $this->capturar($caso, [8, 10]);

        $acta = $this->asentador->cerrar($caso['acta'], $caso['persona']);

        $this->assertSame(Acta::CERRADA, $acta->situacion);
        $this->assertNotNull($acta->folio);
        $this->assertStringStartsWith('ACT-', $acta->folio, 'El folio sale del formato configurable.');

        $renglon = Historial::where('acta_id', $acta->id)->firstOrFail();

        // 8*0.5 + 10*0.5 = 9
        $this->assertSame('9.00', $renglon->calificacion);
        $this->assertSame($acta->folio, $renglon->acta_folio);
        $this->assertSame('9.00', Inscripcion::findOrFail($caso['inscripcion'])->calificacion_final);
    }

    public function test_el_estatus_del_kardex_sale_de_la_minima_del_plan(): void
    {
        $caso = $this->materiaConAlumno();
        // 4 y 6 dan 5: por debajo de la mínima (6) del plan de prueba.
        $this->capturar($caso, [4, 6]);

        $acta = $this->asentador->cerrar($caso['acta'], $caso['persona']);

        $renglon = Historial::with('estatus')->where('acta_id', $acta->id)->firstOrFail();

        $this->assertSame('reprobada', $renglon->estatus->clave);
    }

    /**
     * Una materia se asienta UNA vez. Un segundo cierre duplicaría los
     * renglones del kárdex sin que nadie lo note: el alumno aparecería dos
     * veces en la misma materia.
     */
    public function test_no_se_asienta_dos_veces_la_misma_materia(): void
    {
        $caso = $this->materiaConAlumno();
        $this->capturar($caso, [8, 8]);

        $this->asentador->cerrar($caso['acta'], $caso['persona']);

        // Un acta nueva sobre la misma materia-grupo.
        $segunda = Acta::create([
            'asignatura_grupo_id' => $caso['materia'],
            'tipo_evaluacion_id' => $caso['tipoEvaluacion'],
            'folio' => 'BORRADOR-2',
            'situacion' => Acta::ABIERTA,
        ]);

        $impedimentos = $this->asentador->impedimentos($segunda->load('asignaturaGrupo'));

        $this->assertStringContainsString('acta de corrección', implode(' ', $impedimentos));
    }

    public function test_cerrar_un_acta_ya_cerrada_falla(): void
    {
        $caso = $this->materiaConAlumno();
        $this->capturar($caso, [8, 8]);

        $acta = $this->asentador->cerrar($caso['acta'], $caso['persona']);

        $this->expectException(RuntimeException::class);
        $this->asentador->cerrar($acta->load('asignaturaGrupo'), $caso['persona']);
    }

    /** Corregir no es editar: es un acta nueva que apunta a la original. */
    public function test_la_correccion_sustituye_los_renglones_de_la_original(): void
    {
        $caso = $this->materiaConAlumno();
        $this->capturar($caso, [4, 6]);

        $original = $this->asentador->cerrar($caso['acta'], $caso['persona']);
        $renglonOriginal = Historial::where('acta_id', $original->id)->firstOrFail();

        // Se corrige la captura: ahora aprueba.
        $correccion = $this->asentador->abrirCorreccion($original, 'Se capturó mal el parcial.');
        $this->capturar($caso, [9, 9]);

        $cerrada = $this->asentador->cerrar($correccion->load('asignaturaGrupo'), $caso['persona']);

        // El renglón viejo se da de baja lógica, no se sobreescribe: queda
        // trazable qué decía el acta original.
        $this->assertNotNull($renglonOriginal->fresh()->deleted_at ?? Historial::withTrashed()->find($renglonOriginal->id)->deleted_at);
        $this->assertSame('9.00', Historial::where('acta_id', $cerrada->id)->value('calificacion'));
    }

    public function test_solo_se_corrige_un_acta_cerrada(): void
    {
        $caso = $this->materiaConAlumno();

        $this->expectException(RuntimeException::class);
        $this->asentador->abrirCorreccion($caso['acta'], 'Motivo');
    }

    /** Pedir dos veces la corrección devuelve la misma, no abre otra. */
    public function test_no_se_abren_dos_correcciones_a_la_vez(): void
    {
        $caso = $this->materiaConAlumno();
        $this->capturar($caso, [8, 8]);

        $original = $this->asentador->cerrar($caso['acta'], $caso['persona']);

        $primera = $this->asentador->abrirCorreccion($original, 'Motivo');
        $segunda = $this->asentador->abrirCorreccion($original, 'Otro motivo');

        $this->assertSame($primera->id, $segunda->id);
    }

    /** Una baja dejó de cursar: no entra al acta ni al kárdex. */
    public function test_un_alumno_dado_de_baja_no_se_califica(): void
    {
        $caso = $this->materiaConAlumno();
        $this->capturar($caso, [8, 8]);

        DB::table('inscripcion')->where('id', $caso['inscripcion'])->update([
            'situacion_id' => $this->situacionCon('situaciones_inscripcion', 'baja'),
        ]);

        $materiaGrupo = AsignaturaGrupo::findOrFail($caso['materia']);

        $this->assertCount(0, $this->asentador->inscripcionesCalificables($materiaGrupo));
    }

    /**
     * El folio se emite al CERRAR, no al abrir: un acta que se abandona sin
     * capturar no debe quemar un número del consecutivo del archivo.
     */
    public function test_dos_actas_no_comparten_folio(): void
    {
        $folios = [];

        foreach (range(1, 2) as $i) {
            $caso = $this->materiaConAlumno();
            $this->capturar($caso, [8, 8]);
            $folios[] = $this->asentador->cerrar($caso['acta'], $caso['persona'])->folio;
        }

        $this->assertCount(2, array_unique($folios), 'El consecutivo no puede repetirse.');
    }

    // ── Andamiaje ──────────────────────────────────────────────────────────

    /**
     * Una materia con su esquema de evaluación, un alumno inscrito y su acta
     * abierta.
     *
     * @param  array<int, int>  $porcentajes
     * @return array<string, mixed>
     */
    private function materiaConAlumno(bool $conAlumno = true, array $porcentajes = [50, 50]): array
    {
        $escuela = $this->alumnoInscrito();
        $ciclo = $this->cicloDePrueba();
        $abierta = $this->materiaAbierta($escuela['plan'], $escuela['campus'], $ciclo);

        $esquema = [];

        foreach ($porcentajes as $i => $porcentaje) {
            $esquema[] = EsquemaEvaluacion::create([
                'plan_materia_id' => $abierta['planMateria'],
                'componente' => 'Componente '.($i + 1),
                'porcentaje' => $porcentaje,
                'orden' => $i + 1,
            ])->id;
        }

        $inscripcion = null;

        if ($conAlumno) {
            $inscripcion = $this->fila('inscripcion', [
                'matricula_oferta_id' => $escuela['matricula'],
                'asignatura_grupo_id' => $abierta['materia'],
                'ciclo_id' => $ciclo,
                'tipo' => 'ordinaria',
                'forma_inscripcion' => 'administrativa',
                'situacion_id' => $this->deCatalogo('situaciones_inscripcion'),
            ]);
        }

        $tipoEvaluacion = $this->situacionCon('tipos_evaluacion', 'ordinaria');

        $acta = Acta::create([
            'asignatura_grupo_id' => $abierta['materia'],
            'tipo_evaluacion_id' => $tipoEvaluacion,
            'folio' => 'BORRADOR-'.uniqid(),
            'situacion' => Acta::ABIERTA,
        ]);

        return [
            'acta' => $acta->load('asignaturaGrupo'),
            'materia' => $abierta['materia'],
            'inscripcion' => $inscripcion,
            'esquema' => $esquema,
            'persona' => $escuela['persona'],
            'tipoEvaluacion' => $tipoEvaluacion,
        ];
    }

    /**
     * Captura una calificación por componente.
     *
     * @param  array<int, float>  $calificaciones
     */
    private function capturar(array $caso, array $calificaciones): void
    {
        foreach ($caso['esquema'] as $i => $esquemaId) {
            DB::table('calificaciones_componente')->updateOrInsert(
                ['inscripcion_id' => $caso['inscripcion'], 'esquema_evaluacion_id' => $esquemaId],
                ['calificacion' => $calificaciones[$i], 'created_at' => now(), 'updated_at' => now()],
            );
        }
    }
}
