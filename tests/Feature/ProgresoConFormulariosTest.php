<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Admisiones\Aspirante;
use App\Models\Formularios\Formulario;
use App\Models\Formularios\FormularioAsignacion;
use App\Models\Identidad\Persona;
use App\Services\ProgresoSolicitud;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreaEscuelaDePrueba;
use Tests\TenantTestCase;

/**
 * Los formularios cuentan para el avance de la solicitud.
 *
 * Sin ellos el porcentaje mentía: un aspirante con todo lo obligatorio sin
 * contestar podía ver «100%», porque el avance sólo miraba datos, documentos y
 * pago. Quien lo lee decide con él si ya puede convertirse en alumno.
 *
 * Que el paso APLIQUE o no depende de la persona, y siempre fue así —quien no
 * tiene cargos no ve el de pago—; lo que es fijo para toda la escuela es que el
 * paso existe.
 */
class ProgresoConFormulariosTest extends TenantTestCase
{
    use CreaEscuelaDePrueba;

    private ProgresoSolicitud $progreso;

    protected function setUp(): void
    {
        parent::setUp();

        $this->progreso = app(ProgresoSolicitud::class);
    }

    /** A quien no se le asignó ninguno, el paso no le aplica ni le estorba. */
    public function test_sin_formularios_asignados_el_paso_no_aplica(): void
    {
        $paso = $this->pasoDe($this->aspirante());

        $this->assertFalse($paso['aplica']);
        $this->assertTrue($paso['completo']);
    }

    public function test_un_formulario_obligatorio_sin_contestar_deja_el_paso_incompleto(): void
    {
        $aspirante = $this->aspirante();
        $formulario = $this->formularioAsignado('salud', obligatorio: true);
        $this->campo($formulario, 'Alergias');

        $paso = $this->pasoDe($aspirante);

        $this->assertTrue($paso['aplica']);
        $this->assertFalse($paso['completo']);
        $this->assertSame(['Salud'], $paso['faltantes']);
    }

    public function test_al_contestarlo_el_paso_queda_completo(): void
    {
        $aspirante = $this->aspirante();
        $formulario = $this->formularioAsignado('salud', obligatorio: true);
        $campo = $this->campo($formulario, 'Alergias');

        $this->responder($aspirante, $campo);

        $this->assertTrue($this->pasoDe($aspirante)['completo']);
    }

    /**
     * Sólo cuentan los OBLIGATORIOS.
     *
     * Pedirle que llene lo opcional para poder avanzar convertiría un «puedes
     * contestarlo» en un requisito, que es la misma regla del expediente
     * documental.
     */
    public function test_uno_opcional_sin_contestar_no_frena_el_paso(): void
    {
        $aspirante = $this->aspirante();
        $formulario = $this->formularioAsignado('encuesta', obligatorio: false);
        $this->campo($formulario, '¿Cómo nos conociste?');

        $paso = $this->pasoDe($aspirante);

        $this->assertTrue($paso['aplica']);
        $this->assertTrue($paso['completo']);
    }

    /**
     * Y el porcentaje baja de verdad.
     *
     * Es lo que la funcionalidad promete: el número que el interesado mira deja
     * de decir que terminó cuando le falta algo.
     */
    public function test_el_porcentaje_deja_de_decir_cien_con_formularios_pendientes(): void
    {
        $aspirante = $this->aspirante();
        $formulario = $this->formularioAsignado('salud', obligatorio: true);
        $this->campo($formulario, 'Alergias');

        $avance = $this->progreso->para($aspirante->fresh(['persona']));

        $this->assertLessThan(100, $avance['porcentaje']);

        /*
         * Y es el paso de FORMULARIOS el que lo baja.
         *
         * Sin esta segunda comprobación la prueba pasaba igual con el paso
         * siempre dado por completo: el porcentaje ya venía por debajo de 100
         * porque a este aspirante le faltan datos de contacto. Se afirma lo
         * que se quiere probar, no una consecuencia que otro paso también
         * produce.
         */
        $paso = collect($avance['pasos'])->firstWhere('clave', ProgresoSolicitud::PASO_FORMULARIOS);

        $this->assertTrue($paso['aplica']);
        $this->assertFalse($paso['completo']);
    }

    // ── Andamiaje ──────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function pasoDe(Aspirante $aspirante): array
    {
        return collect($this->progreso->para($aspirante->fresh(['persona']))['pasos'])
            ->firstWhere('clave', ProgresoSolicitud::PASO_FORMULARIOS);
    }

    private function formularioAsignado(string $clave, bool $obligatorio): Formulario
    {
        $formulario = Formulario::create([
            'clave' => $clave,
            'titulo' => ucfirst($clave),
            'version' => 1,
            'obligatorio' => $obligatorio,
            'orden' => 1,
        ]);

        FormularioAsignacion::create([
            'formulario_id' => $formulario->id,
            'rol_id' => DB::table('roles')->where('name', 'aspirante')->value('id')
                ?? $this->fila('roles', ['name' => 'aspirante', 'nombre' => 'Aspirante', 'guard_name' => 'web']),
        ]);

        return $formulario;
    }

    private function campo(Formulario $formulario, string $pregunta): int
    {
        return $this->fila('campos_formulario', [
            'formulario_id' => $formulario->id,
            'pregunta' => $pregunta,
            'tipo_campo_id' => $this->deCatalogo('tipos_campo'),
            'orden' => 1,
            'obligatorio' => false,
        ]);
    }

    private function responder(Aspirante $aspirante, int $campoId): void
    {
        $this->fila('respuestas_campo', [
            'campo_formulario_id' => $campoId,
            'formulario_version' => 1,
            'persona_id' => $aspirante->persona_id,
            'aspirante_id' => $aspirante->id,
            'valor' => 'Ninguna',
        ]);
    }

    private function aspirante(): Aspirante
    {
        $persona = Persona::create(['nombre' => 'Prospecto', 'primer_apellido' => 'De prueba']);

        return Aspirante::create([
            'persona_id' => $persona->id,
        ]);
    }
}
