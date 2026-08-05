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

        $this->regla('global', null, '{AAAA}-{###}', consecutivoAnual: true);

        $this->assertSame('2026-001', $this->generador->generar($oferta, anio: 2026));
        $this->assertSame('2026-002', $this->generador->generar($oferta, anio: 2026));
        $this->assertSame('2027-001', $this->generador->generar($oferta, anio: 2027), 'Año nuevo, cuenta nueva.');
    }

    public function test_el_ambito_global_no_reinicia_nunca(): void
    {
        $oferta = $this->ofertaDePrueba();

        $this->regla('global', null, '{AAAA}-{###}', consecutivoAnual: false);

        $this->generador->generar($oferta, anio: 2026);

        $this->assertSame('2027-002', $this->generador->generar($oferta, anio: 2027), 'Sigue la misma cuenta.');
    }

    public function test_el_ambito_por_carrera_lleva_cuentas_separadas(): void
    {
        $primera = $this->ofertaDePrueba();
        $segunda = $this->ofertaDePrueba();

        $this->regla('global', null, 'M{###}', consecutivoPor: 'carrera', consecutivoAnual: false);

        $this->assertSame('M001', $this->generador->generar($primera, anio: 2026));
        $this->assertSame('M002', $this->generador->generar($primera, anio: 2026));
        $this->assertSame('M001', $this->generador->generar($segunda, anio: 2026), 'Otra carrera, otra cuenta.');
    }

    public function test_un_ambito_de_consecutivo_desconocido_falla(): void
    {
        $oferta = $this->ofertaDePrueba();

        // Un valor que la pantalla no ofrece pero que podría llegar de una
        // migración a medias o de una edición a mano en la base.
        $this->regla('global', null, 'M{###}')->update(['consecutivo_por' => 'lo_que_sea']);

        $this->expectException(RuntimeException::class);
        $this->generador->generar($oferta, anio: 2026);
    }

    // ── Los tres formatos que se pidieron ──────────────────────────────────

    /**
     * ClaveNivel + ClaveCarrera + ClavePlan + Año + consecutivo del año.
     *
     * El que motivó el token {NIVEL}: no existía y no había forma de armar este
     * formato sin él.
     */
    public function test_nivel_carrera_plan_anio_y_consecutivo_del_anio(): void
    {
        $oferta = $this->ofertaDePrueba();

        $this->regla('global', null, '{NIVEL}{CARRERA}{PLAN}{AAAA}{###}');

        $esperado = $oferta->carrera->nivelEstudios->clave
            .$oferta->carrera->clave
            .$oferta->plan->clave
            .'2026001';

        $this->assertSame($esperado, $this->generador->generar($oferta, anio: 2026));
    }

    /** Año + ClaveCarrera + consecutivo histórico de la carrera. */
    public function test_anio_carrera_y_consecutivo_historico_de_la_carrera(): void
    {
        $oferta = $this->ofertaDePrueba();

        $this->regla('global', null, '{AAAA}{CARRERA}{####}', consecutivoPor: 'carrera', consecutivoAnual: false);

        $this->generador->generar($oferta, anio: 2026);

        $this->assertSame(
            '2027'.$oferta->carrera->clave.'0002',
            $this->generador->generar($oferta, anio: 2027),
            'El año cambia en la matrícula, pero la cuenta de la carrera sigue.',
        );
    }

    /** ClaveCarrera + Año + consecutivo del campus por año. */
    public function test_carrera_anio_y_consecutivo_del_campus_por_anio(): void
    {
        $oferta = $this->ofertaDePrueba();

        $this->regla('global', null, '{CARRERA}{AAAA}{####}', consecutivoPor: 'campus');

        $this->assertSame($oferta->carrera->clave.'20260001', $this->generador->generar($oferta, anio: 2026));
        $this->assertSame($oferta->carrera->clave.'20260002', $this->generador->generar($oferta, anio: 2026));
        $this->assertSame(
            $oferta->carrera->clave.'20270001',
            $this->generador->generar($oferta, anio: 2027),
            'Año nuevo, cuenta nueva del campus.',
        );
    }

    /**
     * Dos ofertas de campus distintos no comparten cuenta.
     *
     * `ofertaDePrueba()` arma una escuela entera cada vez, así que cada oferta
     * trae su propio campus: es exactamente el caso.
     */
    public function test_el_consecutivo_por_campus_lleva_cuentas_separadas(): void
    {
        $primera = $this->ofertaDePrueba();
        $segunda = $this->ofertaDePrueba();

        $this->regla('global', null, 'C{###}', consecutivoPor: 'campus');

        $this->assertSame('C001', $this->generador->generar($primera, anio: 2026));
        $this->assertSame('C002', $this->generador->generar($primera, anio: 2026));
        $this->assertSame('C001', $this->generador->generar($segunda, anio: 2026), 'Otro campus, otra cuenta.');
    }

    public function test_el_consecutivo_por_nivel_lleva_cuentas_separadas(): void
    {
        $oferta = $this->ofertaDePrueba();

        $this->regla('global', null, 'N{###}', consecutivoPor: 'nivel', consecutivoAnual: false);

        $this->assertSame('N001', $this->generador->generar($oferta, anio: 2026));
        $this->assertSame('N002', $this->generador->generar($oferta, anio: 2027), 'Histórico: el año no lo reinicia.');
    }

    // ── La sugerencia ──────────────────────────────────────────────────────

    /**
     * Previsualizar NO gasta folio.
     *
     * Es lo que sostiene toda la funcionalidad: la ficha del aspirante enseña
     * la matrícula que le tocaría cada vez que alguien la abre. Si consumiera,
     * un expediente visitado tres veces se llevaría tres números y la
     * numeración saldría con huecos que nadie sabría explicar.
     */
    public function test_la_vista_previa_no_consume_el_consecutivo(): void
    {
        $oferta = $this->ofertaDePrueba();

        $this->regla('global', null, '{AAAA}-{####}');

        $this->assertSame('2026-0001', $this->generador->previsualizar($oferta, anio: 2026));
        $this->assertSame('2026-0001', $this->generador->previsualizar($oferta, anio: 2026));
        $this->assertSame('2026-0001', $this->generador->generar($oferta, anio: 2026), 'Y era la que iba a tocar.');
    }

    /** Después de emitir una, la siguiente vista previa ya dice la que sigue. */
    public function test_la_vista_previa_avanza_cuando_alguien_mas_consume(): void
    {
        $oferta = $this->ofertaDePrueba();

        $this->regla('global', null, '{AAAA}-{####}');

        $this->generador->generar($oferta, anio: 2026);

        $this->assertSame('2026-0002', $this->generador->previsualizar($oferta, anio: 2026));
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
        ?string $consecutivoPor = null,
        bool $consecutivoAnual = true,
        bool $activo = true,
    ): ReglaMatricula {
        return ReglaMatricula::create([
            'nombre' => "Regla {$ambito}",
            'ambito' => $ambito,
            'ambito_id' => $ambitoId,
            'plantilla' => $plantilla,
            'consecutivo_por' => $consecutivoPor,
            'consecutivo_anual' => $consecutivoAnual,
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
