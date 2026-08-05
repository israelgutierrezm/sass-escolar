<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Admisiones\Aspirante;
use App\Models\Admisiones\EtapaCrm;
use App\Models\Admisiones\MatriculaOferta;
use App\Services\ConvertidorAspirante;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreaEscuelaDePrueba;
use Tests\TenantTestCase;

/**
 * Convertir un aspirante en alumno.
 *
 * Es el momento en que se genera la matrícula y el prospecto deja de serlo. Se
 * actualizaba su SITUACIÓN pero no su ETAPA del embudo, así que alguien con
 * matrícula seguía contando como «Contacto inicial» en el tablero del panel:
 * quien lo mira para saber cuántos faltan por cerrar veía un número que no era.
 */
class ConversionAAlumnoTest extends TenantTestCase
{
    use CreaEscuelaDePrueba;

    public function test_la_conversion_genera_matricula(): void
    {
        $aspirante = $this->aspirante();

        $matricula = app(ConvertidorAspirante::class)->convertir($aspirante);

        $this->assertNotNull($matricula->matricula);
        $this->assertSame($aspirante->persona_id, $matricula->persona_id);
    }

    /** El embudo tiene que reflejar que ya se cerró. */
    public function test_el_aspirante_avanza_a_la_ultima_etapa_del_embudo(): void
    {
        $aspirante = $this->aspirante();
        $ultima = EtapaCrm::query()->orderByDesc('orden')->value('id');

        $this->assertNotSame($ultima, $aspirante->etapa_crm_id, 'Arranca en la primera etapa.');

        app(ConvertidorAspirante::class)->convertir($aspirante);

        $this->assertSame($ultima, $aspirante->fresh()->etapa_crm_id);
    }

    /**
     * La última etapa se toma por ORDEN, no por una clave fija: el catálogo lo
     * edita cada escuela y puede llamar «Matriculado» a lo que aquí es
     * «Inscrito».
     */
    public function test_la_ultima_etapa_se_decide_por_orden_no_por_su_nombre(): void
    {
        $aspirante = $this->aspirante();

        $final = $this->fila('etapas_crm', ['clave' => 'cerrado_pagado', 'nombre' => 'Cerrado y pagado', 'orden' => 99]);

        app(ConvertidorAspirante::class)->convertir($aspirante);

        $this->assertSame($final, $aspirante->fresh()->etapa_crm_id);
    }

    /** Dos veces la misma persona en la misma oferta no: sería doble matrícula. */
    public function test_no_se_convierte_dos_veces_a_la_misma_oferta(): void
    {
        $aspirante = $this->aspirante();
        app(ConvertidorAspirante::class)->convertir($aspirante);

        $impedimentos = app(ConvertidorAspirante::class)->impedimentos($aspirante->fresh());

        $this->assertNotEmpty($impedimentos);
        $this->assertStringContainsString('ya está matriculada', implode(' ', $impedimentos));
    }

    // ── Andamiaje ──────────────────────────────────────────────────────────

    private function aspirante(): Aspirante
    {
        $escuela = $this->alumnoInscrito();
        $persona = $this->fila('personas', ['nombre' => 'Prospecto', 'primer_apellido' => 'De prueba']);

        // La conversión exige la situación «activo» por su clave: es la que le
        // pone al alumno recién creado.
        $this->situacionCon('situaciones_alumno', 'activo');

        // Y la de aspirante «inscrito», que es a la que pasa al convertirse.
        $this->situacionCon('situaciones_aspirante', 'inscrito');

        // Y una regla de matrícula: sin ella no hay con qué numerar al alumno.
        // La global con año y consecutivo es la que trae cualquier escuela.
        if (DB::table('reglas_matricula')->count() === 0) {
            $this->fila('reglas_matricula', [
                'ambito' => 'global',
                'plantilla' => '{AAAA}-{####}',
                'consecutivo_por' => null,
                'consecutivo_anual' => true,
                'activo' => true,
            ]);
        }

        // Un embudo con principio y final, como el que siembra cualquier escuela.
        if (DB::table('etapas_crm')->count() === 0) {
            $this->fila('etapas_crm', ['clave' => 'contacto', 'nombre' => 'Contacto inicial', 'orden' => 1]);
            $this->fila('etapas_crm', ['clave' => 'inscrito', 'nombre' => 'Inscrito', 'orden' => 10]);
        }

        return Aspirante::create([
            'persona_id' => $persona,
            'oferta_interes_id' => $escuela['oferta'],
            'campus_id' => $escuela['campus'],
            'situacion_id' => $this->deCatalogo('situaciones_aspirante'),
            'etapa_crm_id' => EtapaCrm::query()->orderBy('orden')->value('id'),
        ]);
    }
}
