<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ModoRedondeo;
use App\Exceptions\AvisoParaElUsuario;
use App\Http\Controllers\ConfiguracionEscolarController;
use App\Models\Academico\PlanEstudio;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreaEscuelaDePrueba;
use Tests\TenantTestCase;

/**
 * Corregir una calificación ya asentada.
 *
 * Se hace de una en una y a propósito: son actas, y un cambio masivo movería
 * promedios y becas sin que nadie viera qué se movía. Lo que esta prueba
 * sostiene es que cada corrección respete la escala del plan y deje rastro,
 * porque es de las cosas que después alguien pregunta —el alumno, un auditor—
 * y la respuesta no puede depender de que alguien se acuerde.
 */
class CorregirCalificacionTest extends TenantTestCase
{
    use CreaEscuelaDePrueba;

    private ConfiguracionEscolarController $controlador;

    protected function setUp(): void
    {
        parent::setUp();

        Session::start();

        $this->controlador = app(ConfiguracionEscolarController::class);
    }

    /** Se corrige y queda el valor nuevo. */
    public function test_corrige_la_calificacion(): void
    {
        ['historial' => $id] = $this->conCalificacion(8.5, decimales: 0);

        $this->corregir($id, 9);

        $this->assertSame(9.0, (float) DB::table('historial')->where('id', $id)->value('calificacion'));
    }

    /**
     * Y queda registrado quién, cuándo y desde dónde.
     *
     * Sin esto, una calificación cambiada es indistinguible de una capturada
     * mal desde el principio.
     */
    public function test_la_correccion_queda_en_la_bitacora(): void
    {
        ['historial' => $id] = $this->conCalificacion(8.5, decimales: 0);

        $this->corregir($id, 9);

        $registro = DB::table('auditoria')
            ->where('auditable_type', 'historial')
            ->where('auditable_id', $id)
            ->first();

        $this->assertNotNull($registro, 'La corrección tiene que dejar rastro.');
        $this->assertSame('calificacion_corregida', $registro->evento);
        // Con `(float)`: al pasar por JSON, un 9.0 vuelve como entero y la
        // comparación estricta fallaría por el tipo, no por el valor.
        $this->assertSame(8.5, (float) json_decode($registro->valores_anteriores, true)['calificacion']);
        $this->assertSame(9.0, (float) json_decode($registro->valores_nuevos, true)['calificacion']);
        $this->assertNotNull($registro->usuario_id);
    }

    /**
     * El folio del acta se guarda con la corrección.
     *
     * Es el dato que convierte un cambio rutinario en algo que hay que poder
     * explicar: si estaba en un acta, esa acta ya no dice lo mismo.
     */
    public function test_se_guarda_el_acta_donde_estaba(): void
    {
        ['historial' => $id] = $this->conCalificacion(8.5, decimales: 0, acta: 'ACTA-2026-014');

        $this->corregir($id, 9);

        $anteriores = json_decode(
            DB::table('auditoria')->where('auditable_id', $id)->value('valores_anteriores'),
            true,
        );

        $this->assertSame('ACTA-2026-014', $anteriores['acta_folio']);
    }

    /**
     * La corrección respeta la escala del plan.
     *
     * Sería absurdo que la pantalla que existe para cuadrar el historial con la
     * escala permitiera meter algo que tampoco cuadra.
     */
    public function test_no_se_puede_corregir_a_algo_fuera_de_escala(): void
    {
        ['historial' => $id] = $this->conCalificacion(8.5, decimales: 0, maxima: 10);

        $this->expectException(ValidationException::class);

        $this->corregir($id, 15);
    }

    /** Y tampoco con más decimales de los que el plan permite. */
    public function test_no_se_puede_corregir_con_decimales_de_mas(): void
    {
        ['historial' => $id] = $this->conCalificacion(8.5, decimales: 0);

        $this->expectException(ValidationException::class);

        $this->corregir($id, 8.75);
    }

    /** Corregir al mismo valor no ensucia la bitácora. */
    public function test_corregir_a_lo_mismo_no_registra_nada(): void
    {
        ['historial' => $id] = $this->conCalificacion(9.0, decimales: 0);

        $this->corregir($id, 9);

        $this->assertSame(0, DB::table('auditoria')->where('auditable_id', $id)->count());
    }

    /** Un renglón borrado no se corrige. */
    public function test_no_se_corrige_lo_borrado(): void
    {
        ['historial' => $id] = $this->conCalificacion(8.5, decimales: 0);

        DB::table('historial')->where('id', $id)->update(['deleted_at' => now()]);

        $this->expectException(AvisoParaElUsuario::class);

        $this->corregir($id, 9);
    }

    // ── Andamiaje ──────────────────────────────────────────────────────────

    private function corregir(int $historial, float $valor): void
    {
        $peticion = Request::create("/escolar/configuraciones/calificaciones/historial/{$historial}", 'PUT', [
            'calificacion' => $valor,
        ]);

        $peticion->setUserResolver(fn () => $this->usuarioConAlcance());

        $this->controlador->corregirCalificacion($peticion, $historial);
    }

    /** @return array<string, mixed> */
    private function conCalificacion(
        float $calificacion,
        int $decimales,
        float $maxima = 10,
        ?string $acta = null,
    ): array {
        $escuela = $this->alumnoInscrito();

        PlanEstudio::whereKey($escuela['plan'])->update([
            'calificacion_minima' => 0,
            'calificacion_maxima' => $maxima,
            'calificacion_minima_aprobatoria' => 6,
            'decimales_calificacion' => $decimales,
            'redondeo_calificacion' => ModoRedondeo::MEDIO_ARRIBA->value,
        ]);

        $ciclo = $this->cicloDePrueba();
        $materia = $this->materiaAbierta($escuela['plan'], $escuela['campus'], $ciclo);

        $historial = $this->fila('historial', [
            'matricula_oferta_id' => $escuela['matricula'],
            'plan_materia_id' => $materia['planMateria'],
            'ciclo_id' => $ciclo,
            'tipo_evaluacion_id' => $this->deCatalogo('tipos_evaluacion'),
            'estatus_id' => $this->deCatalogo('estatus_historial'),
            'calificacion' => $calificacion,
            'acta_folio' => $acta,
        ]);

        return $escuela + compact('historial');
    }
}
