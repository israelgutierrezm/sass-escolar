<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ModoRedondeo;
use App\Models\Academico\PlanEstudio;
use App\Services\CalificacionesFueraDeEscala;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreaEscuelaDePrueba;
use Tests\TenantTestCase;

/**
 * Lo ya capturado que no cuadra con la escala de hoy.
 *
 * Cambiar la escala de un plan sólo rige para lo que se capture después: el
 * historial no se toca, porque son actas emitidas y reescribir una calificación
 * pasada sin que nadie lo pida es lo que un sistema escolar no debe hacer.
 *
 * Pero entonces la incoherencia se queda callada —la escuela configura enteros y
 * sigue viendo 8.5 en los historial académico—, y esto existe para decirlo. No arregla nada:
 * cuenta, que es lo que permite decidir con datos.
 */
class CalificacionesFueraDeEscalaTest extends TenantTestCase
{
    use CreaEscuelaDePrueba;

    private CalificacionesFueraDeEscala $detector;

    protected function setUp(): void
    {
        parent::setUp();

        $this->detector = app(CalificacionesFueraDeEscala::class);
    }

    /** Con la escala que ya tenían, no hay nada que avisar. */
    public function test_lo_que_cuadra_no_se_reporta(): void
    {
        $escuela = $this->conCalificaciones([8.5, 9.0, 7.5], decimales: 1);

        $this->assertArrayNotHasKey($escuela['plan'], $this->detector->porPlan());
    }

    /**
     * Al pasar el plan a enteros, aparecen las que traen decimales.
     *
     * Es el caso que motivó todo esto: se configura «aquí calificamos con
     * enteros» y el historial sigue lleno de 8.5.
     */
    public function test_al_pasar_a_enteros_aparecen_los_decimales(): void
    {
        $escuela = $this->conCalificaciones([8.5, 9.0, 7.25, 10.0], decimales: 0);

        $conteo = $this->detector->porPlan()[$escuela['plan']];

        $this->assertSame(2, $conteo['precision'], 'El 8.5 y el 7.25; el 9 y el 10 ya son enteros.');
        $this->assertSame(0, $conteo['rango']);
    }

    /**
     * Salirse del rango se cuenta APARTE, y con razón.
     *
     * Un 85 en una escala de 0 a 10 no es un decimal de más: es otra unidad, y
     * casi siempre significa que el plan cambió de escala entera y el historial
     * se quedó en la anterior. Mezclarlo con lo otro escondería el caso grave
     * detrás del leve.
     */
    public function test_el_rango_se_cuenta_aparte(): void
    {
        $escuela = $this->conCalificaciones([85.0, 90.0, 9.0], decimales: 1, minima: 0, maxima: 10);

        $conteo = $this->detector->porPlan()[$escuela['plan']];

        $this->assertSame(2, $conteo['rango'], 'El 85 y el 90 no caben en una escala de 0 a 10.');
        $this->assertSame(0, $conteo['precision'], 'Ninguna tiene decimales de más.');
    }

    /** Lo de un plan no se le cuenta a otro. */
    public function test_cada_plan_cuenta_lo_suyo(): void
    {
        $conDecimales = $this->conCalificaciones([8.5], decimales: 0);
        $limpio = $this->conCalificaciones([9.0], decimales: 0);

        $porPlan = $this->detector->porPlan();

        $this->assertArrayHasKey($conDecimales['plan'], $porPlan);
        $this->assertArrayNotHasKey($limpio['plan'], $porPlan);
    }

    /**
     * El detalle dice qué se capturó y qué quedaría al redondear.
     *
     * Sin el «quedaría», la lista obliga a hacer la cuenta a mano para saber si
     * el cambio es inocuo o le mueve el promedio a alguien.
     */
    public function test_el_detalle_dice_que_quedaria(): void
    {
        $escuela = $this->conCalificaciones([8.5], decimales: 0);
        $plan = PlanEstudio::findOrFail($escuela['plan']);

        $fila = $this->detector->deUnPlan($plan)->first();

        $this->assertSame(8.5, (float) $fila->calificacion);
        $this->assertSame(9.0, (float) $fila->sugerida, 'Con enteros, 8.5 redondea a 9.');
        $this->assertNotEmpty($fila->matricula, 'Hay que poder identificar de quién es.');
    }

    /**
     * El detalle lista SÓLO las que no cuadran.
     *
     * Se escapó al escribirlo: el conteo filtraba y la lista no, así que la
     * pantalla enseñaba todas las calificaciones del plan —la que sobra y la
     * que está perfecta— y obligaba a buscar a ojo cuál había que corregir. Se
     * vio en el navegador, con 96 renglones donde debía haber 85.
     */
    public function test_el_detalle_no_trae_las_que_ya_cuadran(): void
    {
        $escuela = $this->conCalificaciones([8.5, 9.0, 7.25, 10.0], decimales: 0);
        $plan = PlanEstudio::findOrFail($escuela['plan']);

        $filas = $this->detector->deUnPlan($plan);

        $this->assertCount(2, $filas, 'Sólo el 8.5 y el 7.25.');
        $this->assertEqualsCanonicalizing(
            [8.5, 7.25],
            $filas->map(fn ($f) => (float) $f->calificacion)->all(),
        );
    }

    /**
     * Una calificación borrada no cuenta.
     *
     * El historial se corrige con borrado suave; una fila retirada no debe
     * seguir apareciendo como problema.
     */
    public function test_lo_borrado_no_cuenta(): void
    {
        $escuela = $this->conCalificaciones([8.5], decimales: 0);

        DB::table('historial')
            ->where('matricula_oferta_id', $escuela['matricula'])
            ->update(['deleted_at' => now()]);

        $this->assertArrayNotHasKey($escuela['plan'], $this->detector->porPlan());
    }

    // ── Andamiaje ──────────────────────────────────────────────────────────

    /**
     * Una escuela con esas calificaciones ya asentadas y esta escala.
     *
     * @param  array<int, float>  $calificaciones
     * @return array<string, mixed>
     */
    private function conCalificaciones(
        array $calificaciones,
        int $decimales,
        float $minima = 0,
        float $maxima = 10,
    ): array {
        $escuela = $this->alumnoInscrito();

        PlanEstudio::whereKey($escuela['plan'])->update([
            'calificacion_minima' => $minima,
            'calificacion_maxima' => $maxima,
            'calificacion_minima_aprobatoria' => $minima,
            'decimales_calificacion' => $decimales,
            'redondeo_calificacion' => ModoRedondeo::MEDIO_ARRIBA->value,
        ]);

        $ciclo = $this->cicloDePrueba();

        // El renglón de historial cuelga de una materia del plan; no vale
        // inventar el id porque la tabla exige que exista.
        $materia = $this->materiaAbierta($escuela['plan'], $escuela['campus'], $ciclo);

        foreach ($calificaciones as $calificacion) {
            $this->fila('historial', [
                'matricula_oferta_id' => $escuela['matricula'],
                'plan_materia_id' => $materia['planMateria'],
                'ciclo_id' => $ciclo,
                'tipo_evaluacion_id' => $this->deCatalogo('tipos_evaluacion'),
                'estatus_id' => $this->deCatalogo('estatus_historial'),
                'calificacion' => $calificacion,
            ]);
        }

        return $escuela;
    }
}
