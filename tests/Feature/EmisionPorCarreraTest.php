<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Academico\Carrera;
use App\Models\Admisiones\MatriculaOferta;
use App\Services\EstadoCertificacion;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreaEscuelaDePrueba;
use Tests\TenantTestCase;

/**
 * No toda carrera termina en papel oficial.
 *
 * Diplomados, cursos y educación continua viven en el mismo catálogo que las
 * licenciaturas y no tienen RVOE que respalde un certificado ni un título.
 * Cerrar su plan no los vuelve certificables, y ofrecerlos entre los candidatos
 * de un lote es prometer un trámite que la escuela no puede cumplir —alguien
 * acaba diciéndoselo al alumno—.
 */
class EmisionPorCarreraTest extends TenantTestCase
{
    use CreaEscuelaDePrueba;

    private EstadoCertificacion $estado;

    protected function setUp(): void
    {
        parent::setUp();

        $this->estado = app(EstadoCertificacion::class);
    }

    public function test_una_carrera_nace_emitiendo_documentos(): void
    {
        $this->assertTrue($this->estado->emiteDocumentos($this->egresado()));
    }

    public function test_sin_certificado_no_entra_a_un_lote_aunque_haya_cerrado_su_plan(): void
    {
        $matricula = $this->egresado();

        $this->assertTrue($this->estado->elegibleParaLote($matricula), 'Cerró su plan: de origen sí entra.');

        $this->apagar($matricula);

        $this->assertFalse($this->estado->elegibleParaLote($matricula->fresh(['oferta.carrera'])));
    }

    /**
     * Certificado y título son UN permiso: el certificado acredita las materias
     * y el título haberla terminado, y no hay uno sin el otro. Se separaron
     * pensando en programas que dieran sólo certificado; no existen.
     */
    public function test_certificado_y_titulo_van_juntos(): void
    {
        $matricula = $this->egresado();

        $this->apagar($matricula);

        $this->assertFalse($this->estado->emiteDocumentos($matricula->fresh(['oferta.carrera'])));
    }

    /**
     * `disponible()` responde al avance ACADÉMICO y nada más: lo consultan tanto
     * certificación como titulación, y mezclarle una de las dos banderas apagaba
     * la otra sin que nadie lo pidiera.
     */
    public function test_el_avance_academico_no_depende_de_lo_que_la_carrera_emita(): void
    {
        $matricula = $this->egresado();

        $this->apagar($matricula);

        $this->assertTrue(
            $this->estado->disponible($matricula->fresh(['oferta.carrera', 'oferta.plan'])),
            'Sigue habiendo cerrado su plan: eso no se lo quita ningún trámite.',
        );
    }

    /** Sin el dato —carrera vieja, relación no cargada— se responde que sí. */
    public function test_ante_la_duda_se_deja_pasar(): void
    {
        $matricula = $this->egresado();

        // Una matrícula sin oferta cargada es lo más parecido a «no se sabe».
        $suelta = new MatriculaOferta(['matricula' => 'SIN-OFERTA']);

        $this->assertTrue($this->estado->emiteDocumentos($suelta));
        $this->assertNotNull($matricula->id);
    }

    // ── Andamiaje ──────────────────────────────────────────────────────────

    /** Un alumno que ya cerró su plan: aprobó todo lo que su plan exige. */
    private function egresado(): MatriculaOferta
    {
        $escuela = $this->alumnoInscrito();
        $ciclo = $this->cicloDePrueba();
        $materia = $this->materiaAbierta($escuela['plan'], $escuela['campus'], $ciclo);

        // El plan pide UNA materia; con esa aprobada, queda cerrado.
        DB::table('planes_estudio')->where('id', $escuela['plan'])->update(['minimo_asignaturas' => 1]);

        $this->fila('historial', [
            'matricula_oferta_id' => $escuela['matricula'],
            'plan_materia_id' => $materia['planMateria'],
            'ciclo_id' => $ciclo,
            'calificacion' => 10,
            'estatus_id' => $this->situacionCon('estatus_historial', 'aprobada'),
            'tipo_evaluacion_id' => $this->deCatalogo('tipos_evaluacion'),
        ]);

        return MatriculaOferta::with(['oferta.carrera', 'oferta.plan'])->findOrFail($escuela['matricula']);
    }

    private function apagar(MatriculaOferta $matricula): void
    {
        Carrera::query()->where('id', $matricula->oferta->carrera_id)
            ->update(['emite_documentos_oficiales' => false]);
    }
}
