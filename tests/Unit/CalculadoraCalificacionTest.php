<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Academico\EsquemaEvaluacion;
use App\Models\Academico\PlanEstudio;
use App\Models\ControlEscolar\CalificacionComponente;
use App\Models\ControlEscolar\Inscripcion;
use App\Services\CalculadoraCalificacion;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

/**
 * La calificación final de un alumno en una materia.
 *
 * Es de lo poco en el sistema que no se puede corregir a la ligera: una vez
 * asentada el acta, el número va al kárdex y de ahí al certificado. Las tres
 * reglas que se comprueban aquí son las que impiden que un descuido se
 * convierta en una materia reprobada.
 *
 * Los modelos se arman en memoria, sin base: lo que se prueba es la aritmética
 * y sus reglas, no cómo se guardan.
 */
class CalculadoraCalificacionTest extends TestCase
{
    private CalculadoraCalificacion $calculadora;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculadora = new CalculadoraCalificacion;
    }

    public function test_pondera_cada_componente_por_su_porcentaje(): void
    {
        $resultado = $this->calcular(
            [1 => ['Parciales', 40], 2 => ['Trabajos', 30], 3 => ['Final', 30]],
            [1 => 8.0, 2 => 10.0, 3 => 7.0],
        );

        // 8*0.40 + 10*0.30 + 7*0.30 = 3.2 + 3 + 2.1
        $this->assertSame(8.3, $resultado->final);
        $this->assertTrue($resultado->completa);
        $this->assertSame([], $resultado->faltantes);
    }

    /**
     * Un cero es una calificación —el alumno no presentó—; un nulo es que el
     * docente todavía no llega ahí. Ponderar el nulo como cero reprobaría gente
     * por descuido.
     */
    public function test_un_componente_sin_capturar_deja_la_calificacion_incompleta(): void
    {
        $resultado = $this->calcular(
            [1 => ['Parciales', 50], 2 => ['Final', 50]],
            [1 => 10.0],
        );

        $this->assertFalse($resultado->completa);
        $this->assertSame(['Final'], $resultado->faltantes);
        // Lo que lleva sí se informa: es el avance, no la calificación.
        $this->assertSame(5.0, $resultado->final);
        $this->assertNull($resultado->aprobada, 'Sin todo capturado no se dictamina.');
    }

    public function test_un_cero_capturado_si_cuenta_como_calificacion(): void
    {
        $resultado = $this->calcular(
            [1 => ['Parciales', 50], 2 => ['Final', 50]],
            [1 => 10.0, 2 => 0.0],
        );

        $this->assertTrue($resultado->completa, 'Un cero está capturado.');
        $this->assertSame([], $resultado->faltantes);
        $this->assertSame(5.0, $resultado->final);
    }

    /**
     * Vale más una materia sin calificación que un kárdex con números que nadie
     * puede reproducir.
     */
    public function test_si_el_esquema_no_suma_cien_no_se_calcula(): void
    {
        $resultado = $this->calcular(
            [1 => ['Parciales', 40], 2 => ['Final', 40]],
            [1 => 10.0, 2 => 10.0],
        );

        $this->assertNull($resultado->final);
        $this->assertFalse($resultado->completa);
        $this->assertStringContainsString('80%', $resultado->motivo);
        $this->assertStringContainsString('100%', $resultado->motivo);
    }

    public function test_sin_esquema_se_explica_el_motivo(): void
    {
        $resultado = $this->calcular([], []);

        $this->assertNull($resultado->final);
        $this->assertStringContainsString('esquema de evaluación', $resultado->motivo);
    }

    /** Cada plan tiene su escala; la mínima no es una constante del código. */
    public function test_aprobar_lo_decide_la_minima_del_plan(): void
    {
        $esquema = [1 => ['Único', 100]];
        $capturado = [1 => 7.0];

        $this->assertTrue($this->calcular($esquema, $capturado, 6.0)->aprobada);
        $this->assertFalse($this->calcular($esquema, $capturado, 8.0)->aprobada);
        // Justo en la mínima aprueba: es «mínima aprobatoria», no «más que».
        $this->assertTrue($this->calcular($esquema, $capturado, 7.0)->aprobada);
    }

    public function test_la_suma_admite_decimales_sin_desbordar_por_redondeo(): void
    {
        // 33.33 + 33.33 + 33.34 = 100 exacto sólo si se tolera el epsilon.
        $resultado = $this->calcular(
            [1 => ['A', 33.33], 2 => ['B', 33.33], 3 => ['C', 33.34]],
            [1 => 9.0, 2 => 9.0, 3 => 9.0],
        );

        $this->assertNull($resultado->motivo, 'El esquema es válido.');
        $this->assertSame(9.0, $resultado->final);
    }

    // ── Andamiaje ──────────────────────────────────────────────────────────

    /**
     * @param  array<int, array{0: string, 1: float}>  $componentes  id => [nombre, porcentaje]
     * @param  array<int, float>  $capturado  id del componente => calificación
     */
    private function calcular(array $componentes, array $capturado, float $minima = 6.0)
    {
        $esquema = new Collection(array_map(
            fn (int $id, array $datos) => (new EsquemaEvaluacion([
                'componente' => $datos[0],
                'porcentaje' => $datos[1],
            ]))->forceFill(['id' => $id]),
            array_keys($componentes),
            $componentes,
        ));

        $inscripcion = new Inscripcion;

        $inscripcion->setRelation('calificaciones', new Collection(array_map(
            fn (int $id, float $valor) => new CalificacionComponente([
                'esquema_evaluacion_id' => $id,
                'calificacion' => $valor,
            ]),
            array_keys($capturado),
            $capturado,
        )));

        $plan = new PlanEstudio(['calificacion_minima_aprobatoria' => $minima]);

        return $this->calculadora->calcular($inscripcion, $esquema, $plan);
    }
}
