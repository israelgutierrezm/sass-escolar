<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Emision\Cargo;
use App\Models\Emision\Certificacion;
use App\Models\Emision\CertificadoResponsable;
use App\Models\Emision\LoteCertificacion;
use App\Models\Emision\LoteTitulacion;
use App\Models\Emision\Responsable;
use App\Models\Emision\Titulacion;
use App\Models\Tenant;
use App\Services\Emision\FirmadorLoteTitulo;
use App\Services\FirmadorLote;
use Illuminate\Support\Facades\Storage;
use PhpCfdi\Credentials\Certificate;
use PhpCfdi\Credentials\Credential;
use Stancl\Tenancy\Contracts\Tenant as ContratoTenant;
use Tests\Concerns\CreaEscuelaDePrueba;
use Tests\TenantTestCase;

/**
 * Lo que la SEP recibe tiene que poder verificarse allá, no sólo aquí.
 *
 * ── Por qué existe esta prueba ─────────────────────────────────────────────
 * El certificado y el sello son los dos únicos campos del título que este
 * sistema NO puede comprobar solo: los genera, los mete en el XML y se los
 * manda a alguien más. Si van mal, aquí no pasa nada —el archivo se escribe, el
 * lote se marca como firmado, el revisor ve todo en verde— y el error aparece
 * semanas después, en forma de un rechazo del web service que no dice por qué.
 *
 * Así que se comprueban desde afuera: se toma lo que iría en el XML y se hace
 * exactamente lo que hace quien lo recibe —decodificar el certificado y validar
 * el sello contra él—, sin usar el objeto que lo firmó.
 *
 * No hace falta una e.firma real: lo que se prueba es el FORMATO y la relación
 * entre sello, cadena y certificado, y eso es idéntico con cualquier par
 * .cer/.key. Lo que la e.firma real agrega es que el certificado esté emitido
 * por el SAT, que es cosa de la SEP verificar, no de este código.
 */
class SelloDelTituloTest extends TenantTestCase
{
    use CreaEscuelaDePrueba;

    private Credential $credencial;

    /** La .key de la credencial, que `firmar()` recibe aparte del .cer. */
    private string $llavePem;

    protected function setUp(): void
    {
        parent::setUp();

        $this->credencial = $this->credencialDePrueba();
    }

    /**
     * El certificado va como base64 del DER, no del PEM.
     *
     * Es el error que motivó la prueba: `base64_encode($pem)` mete una capa de
     * más. Al decodificarlo aparece el texto «-----BEGIN CERTIFICATE-----» en
     * lugar de los bytes del certificado, y quien espera un .cer no lo puede
     * abrir. Aquí nunca falla, porque aquí nadie lo abre.
     */
    public function test_el_certificado_del_xml_es_el_der_en_base64(): void
    {
        $comoVaAlXml = $this->credencial->certificate()->pemAsOneLine();

        $bytes = base64_decode($comoVaAlXml, true);

        $this->assertNotFalse($bytes, 'Tiene que ser base64 estricto.');
        $this->assertStringNotContainsString(
            'BEGIN CERTIFICATE',
            $bytes,
            'Al decodificar deben salir los bytes del .cer, no el texto del PEM.',
        );

        /*
         * Y lo decodificado tiene que ser un certificado de verdad. Se abre con
         * `Certificate`, que es lo mismo que hace `LectorCertificado` con un
         * .cer subido: `openssl_x509_read` no sirve para comprobarlo porque
         * sólo acepta PEM, y aceptaría justo lo que este caso debe rechazar.
         */
        $this->assertSame(
            $this->credencial->certificate()->pem(),
            (new Certificate($bytes))->pem(),
            'El DER no se pudo abrir como el mismo certificado.',
        );
    }

    /** Lo que va al XML es el MISMO certificado con el que se firma. */
    public function test_el_certificado_del_xml_corresponde_a_la_llave(): void
    {
        $delXml = new Certificate(base64_decode($this->credencial->certificate()->pemAsOneLine(), true));

        // Por el hexadecimal y no por `bytes()`: el serial de un certificado
        // generado al vuelo no siempre es ASCII imprimible como el del SAT.
        $this->assertSame(
            $this->credencial->certificate()->serialNumber()->hexadecimal(),
            $delXml->serialNumber()->hexadecimal(),
        );
    }

    /**
     * El sello verifica contra el certificado, sobre la cadena original.
     *
     * Se verifica con el certificado RECONSTRUIDO desde lo que viaja en el XML,
     * no con el objeto que firmó: es la única manera de comprobar el camino
     * completo tal como lo recorre la SEP.
     */
    public function test_el_sello_verifica_con_lo_que_viaja_en_el_xml(): void
    {
        $cadena = '||1.0|20121023SICERT0151610X0042086X00013|090002|AOJM910903MMCLMR07||';

        $sello = base64_encode($this->credencial->sign($cadena));
        $certificado = new Certificate(base64_decode($this->credencial->certificate()->pemAsOneLine(), true));

        $this->assertTrue(
            $certificado->publicKey()->verify($cadena, base64_decode($sello, true), OPENSSL_ALGO_SHA256),
            'El sello no verifica contra el certificado que va en el XML.',
        );
    }

    /**
     * Y si la cadena cambia una coma, el sello deja de valer.
     *
     * Sin esto, la prueba de arriba pasaría aunque la verificación fuera
     * complaciente: hay que ver el `false`.
     */
    public function test_una_cadena_alterada_invalida_el_sello(): void
    {
        $cadena = '||1.0|FOLIO-1|090002|AOJM910903MMCLMR07||';

        $sello = $this->credencial->sign($cadena);
        $certificado = $this->credencial->certificate();

        $this->assertFalse(
            $certificado->publicKey()->verify($cadena.' ', $sello, OPENSSL_ALGO_SHA256),
            'Un sello no puede valer para una cadena distinta.',
        );
    }

    /** El sello se hace con SHA256, que es lo que espera el SAT y la SEP. */
    public function test_el_sello_es_sha256(): void
    {
        $cadena = '||1.0|FOLIO-1||';

        $sello = $this->credencial->sign($cadena);
        $certificado = $this->credencial->certificate();

        $this->assertTrue($certificado->publicKey()->verify($cadena, $sello, OPENSSL_ALGO_SHA256));
        $this->assertFalse(
            $certificado->publicKey()->verify($cadena, $sello, OPENSSL_ALGO_SHA1),
            'Si verificara también con SHA1, no se estaría firmando con SHA256.',
        );
    }

    /**
     * Y lo mismo, pero pasando por el firmador de verdad.
     *
     * Las pruebas de arriba fijan el contrato; ésta fija la IMPLEMENTACIÓN. Sin
     * ella, alguien vuelve a escribir `base64_encode($pem)` en el firmador y
     * todo sigue en verde: el contrato se seguiría cumpliendo en el papel y el
     * XML se seguiría enviando mal.
     */
    public function test_el_xml_que_escribe_el_firmador_lleva_el_der(): void
    {
        Storage::fake('local');
        $this->conContextoDeEscuela();

        $escuela = $this->alumnoInscrito();
        $lote = LoteTitulacion::create([
            'folio' => 'LOTE-SELLO-1',
            'etapa' => 'pruebas',
            'estado' => 'en_espera_firma',
        ]);
        Titulacion::create([
            'lote_id' => $lote->id,
            'matricula_oferta_id' => $escuela['matricula'],
            'estado' => Titulacion::PENDIENTE,
        ]);

        app(FirmadorLoteTitulo::class)->firmar($lote, [$this->firmanteDePrueba()]);

        $titulacion = Titulacion::where('lote_id', $lote->id)->firstOrFail();
        $this->assertNotNull($titulacion->xml_path, 'El firmador no escribió el XML: '.$titulacion->error_mensaje);

        $xml = Storage::disk('local')->get($titulacion->xml_path);
        $certificado = $this->atributo($xml, 'certificadoResponsable');

        $bytes = base64_decode($certificado, true);

        $this->assertNotFalse($bytes);
        $this->assertStringNotContainsString(
            'BEGIN CERTIFICATE',
            $bytes,
            'El XML lleva el PEM codificado, no el .cer.',
        );
        $this->assertSame(
            $this->credencial->certificate()->pem(),
            (new Certificate($bytes))->pem(),
        );

        // Y el sello del XML verifica con ese mismo certificado, sobre la
        // cadena que se guardó: el camino completo, como lo hará la SEP.
        $this->assertTrue(
            (new Certificate($bytes))->publicKey()->verify(
                (string) $titulacion->cadena_original,
                base64_decode($this->atributo($xml, 'sello'), true),
                OPENSSL_ALGO_SHA256,
            ),
            'El sello del XML no verifica contra el certificado del XML.',
        );
    }

    /**
     * Certificación tenía el mismo defecto, y necesita su propio guardián.
     *
     * Son dos firmadores distintos y nada obliga a que cambien juntos: se
     * arreglaron a la vez porque se encontraron a la vez, no porque compartan
     * código.
     */
    public function test_el_xml_del_certificado_tambien_lleva_el_der(): void
    {
        Storage::fake('local');
        $this->conContextoDeEscuela();

        $escuela = $this->alumnoInscrito();
        $lote = LoteCertificacion::create([
            'folio' => 'LOTE-CERT-1',
            'tipo' => 'total',
            'estado' => 'en_espera_firma',
        ]);
        Certificacion::create([
            'lote_id' => $lote->id,
            'matricula_oferta_id' => $escuela['matricula'],
            'estado' => Certificacion::PENDIENTE,
        ]);

        $firmante = $this->firmanteDePrueba();

        app(FirmadorLote::class)->firmar(
            $lote,
            $firmante['responsable'],
            $firmante['certificado'],
            $firmante['cert_pem'],
            $firmante['key'],
            $firmante['password'],
        );

        $certificacion = Certificacion::where('lote_id', $lote->id)->firstOrFail();
        $this->assertNotNull($certificacion->xml_path, 'No se escribió el XML: '.$certificacion->error_mensaje);

        $xml = Storage::disk('local')->get($certificacion->xml_path);
        $bytes = base64_decode($this->atributo($xml, 'certificadoResponsable', 'https://www.siged.sep.gob.mx/certificados/', 'Dec'), true);

        $this->assertNotFalse($bytes);
        $this->assertStringNotContainsString('BEGIN CERTIFICATE', $bytes, 'El XML lleva el PEM codificado, no el .cer.');
        $this->assertSame($this->credencial->certificate()->pem(), (new Certificate($bytes))->pem());
    }

    // ── Andamiaje ──────────────────────────────────────────────────────────

    /**
     * Deja resuelto `tenant()` sin inicializar tenancy de verdad.
     *
     * El firmador pregunta de qué escuela es el consumo de créditos. Inicializar
     * tenancy cambiaría la conexión a la base de un tenant real y dejaría las
     * pruebas sin su esquema; aquí sólo hace falta que haya una escuela con
     * nombre.
     */
    private function conContextoDeEscuela(): void
    {
        $this->app->instance(ContratoTenant::class, new class extends Tenant
        {
            public function getTenantKey(): string
            {
                return 'pruebas';
            }
        });
    }

    /** Lee un atributo del nodo que lleva la firma, en cualquiera de los dos XML. */
    private function atributo(
        string $xml,
        string $nombre,
        string $ns = 'https://www.siged.sep.gob.mx/titulos/',
        string $etiqueta = 'FirmaResponsable',
    ): string {
        $dom = new \DOMDocument;
        $dom->loadXML($xml);

        $nodo = $dom->getElementsByTagNameNS($ns, $etiqueta)->item(0);

        $this->assertNotNull($nodo, "El XML no trae {$etiqueta}.");

        return $nodo->getAttribute($nombre);
    }

    /** Un firmante con la credencial de prueba y un responsable mínimo. */
    private function firmanteDePrueba(): array
    {
        $cargo = Cargo::create(['clave' => 'DIR', 'nombre' => 'DIRECTOR', 'identificador' => 1, 'activo' => true]);

        $responsable = Responsable::create([
            'tipo_responsable_id' => $this->deCatalogo('tipos_responsable'),
            'nombre' => 'RESPONSABLE',
            'apellido_paterno' => 'DE',
            'apellido_materno' => 'PRUEBA',
            'curp' => 'AEMM830225HDFLCG04',
            'cargo_id' => $cargo->id,
            'activo' => true,
        ]);

        $certificado = CertificadoResponsable::create([
            'responsable_id' => $responsable->id,
            'serie' => '00001000000409837457',
            'vigencia_inicio' => now()->subDay(),
            'vigencia_fin' => now()->addYear(),
        ]);

        return [
            'responsable' => $responsable,
            'certificado' => $certificado,
            'cert_pem' => $this->credencial->certificate()->pem(),
            'key' => $this->llavePem,
            'password' => 'secreto',
        ];
    }

    /**
     * Un par .cer/.key generado al vuelo.
     *
     * No es una e.firma del SAT y no pretende serlo: para el formato del sello
     * y del certificado da exactamente lo mismo, y así la prueba no depende de
     * un archivo secreto en el repositorio.
     */
    private function credencialDePrueba(): Credential
    {
        // El `config` va explícito porque el PHP de WAMP no trae openssl.cnf y
        // sin él estas funciones devuelven false sin decir por qué.
        $cnf = ['config' => base_path('tests/fixtures/openssl.cnf')];

        $llave = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            ...$cnf,
        ]);

        $this->assertNotFalse($llave, 'No se pudo generar la llave de prueba.');

        $csr = openssl_csr_new(['commonName' => 'RESPONSABLE DE PRUEBA'], $llave, ['digest_alg' => 'sha256', ...$cnf]);
        $x509 = openssl_csr_sign($csr, null, $llave, 1, ['digest_alg' => 'sha256', ...$cnf]);

        openssl_x509_export($x509, $certPem);
        // Con contraseña, como las llaves reales: `Credential` espera una .key
        // protegida y así se ejercita el mismo camino que en producción.
        openssl_pkey_export($llave, $keyPem, 'secreto', $cnf);

        $this->llavePem = $keyPem;

        return Credential::create($certPem, $keyPem, 'secreto');
    }
}
