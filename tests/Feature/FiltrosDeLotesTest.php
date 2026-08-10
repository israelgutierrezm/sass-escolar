<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Emision\LoteCertificacionController;
use App\Http\Controllers\Emision\LoteTitulacionController;
use App\Models\Emision\LoteCertificacion;
use App\Models\Emision\LoteTitulacion;
use App\Models\Emision\Titulacion;
use App\Models\Landlord\SaldoEmision;
use App\Models\Tenant;
use Stancl\Tenancy\Contracts\Tenant as ContratoTenant;
use Tests\Concerns\CreaEscuelaDePrueba;
use Tests\TenantTestCase;

/**
 * Los filtros de los listados de lotes, y el saldo que los acompaña.
 *
 * ── Qué se prueba de un filtro ─────────────────────────────────────────────
 * No que devuelva algo, sino que DEJE FUERA lo que no corresponde. Un filtro
 * que se ignora en silencio se ve exactamente igual que uno que funciona
 * cuando el listado es corto, que es como se prueba a mano.
 */
class FiltrosDeLotesTest extends TenantTestCase
{
    use CreaEscuelaDePrueba;

    protected function setUp(): void
    {
        parent::setUp();

        // Los lotes preguntan de qué escuela es el saldo.
        $this->app->instance(ContratoTenant::class, new class extends Tenant
        {
            public function getTenantKey(): string
            {
                return 'pruebas';
            }
        });
    }

    // ── Certificación ──────────────────────────────────────────────────────

    public function test_filtra_lotes_de_certificacion_por_estado(): void
    {
        $this->loteCert('LOTE-A', 'borrador');
        $this->loteCert('LOTE-B', 'firmado');

        $folios = $this->foliosCert(['estado' => 'firmado']);

        $this->assertSame(['LOTE-B'], $folios);
    }

    public function test_filtra_lotes_de_certificacion_por_tipo(): void
    {
        $this->loteCert('LOTE-TOTAL', 'borrador', tipo: 'total');
        $this->loteCert('LOTE-PARCIAL', 'borrador', tipo: 'parcial');

        $this->assertSame(['LOTE-PARCIAL'], $this->foliosCert(['tipo' => 'parcial']));
    }

    /** La búsqueda mira folio y nombre: son las dos formas de recordar un lote. */
    public function test_la_busqueda_encuentra_por_folio_y_por_nombre(): void
    {
        $this->loteCert('LOTE-CERT-0007', 'borrador');
        $this->loteCert('LOTE-CERT-0008', 'borrador', nombre: 'Egresados de enero');

        $this->assertSame(['LOTE-CERT-0007'], $this->foliosCert(['busqueda' => '0007']));
        $this->assertSame(['LOTE-CERT-0008'], $this->foliosCert(['busqueda' => 'enero']));
    }

    /** Sin filtros salen todos: el listado no debe esconder nada por omisión. */
    public function test_sin_filtros_salen_todos(): void
    {
        $this->loteCert('LOTE-A', 'borrador');
        $this->loteCert('LOTE-B', 'firmado');

        $this->assertCount(2, $this->foliosCert([]));
    }

    /** El listado dice con qué se paga: es lo que decide si se puede firmar. */
    public function test_el_listado_trae_el_saldo(): void
    {
        SaldoEmision::de('pruebas')->update(['modalidad' => SaldoEmision::PREPAGO, 'creditos' => 7]);

        $props = $this->propsCert([]);

        $this->assertSame('prepago', $props['saldo']['modalidad']);
        $this->assertSame(7, $props['saldo']['creditos']);
        $this->assertTrue($props['saldo']['cuenta_creditos']);
    }

    /**
     * En ilimitado no se enseña contador.
     *
     * Un «0 créditos» junto a una escuela que no paga por documento es una
     * alarma falsa, y la primera que la vea va a pedir que le carguen saldo.
     */
    public function test_en_ilimitado_no_se_cuentan_creditos(): void
    {
        SaldoEmision::de('pruebas')->update(['modalidad' => SaldoEmision::ILIMITADO, 'creditos' => 0]);

        $props = $this->propsCert([]);

        $this->assertFalse($props['saldo']['cuenta_creditos']);
        $this->assertSame('Incluido', $props['saldo']['etiqueta']);
    }

    // ── Titulación ─────────────────────────────────────────────────────────

    public function test_filtra_lotes_de_titulacion_por_etapa(): void
    {
        $this->loteTit('LOTE-PRU', 'borrador', etapa: 'pruebas');
        $this->loteTit('LOTE-PROD', 'borrador', etapa: 'produccion');

        $this->assertSame(['LOTE-PROD'], $this->foliosTit(['etapa' => 'produccion']));
    }

    /**
     * «Con rechazos de la SEP» es el filtro que se usa de verdad.
     *
     * Después de enviar un lote grande lo que se busca no es un folio, sino
     * dónde quedó trabajo por rehacer.
     */
    public function test_filtra_los_lotes_con_rechazos_del_web_service(): void
    {
        $escuela = $this->alumnoInscrito();

        $limpio = $this->loteTit('LOTE-LIMPIO', 'enviado');
        Titulacion::create([
            'lote_id' => $limpio->id,
            'matricula_oferta_id' => $escuela['matricula'],
            'estado' => Titulacion::TITULADO,
            'estado_ws' => 'aceptado',
        ]);

        $conRechazo = $this->loteTit('LOTE-RECHAZADO', 'enviado');
        Titulacion::create([
            'lote_id' => $conRechazo->id,
            'matricula_oferta_id' => $escuela['matricula'],
            'estado' => Titulacion::TITULADO,
            'estado_ws' => 'rechazado',
        ]);

        $this->assertSame(['LOTE-RECHAZADO'], $this->foliosTit(['rechazados' => '1']));
        // Y sin el filtro siguen los dos: no se cuela un `where` permanente.
        $this->assertCount(2, $this->foliosTit([]));
    }

    /** Cada lote dice cuántos le rechazaron, para no abrirlos uno por uno. */
    public function test_cada_lote_cuenta_sus_rechazados(): void
    {
        $lote = $this->loteTit('LOTE-MIXTO', 'enviado');

        // Un alumno distinto por renglón: un lote no admite la misma matrícula
        // dos veces, y con razón.
        foreach (['aceptado', 'rechazado', 'rechazado'] as $estadoWs) {
            Titulacion::create([
                'lote_id' => $lote->id,
                'matricula_oferta_id' => $this->alumnoInscrito()['matricula'],
                'estado' => Titulacion::TITULADO,
                'estado_ws' => $estadoWs,
            ]);
        }

        $fila = collect($this->propsTit([])['lotes'])->firstWhere('folio', 'LOTE-MIXTO');

        $this->assertSame(2, $fila['rechazados_ws']);
        $this->assertSame(3, $fila['total']);
    }

    // ── Andamiaje ──────────────────────────────────────────────────────────

    private function loteCert(string $folio, string $estado, string $tipo = 'total', ?string $nombre = null): LoteCertificacion
    {
        return LoteCertificacion::create([
            'folio' => $folio,
            'nombre' => $nombre,
            'tipo' => $tipo,
            'estado' => $estado,
        ]);
    }

    private function loteTit(string $folio, string $estado, string $etapa = 'pruebas'): LoteTitulacion
    {
        return LoteTitulacion::create([
            'folio' => $folio,
            'etapa' => $etapa,
            'estado' => $estado,
        ]);
    }

    /** @return array<string, mixed> */
    private function propsCert(array $filtros): array
    {
        $usuario = $this->usuarioConAlcance();
        $peticion = $this->peticionDe($usuario, '/certificacion/lotes', $filtros);

        return $this->propsDe(app(LoteCertificacionController::class)->index($peticion), $peticion);
    }

    /** @return array<string, mixed> */
    private function propsTit(array $filtros): array
    {
        $usuario = $this->usuarioConAlcance();
        $peticion = $this->peticionDe($usuario, '/titulacion/lotes', $filtros);

        return $this->propsDe(app(LoteTitulacionController::class)->index($peticion), $peticion);
    }

    /** @return array<int, string> */
    private function foliosCert(array $filtros): array
    {
        return collect($this->propsCert($filtros)['lotes'])->pluck('folio')->all();
    }

    /** @return array<int, string> */
    private function foliosTit(array $filtros): array
    {
        return collect($this->propsTit($filtros)['lotes'])->pluck('folio')->all();
    }
}
