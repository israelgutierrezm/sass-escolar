<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Academico\Oferta;
use App\Models\Admisiones\ReglaMatricula;
use App\Services\GeneradorMatricula;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Concerns\CreaEscuelaDePrueba;
use Tests\TenantTestCase;

/**
 * La matrícula del alumno.
 *
 * Es su identificador para toda la vida escolar: aparece en el kárdex, en el
 * certificado y en el título. Dos alumnos con la misma matrícula es un daño que
 * no se puede reparar sin tocar documentos ya emitidos, así que el consecutivo
 * se toma con un incremento atómico y no con un MAX()+1.
 */
class GeneradorMatriculaTest extends TenantTestCase
{
    use CreaEscuelaDePrueba;

    private GeneradorMatricula $generador;

    protected function setUp(): void
    {
        parent::setUp();

        $this->generador = app(GeneradorMatricula::class);
    }

    public function test_arma_la_matricula_con_los_tokens_de_la_plantilla(): void
    {
        $escuela = $this->alumnoInscrito();
        $oferta = Oferta::with(['carrera', 'plan', 'campus'])->findOrFail($escuela['oferta']);

        $this->regla('global', null, '{AAAA}-{CARRERA}-{####}');

        $matricula = $this->generador->generar($oferta, anio: 2026);

        $this->assertSame("2026-{$oferta->carrera->clave}-0001", $matricula);
    }

    /** El relleno de ceros lo decide la cantidad de almohadillas. */
    public function test_el_consecutivo_se_rellena_segun_la_plantilla(): void
    {
        $oferta = $this->ofertaDePrueba();

        $this->regla('global', null, 'M{##}');
        $this->assertSame('M01', $this->generador->generar($oferta, anio: 2026));

        ReglaMatricula::query()->update(['plantilla' => 'M{######}']);
        $this->assertSame('M000002', $this->generador->generar($oferta, anio: 2026));
    }

    public function test_el_anio_corto_usa_las_dos_ultimas_cifras(): void
    {
        $oferta = $this->ofertaDePrueba();

        $this->regla('global', null, '{AA}{###}');

        $this->assertSame('26001', $this->generador->generar($oferta, anio: 2026));
    }

    /**
     * Lo que hace que dos alumnos no compartan matrícula. Se pide varias veces
     * seguidas y ninguna repite.
     */
    public function test_cada_llamada_consume_un_numero_distinto(): void
    {
        $oferta = $this->ofertaDePrueba();

        $this->regla('global', null, 'M{####}');

        $matriculas = array_map(fn () => $this->generador->generar($oferta, anio: 2026), range(1, 5));

        $this->assertSame(['M0001', 'M0002', 'M0003', 'M0004', 'M0005'], $matriculas);
        $this->assertCount(5, array_unique($matriculas));
    }

    /**
     * La regla se resuelve de lo más específico a lo más general, así una
     * escuela puede tener un formato distinto para posgrado sin duplicarlo en
     * cada plan.
     */
    public function test_la_regla_del_plan_gana_a_la_de_la_carrera_y_a_la_global(): void
    {
        $escuela = $this->alumnoInscrito();
        $oferta = Oferta::with(['carrera', 'plan', 'campus'])->findOrFail($escuela['oferta']);

        $this->regla('global', null, 'GLOBAL-{###}');
        $this->regla('carrera', $oferta->carrera_id, 'CARRERA-{###}');
        $this->regla('plan', $oferta->plan_id, 'PLAN-{###}');

        $this->assertStringStartsWith('PLAN-', $this->generador->generar($oferta, anio: 2026));
    }

    public function test_sin_regla_del_plan_se_usa_la_de_la_carrera(): void
    {
        $escuela = $this->alumnoInscrito();
        $oferta = Oferta::with(['carrera', 'plan', 'campus'])->findOrFail($escuela['oferta']);

        $this->regla('global', null, 'GLOBAL-{###}');
        $this->regla('carrera', $oferta->carrera_id, 'CARRERA-{###}');

        $this->assertStringStartsWith('CARRERA-', $this->generador->generar($oferta, anio: 2026));
    }

    /** Una regla apagada no se usa aunque sea la más específica. */
    public function test_una_regla_inactiva_se_ignora(): void
    {
        $escuela = $this->alumnoInscrito();
        $oferta = Oferta::with(['carrera', 'plan', 'campus'])->findOrFail($escuela['oferta']);

        $this->regla('global', null, 'GLOBAL-{###}');
        $this->regla('plan', $oferta->plan_id, 'PLAN-{###}', activo: false);

        $this->assertStringStartsWith('GLOBAL-', $this->generador->generar($oferta, anio: 2026));
    }

    /**
     * Sin regla no se inventa un formato: sería empezar a numerar alumnos con
     * un criterio que nadie eligió y que después no se puede deshacer.
     */
    public function test_sin_ninguna_regla_configurada_falla(): void
    {
        $oferta = $this->ofertaDePrueba();

        $this->expectException(RuntimeException::class);
        $this->generador->generar($oferta, anio: 2026);
    }

    // ── El reinicio del consecutivo ────────────────────────────────────────

    public function test_el_ambito_anual_reinicia_cada_anio(): void
    {
        $oferta = $this->ofertaDePrueba();

        $this->regla('global', null, '{AAAA}-{###}', ambitoConsecutivo: 'anio');

        $this->assertSame('2026-001', $this->generador->generar($oferta, anio: 2026));
        $this->assertSame('2026-002', $this->generador->generar($oferta, anio: 2026));
        $this->assertSame('2027-001', $this->generador->generar($oferta, anio: 2027), 'Año nuevo, cuenta nueva.');
    }

    public function test_el_ambito_global_no_reinicia_nunca(): void
    {
        $oferta = $this->ofertaDePrueba();

        $this->regla('global', null, '{AAAA}-{###}', ambitoConsecutivo: 'global');

        $this->generador->generar($oferta, anio: 2026);

        $this->assertSame('2027-002', $this->generador->generar($oferta, anio: 2027), 'Sigue la misma cuenta.');
    }

    public function test_el_ambito_por_carrera_lleva_cuentas_separadas(): void
    {
        $primera = $this->ofertaDePrueba();
        $segunda = $this->ofertaDePrueba();

        $this->regla('global', null, 'M{###}', ambitoConsecutivo: 'carrera');

        $this->assertSame('M001', $this->generador->generar($primera, anio: 2026));
        $this->assertSame('M002', $this->generador->generar($primera, anio: 2026));
        $this->assertSame('M001', $this->generador->generar($segunda, anio: 2026), 'Otra carrera, otra cuenta.');
    }

    public function test_un_ambito_de_consecutivo_desconocido_falla(): void
    {
        $oferta = $this->ofertaDePrueba();

        $this->regla('global', null, 'M{###}', ambitoConsecutivo: 'lo_que_sea');

        $this->expectException(RuntimeException::class);
        $this->generador->generar($oferta, anio: 2026);
    }

    // ── Andamiaje ──────────────────────────────────────────────────────────

    private function ofertaDePrueba(): Oferta
    {
        return Oferta::with(['carrera', 'plan', 'campus'])->findOrFail($this->alumnoInscrito()['oferta']);
    }

    private function regla(
        string $ambito,
        ?int $ambitoId,
        string $plantilla,
        string $ambitoConsecutivo = 'anio',
        bool $activo = true,
    ): ReglaMatricula {
        return ReglaMatricula::create([
            'nombre' => "Regla {$ambito}",
            'ambito' => $ambito,
            'ambito_id' => $ambitoId,
            'plantilla' => $plantilla,
            'ambito_consecutivo' => $ambitoConsecutivo,
            'activo' => $activo,
        ]);
    }

    protected function tearDown(): void
    {
        // El contador se toca con SQL crudo fuera de Eloquent; la transacción de
        // la prueba lo revierte igual, pero se deja explícito porque un
        // consecutivo que sobreviviera entre pruebas las haría fallar por orden.
        DB::table('contadores_matricula')->delete();

        parent::tearDown();
    }
}
