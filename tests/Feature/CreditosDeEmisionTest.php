<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\AvisoParaElUsuario;
use App\Models\Landlord\ConsumoEmision;
use App\Models\Landlord\SaldoEmision;
use App\Services\Emision\CreditosDeEmision;
use Tests\TenantTestCase;

/**
 * Qué se cobra por cada XML de certificación y titulación.
 *
 * ── Lo difícil no es contar, es no cobrar de más ───────────────────────────
 * Un XML sale mal por un dato mal capturado o porque el web service de la SEP lo
 * rechaza, y hay que rehacerlo. Eso no es un consumo nuevo: es el mismo trámite
 * del mismo alumno, y cobrarlo otra vez es cobrarle a la escuela por un error.
 *
 * El trámite se reconoce por CURP + plan: no por folio, que cambia justamente al
 * regenerar. Y distingue el caso en que SÍ son dos cobros: el mismo alumno
 * titulándose de dos programas académicos distintas.
 */
class CreditosDeEmisionTest extends TenantTestCase
{
    private CreditosDeEmision $creditos;

    protected function setUp(): void
    {
        parent::setUp();

        $this->creditos = app(CreditosDeEmision::class);
    }

    /** El primero cobra y descuenta. */
    public function test_el_primer_xml_gasta_un_credito(): void
    {
        $this->conSaldo(SaldoEmision::PREPAGO, 5);

        $this->assertTrue($this->emitir('CURP010101HDFAAA01', 'LIC-DER-2020'));
        $this->assertSame(4, $this->saldo()->creditos);
    }

    /**
     * Rehacer el MISMO trámite no vuelve a cobrar.
     *
     * Es el caso que motivó todo: se capturó mal una fecha, se corrige y se
     * regenera. La escuela no tiene por qué pagar dos veces por eso.
     */
    public function test_regenerar_el_mismo_tramite_no_cobra(): void
    {
        $this->conSaldo(SaldoEmision::PREPAGO, 5);

        $this->emitir('CURP010101HDFAAA01', 'LIC-DER-2020');
        $this->assertFalse($this->emitir('CURP010101HDFAAA01', 'LIC-DER-2020'), 'La segunda vez no cobra.');
        $this->assertFalse($this->emitir('CURP010101HDFAAA01', 'LIC-DER-2020'), 'Ni la tercera.');

        $this->assertSame(4, $this->saldo()->creditos, 'Sólo se descontó uno.');
    }

    /**
     * Pero se registran todas.
     *
     * Sin el renglón no habría forma de ver que una escuela reemite mucho por
     * errores de captura, que es justo lo que conviene detectar.
     */
    public function test_las_regeneraciones_quedan_registradas(): void
    {
        $this->conSaldo(SaldoEmision::PREPAGO, 5);

        $this->emitir('CURP010101HDFAAA01', 'LIC-DER-2020');
        $this->emitir('CURP010101HDFAAA01', 'LIC-DER-2020');

        $resumen = $this->creditos->resumen('escuela-x');

        $this->assertSame(2, $resumen['emitidos']);
        $this->assertSame(1, $resumen['cobrados']);
        $this->assertSame(1, $resumen['regenerados']);
    }

    /**
     * El mismo alumno en OTRO plan sí son dos trámites.
     *
     * Titularse de dos programas académicos son dos documentos distintos y dos cobros
     * legítimos: si sólo se mirara la CURP, el segundo saldría gratis.
     */
    public function test_el_mismo_alumno_en_otro_plan_si_cobra(): void
    {
        $this->conSaldo(SaldoEmision::PREPAGO, 5);

        $this->assertTrue($this->emitir('CURP010101HDFAAA01', 'LIC-DER-2020'));
        $this->assertTrue($this->emitir('CURP010101HDFAAA01', 'LIC-PSI-2020'), 'Otro programa académico, otro trámite.');

        $this->assertSame(3, $this->saldo()->creditos);
    }

    /**
     * Y el mismo trámite en OTRO tipo también.
     *
     * El certificado y el título son dos documentos: quien ya certificó a un
     * alumno paga su título aparte.
     */
    public function test_certificado_y_titulo_se_cobran_por_separado(): void
    {
        $this->conSaldo(SaldoEmision::PREPAGO, 5);

        $this->assertTrue($this->emitir('CURP010101HDFAAA01', 'LIC-DER-2020', ConsumoEmision::CERTIFICADO));
        $this->assertTrue($this->emitir('CURP010101HDFAAA01', 'LIC-DER-2020', ConsumoEmision::TITULO));
    }

    /**
     * La CURP se compara sin distinguir mayúsculas.
     *
     * Capturada en minúsculas por una importación, cobraría otra vez por lo
     * mismo sin que nadie lo notara.
     */
    public function test_la_curp_no_distingue_mayusculas(): void
    {
        $this->conSaldo(SaldoEmision::PREPAGO, 5);

        $this->emitir('CURP010101HDFAAA01', 'LIC-DER-2020');

        $this->assertFalse($this->emitir('curp010101hdfaaa01', 'LIC-DER-2020'));
        $this->assertFalse($this->emitir('  CURP010101HDFAAA01  ', 'LIC-DER-2020'), 'Ni con espacios.');
    }

    // ── Modalidades ────────────────────────────────────────────────────────

    /** En ilimitado nunca cobra, pero se cuenta igual. */
    public function test_ilimitado_cuenta_pero_no_cobra(): void
    {
        $this->conSaldo(SaldoEmision::ILIMITADO, 0);

        $this->assertFalse($this->emitir('CURP010101HDFAAA01', 'LIC-DER-2020'));
        $this->assertSame(1, $this->creditos->resumen('escuela-x')['emitidos']);
    }

    /** En postpago cobra —para poder facturarlo— pero no descuenta saldo. */
    public function test_postpago_cobra_sin_descontar(): void
    {
        $this->conSaldo(SaldoEmision::POSTPAGO, 0);

        $this->assertTrue($this->emitir('CURP010101HDFAAA01', 'LIC-DER-2020'));
        $this->assertSame(0, $this->saldo()->creditos, 'No hay saldo que descontar.');
        $this->assertSame(1, $this->creditos->resumen('escuela-x')['cobrados']);
    }

    // ── La comprobación previa ─────────────────────────────────────────────

    /**
     * Sin créditos suficientes NO se empieza el lote.
     *
     * Firmar hasta donde alcance dejaría unos alumnos certificados y otros no,
     * y habría que volver a entrar sabiendo por dónde se quedó.
     */
    public function test_no_se_empieza_un_lote_que_no_alcanza(): void
    {
        $this->conSaldo(SaldoEmision::PREPAGO, 2);

        $this->expectException(AvisoParaElUsuario::class);

        $this->creditos->exigirQuePueda('escuela-x', ConsumoEmision::CERTIFICADO, [
            ['curp' => 'CURP010101HDFAAA01', 'plan' => 'LIC-DER-2020'],
            ['curp' => 'CURP020202HDFBBB02', 'plan' => 'LIC-DER-2020'],
            ['curp' => 'CURP030303HDFCCC03', 'plan' => 'LIC-DER-2020'],
        ]);
    }

    /**
     * Y las regeneraciones NO cuentan para esa comprobación.
     *
     * Un lote de tres donde dos son rehechos sólo necesita un crédito: exigir
     * tres bloquearía una corrección que no cuesta nada.
     */
    public function test_las_regeneraciones_no_piden_creditos(): void
    {
        $this->conSaldo(SaldoEmision::PREPAGO, 1);

        $this->emitir('CURP010101HDFAAA01', 'LIC-DER-2020');
        $this->conSaldo(SaldoEmision::PREPAGO, 1);

        // Dos rehechos y uno nuevo: sólo el nuevo pide crédito.
        $this->creditos->exigirQuePueda('escuela-x', ConsumoEmision::CERTIFICADO, [
            ['curp' => 'CURP010101HDFAAA01', 'plan' => 'LIC-DER-2020'],
            ['curp' => 'CURP999999HDFZZZ09', 'plan' => 'LIC-DER-2020'],
        ]);

        $this->assertTrue(true, 'No lanzó: con 1 crédito alcanza.');
    }

    /** El mismo alumno repetido en el lote se cuenta una vez. */
    public function test_un_repetido_en_el_lote_no_cuenta_dos_veces(): void
    {
        $this->conSaldo(SaldoEmision::PREPAGO, 1);

        $this->assertSame(1, $this->creditos->cuantosCobrarian('escuela-x', ConsumoEmision::CERTIFICADO, [
            ['curp' => 'CURP010101HDFAAA01', 'plan' => 'LIC-DER-2020'],
            ['curp' => 'CURP010101HDFAAA01', 'plan' => 'LIC-DER-2020'],
        ]));
    }

    /** Postpago e ilimitado nunca bloquean. */
    public function test_postpago_e_ilimitado_no_bloquean(): void
    {
        foreach ([SaldoEmision::POSTPAGO, SaldoEmision::ILIMITADO] as $modalidad) {
            $this->conSaldo($modalidad, 0);

            $this->creditos->exigirQuePueda('escuela-x', ConsumoEmision::CERTIFICADO, [
                ['curp' => 'CURP010101HDFAAA01', 'plan' => 'LIC-DER-2020'],
                ['curp' => 'CURP020202HDFBBB02', 'plan' => 'LIC-DER-2020'],
            ]);
        }

        $this->assertTrue(true, 'Ninguna de las dos lanzó.');
    }

    /**
     * El ciclo real: se firma, sale mal, se regenera y se vuelve a firmar.
     *
     * Es la secuencia que motivó todo esto, y la prueba que importa: al final
     * la escuela ha emitido dos XML del mismo documento y ha pagado uno.
     */
    public function test_el_ciclo_de_regenerar_cobra_una_sola_vez(): void
    {
        $this->conSaldo(SaldoEmision::PREPAGO, 3);

        // Primera firma del lote: dos alumnos, dos créditos.
        $this->creditos->exigirQuePueda('escuela-x', ConsumoEmision::CERTIFICADO, [
            ['curp' => 'CURP010101HDFAAA01', 'plan' => 'LIC-DER-2020'],
            ['curp' => 'CURP020202HDFBBB02', 'plan' => 'LIC-DER-2020'],
        ]);
        $this->emitir('CURP010101HDFAAA01', 'LIC-DER-2020');
        $this->emitir('CURP020202HDFBBB02', 'LIC-DER-2020');

        $this->assertSame(1, $this->saldo()->creditos, 'De 3 quedan 1.');

        // Uno salió con la fecha mal: se regenera y se vuelve a firmar el lote.
        $this->creditos->exigirQuePueda('escuela-x', ConsumoEmision::CERTIFICADO, [
            ['curp' => 'CURP010101HDFAAA01', 'plan' => 'LIC-DER-2020'],
        ]);
        $this->emitir('CURP010101HDFAAA01', 'LIC-DER-2020');

        $this->assertSame(1, $this->saldo()->creditos, 'Rehacer no costó nada.');

        $resumen = $this->creditos->resumen('escuela-x');
        $this->assertSame(3, $resumen['emitidos'], 'Se emitieron tres XML…');
        $this->assertSame(2, $resumen['cobrados'], '…y se pagaron dos.');
    }

    /**
     * El resumen dice de qué son los XML, no sólo cuántos.
     *
     * El total responde «cuánto llevamos gastado»; el desglose responde la que
     * viene justo después, que es de cuál. Son dos trámites distintos y detrás
     * suele haber dos áreas distintas.
     */
    public function test_el_resumen_separa_certificados_de_titulos(): void
    {
        $this->conSaldo(SaldoEmision::PREPAGO, 10);

        $this->emitir('CURP010101HDFAAA01', 'LIC-DER-2020', ConsumoEmision::CERTIFICADO);
        $this->emitir('CURP020202MDFBBB02', 'LIC-DER-2020', ConsumoEmision::CERTIFICADO);
        $this->emitir('CURP010101HDFAAA01', 'LIC-DER-2020', ConsumoEmision::TITULO);

        $resumen = $this->creditos->resumen('escuela-x');

        $this->assertSame(3, $resumen['emitidos']);
        $this->assertSame(2, $resumen['certificados']);
        $this->assertSame(1, $resumen['titulos']);
    }

    /**
     * Y el desglose cuenta lo rehecho, igual que el total.
     *
     * Si contara sólo lo cobrado, las dos cifras no cuadrarían entre sí y la
     * suma del desglose sería menor que el total, sin explicación a la vista.
     */
    public function test_el_desglose_suma_lo_mismo_que_el_total(): void
    {
        $this->conSaldo(SaldoEmision::PREPAGO, 10);

        $this->emitir('CURP010101HDFAAA01', 'LIC-DER-2020', ConsumoEmision::CERTIFICADO);
        // El mismo trámite otra vez: se rehizo, no cobra, pero se emitió.
        $this->emitir('CURP010101HDFAAA01', 'LIC-DER-2020', ConsumoEmision::CERTIFICADO);
        $this->emitir('CURP010101HDFAAA01', 'LIC-DER-2020', ConsumoEmision::TITULO);

        $resumen = $this->creditos->resumen('escuela-x');

        $this->assertSame(
            $resumen['emitidos'],
            $resumen['certificados'] + $resumen['titulos'],
            'El desglose tiene que sumar el total.',
        );
        $this->assertSame(2, $resumen['certificados'], 'El rehecho también cuenta como emitido.');
    }

    // ── Andamiaje ──────────────────────────────────────────────────────────

    private function emitir(string $curp, string $plan, string $tipo = ConsumoEmision::CERTIFICADO): bool
    {
        return $this->creditos->registrar('escuela-x', $tipo, $curp, $plan, 'FOLIO-1');
    }

    private function conSaldo(string $modalidad, int $creditos): void
    {
        SaldoEmision::updateOrCreate(
            ['tenant_id' => 'escuela-x'],
            ['modalidad' => $modalidad, 'creditos' => $creditos],
        );
    }

    private function saldo(): SaldoEmision
    {
        return SaldoEmision::where('tenant_id', 'escuela-x')->firstOrFail();
    }
}
