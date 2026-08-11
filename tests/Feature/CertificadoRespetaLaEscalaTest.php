<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ModoRedondeo;
use App\Models\Academico\PlanEstudio;
use App\Models\ControlEscolar\Historial;
use App\Services\ConstructorCertificadoXml;
use App\Support\Creditos;
use Tests\Concerns\CreaEscuelaDePrueba;
use Tests\TenantTestCase;

/**
 * El certificado tiene que decir lo mismo que el expediente.
 *
 * ── Por qué esto es lo más grave de la familia ─────────────────────────────
 * El certificado electrónico se sella y se manda a la SEP. Calculaba su
 * promedio con dos decimales fijos, ignorando la escala del plan: una escuela
 * con plan de enteros veía un 8 en el expediente y en el historial académico, y su
 * certificado oficial decía 8.33. Dos documentos de la misma escuela que no
 * concuerdan, y el que vale es el que estaba mal.
 *
 * El mismo enredo tenían los créditos, escritos tres veces con dos precisiones:
 * el mismo alumno sumaba 295 en una pantalla y 295.3 en otra.
 */
class CertificadoRespetaLaEscalaTest extends TenantTestCase
{
    use CreaEscuelaDePrueba;

    /**
     * El promedio del certificado sale de la escala del PLAN.
     *
     * Se comprueba sobre el método que arma el dato, no sobre el XML entero:
     * lo que se rompió fue el cálculo, y el resto del documento tiene sus
     * propias pruebas.
     */
    public function test_el_promedio_del_certificado_usa_la_escala_del_plan(): void
    {
        $plan = $this->planCon(decimales: 0);

        // 8.5 y 8.0 promedian 8.25; con enteros y medio-arriba, 8.
        $this->assertSame(8.0, $this->promedioDelCertificado([8.5, 8.0], $plan));
    }

    /** Y con dos decimales, el mismo caso da 8.25. */
    public function test_con_dos_decimales_el_promedio_conserva_el_detalle(): void
    {
        $plan = $this->planCon(decimales: 2);

        $this->assertSame(8.25, $this->promedioDelCertificado([8.5, 8.0], $plan));
    }

    /**
     * Y coincide con lo que enseña el expediente.
     *
     * Es la comprobación que importa: no que cada uno sea correcto por su lado,
     * sino que digan lo MISMO. Los dos preguntan al plan, así que no pueden
     * discrepar sin que esto falle.
     */
    public function test_certificado_y_expediente_dicen_lo_mismo(): void
    {
        $plan = $this->planCon(decimales: 0);
        $notas = [8.5, 8.0, 9.0];

        $delExpediente = PlanEstudio::redondearCon($plan, array_sum($notas) / count($notas));

        $this->assertSame($delExpediente, $this->promedioDelCertificado($notas, $plan));
    }

    /**
     * Los créditos se suman igual en todas partes.
     *
     * Estaban escritos tres veces —expediente, portal del padre y certificado—
     * con dos precisiones distintas.
     */
    public function test_los_creditos_se_suman_igual_en_todas_partes(): void
    {
        $aprobadas = collect([
            $this->materiaDe(7.25),
            $this->materiaDe(3.5),
        ]);

        $this->assertSame(10.75, Creditos::sumar($aprobadas));
    }

    /** Sin materias aprobadas son cero créditos, no un error. */
    public function test_sin_materias_son_cero_creditos(): void
    {
        $this->assertSame(0.0, Creditos::sumar(collect()));
    }

    /** Una materia sin créditos declarados no rompe la suma. */
    public function test_una_materia_sin_creditos_no_rompe_la_suma(): void
    {
        $this->assertSame(5.0, Creditos::sumar(collect([
            $this->materiaDe(5),
            (object) ['planMateria' => null],
        ])));
    }

    // ── Andamiaje ──────────────────────────────────────────────────────────

    /**
     * El promedio tal como lo calcula el constructor del certificado.
     *
     * Se llega por reflexión porque el método es privado y su contrato es
     * interno: lo que se quiere fijar es el RESULTADO, no obligar a exponerlo.
     *
     * @param  array<int, float>  $calificaciones
     */
    private function promedioDelCertificado(array $calificaciones, PlanEstudio $plan): ?float
    {
        $constructor = app(ConstructorCertificadoXml::class);

        $metodo = new \ReflectionMethod($constructor, 'promedio');
        $metodo->setAccessible(true);

        /*
         * Modelos de verdad, sin guardar: el cálculo tipa sus renglones como
         * `Historial` y un objeto suelto no pasa. No hace falta que existan en
         * la base —sólo se les lee la calificación—, pero sí que sean lo que
         * dicen ser.
         */
        $mejores = collect($calificaciones)->map(
            fn (float $c) => new Historial(['calificacion' => $c]),
        );

        return $metodo->invoke($constructor, $mejores, $plan);
    }

    private function planCon(int $decimales): PlanEstudio
    {
        $escuela = $this->alumnoInscrito();

        PlanEstudio::whereKey($escuela['plan'])->update([
            'calificacion_minima' => 0,
            'calificacion_maxima' => 10,
            'calificacion_minima_aprobatoria' => 6,
            'decimales_calificacion' => $decimales,
            'redondeo_calificacion' => ModoRedondeo::MEDIO_ARRIBA->value,
        ]);

        return PlanEstudio::findOrFail($escuela['plan']);
    }

    /** Un renglón de historial con esos créditos, sin tocar la base. */
    private function materiaDe(float $creditos): object
    {
        return (object) [
            'planMateria' => (object) ['asignatura' => (object) ['creditos' => $creditos]],
        ];
    }
}
