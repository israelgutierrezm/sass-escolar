<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Academico\NivelEstudio;
use App\Models\Academico\TipoPeriodo;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Emision\TipoCertificacion;
use App\Models\Landlord\EntidadFederativa;
use App\Services\ConstructorCertificadoXml;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreaEscuelaDePrueba;
use Tests\TenantTestCase;

/**
 * De qué columna sale cada número que viaja al DEC.
 *
 * ── Por qué esto merece su propia prueba ───────────────────────────────────
 * Porque un certificado con el número equivocado NO falla en ningún sitio
 * nuestro: lo rechaza el web service de la SEP, o peor, lo acepta y timbra un
 * documento oficial que dice otra cosa. No hay pantalla donde se note.
 *
 * El valor oficial vive en la CLAVE de cada catálogo académico. Que el id
 * coincida es casualidad de cómo se sembró —en `tipos_certificacion` NO
 * coincide: id 1 y 2 contra clave 79 y 80—, y la entidad federativa es al
 * revés: su clave es la abreviatura de dos letras y el DEC espera el número,
 * que está en el identificador. Por eso la columna se elige catálogo por
 * catálogo, y por eso se fija aquí.
 *
 * ── Se lee el SNAPSHOT, no el ayudante privado ─────────────────────────────
 * La primera versión de esta prueba llamaba al método interno pasándole la
 * columna a mano, y así pasaba igual con el constructor leyendo la columna
 * equivocada: comprobaba el ayudante, no la decisión. Comprobado mutando las
 * dos columnas. Ahora se pide el snapshot —lo que de verdad se firma y se
 * manda— y se mira lo que trae.
 */
class ClavesSepDelCertificadoTest extends TenantTestCase
{
    use CreaEscuelaDePrueba;

    /** El nivel y el tipo de periodo salen de la CLAVE del catálogo. */
    public function test_el_nivel_y_el_periodo_salen_de_la_clave(): void
    {
        $datos = $this->snapshotConCatalogosDistinguibles();

        $this->assertSame('777', $datos['idNivelEstudios']);
        $this->assertSame('778', $datos['idTipoPeriodo']);
    }

    /**
     * La ENTIDAD FEDERATIVA es la excepción: va por identificador.
     *
     * Su clave es «AS», «BC»… —la abreviatura de RENAPO— y el DEC espera «01»,
     * «02». Si alguien «unifica» esto para que todo lea la clave, el timbrado de
     * todas las escuelas empieza a mandar letras donde va un número.
     */
    public function test_la_entidad_federativa_va_por_identificador(): void
    {
        $datos = $this->snapshotConCatalogosDistinguibles();

        $this->assertSame('99', $datos['idEntidadFederativa']);
        $this->assertSame('99', $datos['idLugarExpedicion']);
    }

    /**
     * El tipo de certificación sale del catálogo, no del id.
     *
     * Es el caso que obliga a que esta prueba exista: ahí el id es 1 y 2, y
     * mandar el id sería mandar «1» donde la SEP espera «79».
     */
    public function test_el_tipo_de_certificacion_va_por_clave(): void
    {
        $fila = DB::table('tipos_certificacion')->where('clave', TipoCertificacion::TOTAL)->first();

        if ($fila !== null) {
            $this->assertNotSame(
                (string) $fila->id,
                (string) $fila->clave,
                'En este catálogo el id NO debe coincidir con la clave; si coincide, se sembró distinto.',
            );
        }

        $datos = $this->snapshotConCatalogosDistinguibles();

        $this->assertSame(TipoCertificacion::TOTAL, $datos['idTipoCertificacion']);
        $this->assertSame(TipoCertificacion::PARCIAL, TipoCertificacion::claveOficial(true));
    }

    /**
     * Sin la fila del catálogo se manda el valor oficial de siempre.
     *
     * Un certificado sin `idTipoCertificacion` lo rechaza el web service
     * entero, y eso es peor que mandar el 79 que la SEP lleva años esperando.
     */
    public function test_sin_la_fila_se_manda_el_valor_oficial(): void
    {
        DB::table('tipos_certificacion')->delete();

        $this->assertSame(TipoCertificacion::TOTAL, TipoCertificacion::claveOficial(false));
    }

    // ── Andamiaje ──────────────────────────────────────────────────────────

    /**
     * El snapshot de una matrícula cuyos catálogos tienen clave e identificador
     * DISTINTOS.
     *
     * Es lo único que hace la prueba capaz de distinguir de qué columna salió
     * cada número.
     *
     * Para el nivel y el periodo se CREAN filas con una clave que no existe:
     * así su id es un autoincremento cualquiera y la clave es otra cosa, que es
     * exactamente el caso real —una escuela que da de alta su propio nivel y
     * recibe un id sin relación con ningún número de la SEP—. Usar las filas
     * sembradas no serviría: ahí el id ya vale lo mismo que la clave.
     *
     * @return array<string, mixed>
     */
    private function snapshotConCatalogosDistinguibles(): array
    {
        $escuela = $this->alumnoInscrito();

        $nivel = NivelEstudio::query()->create([
            'clave' => '777',
            'nombre' => 'Nivel con clave propia',
            'orden' => 90,
        ]);

        $periodo = TipoPeriodo::query()->create([
            'clave' => '778',
            'nombre' => 'Periodo con clave propia',
        ]);

        $this->assertNotSame('777', (string) $nivel->id, 'Si el id valiera lo mismo que la clave, esto no distinguiría nada.');

        $entidad = EntidadFederativa::query()->create([
            'pais_id' => DB::connection('central')->table('paises')->value('id')
                ?? DB::connection('central')->table('paises')->insertGetId([
                    'clave_iso' => 'ZZ', 'nombre' => 'País de prueba',
                ]),
            // Clave alfabética contra identificador numérico: exactamente como
            // vienen las entidades de verdad.
            'clave' => 'ZZ',
            'identificador' => '99',
            'nombre' => 'Entidad de prueba',
        ]);

        DB::table('programas_academicos')->where('id', $escuela['programa_academico'])->update(['nivel_estudios_id' => $nivel->id]);
        DB::table('planes_estudio')->where('id', $escuela['plan'])->update(['tipo_periodo_id' => $periodo->id]);
        DB::table('campus')->where('id', $escuela['campus'])->update(['entidad_id' => $entidad->id]);

        return app(ConstructorCertificadoXml::class)
            ->snapshot(MatriculaOferta::findOrFail($escuela['matricula']));
    }
}
