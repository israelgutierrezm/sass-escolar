<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\AspiranteController;
use App\Models\Admisiones\Aspirante;
use App\Models\Admisiones\DocumentoRequerido;
use App\Models\Identidad\Persona;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\Concerns\CreaEscuelaDePrueba;
use Tests\TenantTestCase;

/**
 * La ficha del aspirante y su expediente documental.
 *
 * Regresión: la ficha reventaba con «Call to undefined method
 * DocumentoRequerido::carreras()». El puente `documento_carrera` se eliminó
 * —los requisitos se piden por ámbito, no por carrera— pero el controlador
 * conservó un filtro `whereHas('carreras')` que ya no tenía respaldo. Cualquier
 * aspirante con carrera de interés hacía caer la pantalla.
 */
class FichaAspiranteTest extends TenantTestCase
{
    use CreaEscuelaDePrueba;

    public function test_el_expediente_lista_los_documentos_del_ambito_aspirante(): void
    {
        $this->documentoRequerido('Acta de nacimiento', DocumentoRequerido::AMBITO_ASPIRANTE);
        $this->documentoRequerido('CURP', DocumentoRequerido::AMBITO_ASPIRANTE);
        // Uno de otro ámbito, que NO debe aparecer en el expediente del aspirante.
        $this->documentoRequerido('Título profesional', DocumentoRequerido::AMBITO_DOCENTE);

        $expediente = $this->expedienteDe($this->aspirante());

        $this->assertCount(2, $expediente);
        $this->assertEqualsCanonicalizing(
            ['Acta de nacimiento', 'CURP'],
            array_column($expediente, 'nombre'),
        );
    }

    /**
     * El caso exacto que reventaba: un aspirante CON carrera de interés. El
     * filtro por carrera desaparecido invocaba la relación inexistente.
     */
    public function test_la_ficha_no_cae_para_un_aspirante_con_carrera_de_interes(): void
    {
        $this->documentoRequerido('Acta de nacimiento', DocumentoRequerido::AMBITO_ASPIRANTE);

        $escuela = $this->alumnoInscrito();
        $aspirante = $this->aspirante(ofertaId: $escuela['oferta']);

        // No debe lanzar: antes tiraba BadMethodCallException aquí.
        $expediente = $this->expedienteDe($aspirante);

        $this->assertCount(1, $expediente);
    }

    // ── Andamiaje ──────────────────────────────────────────────────────────

    /** Invoca el método privado que arma el expediente, como hace `show`. */
    private function expedienteDe(Aspirante $aspirante): array
    {
        $aspirante->load(['ofertaInteres', 'expedienteDocumentos']);

        $metodo = new ReflectionMethod(AspiranteController::class, 'expediente');
        $metodo->setAccessible(true);

        return $metodo->invoke(app(AspiranteController::class), $aspirante);
    }

    private function aspirante(?int $ofertaId = null): Aspirante
    {
        $persona = Persona::create(['nombre' => 'Aspirante', 'primer_apellido' => 'De prueba']);

        $datos = [
            'persona_id' => $persona->id,
            'situacion_id' => $this->deCatalogo('situaciones_aspirante'),
        ];

        if ($ofertaId !== null) {
            $datos['oferta_interes_id'] = $ofertaId;
        }

        return Aspirante::create($datos);
    }

    private function documentoRequerido(string $nombre, string $ambito): DocumentoRequerido
    {
        $documento = DocumentoRequerido::create(['nombre' => $nombre, 'obligatorio' => true]);

        DB::table('documento_ambitos')->insert([
            'documento_id' => $documento->id,
            'ambito' => $ambito,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $documento;
    }
}
