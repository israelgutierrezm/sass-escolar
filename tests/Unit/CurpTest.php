<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Curp;
use PHPUnit\Framework\TestCase;

/**
 * La CURP, que en este sistema es dato verificado y no texto tecleado.
 *
 * De aquí salen la fecha de nacimiento y el sexo de media escuela: si la
 * validación deja pasar una CURP mal escrita, ese error se propaga al
 * expediente, al certificado y al título. Por eso se comprueba el dígito
 * verificador y no sólo la forma.
 *
 * Las CURP de las pruebas son ficticias y su dígito se calculó aparte, con el
 * algoritmo publicado por RENAPO; así el valor esperado no sale del mismo
 * código que se está probando.
 */
class CurpTest extends TestCase
{
    /** La CURP de ejemplo de la documentación oficial. */
    private const VALIDA = 'HEGG560427MVZRRL04';

    public function test_lee_una_curp_valida(): void
    {
        $curp = Curp::leer(self::VALIDA);

        $this->assertNotNull($curp);
        $this->assertSame('1956-04-27', $curp->fechaNacimiento->format('Y-m-d'));
        $this->assertSame('M', $curp->claveSexo);
        $this->assertSame('VZ', $curp->claveEntidad);
    }

    public function test_normaliza_minusculas_y_espacios(): void
    {
        $this->assertNotNull(Curp::leer('  '.strtolower(self::VALIDA).' '));
    }

    /**
     * El dígito verificador es lo único que distingue una CURP de un texto con
     * la forma de una CURP. Sin comprobarlo, un dedazo entra como dato bueno.
     */
    public function test_rechaza_una_curp_con_el_digito_cambiado(): void
    {
        $conDedazo = substr(self::VALIDA, 0, 17).'5';

        $this->assertNull(Curp::leer($conDedazo));
        $this->assertFalse(Curp::esValida($conDedazo));
    }

    public function test_rechaza_lo_que_ni_siquiera_tiene_forma_de_curp(): void
    {
        foreach ([null, '', 'HOLA', '1234567890', 'HEGG560427MVZRRL0'] as $basura) {
            $this->assertNull(Curp::leer($basura), "Debería rechazar: ".var_export($basura, true));
        }
    }

    /**
     * El siglo no está escrito en la CURP: lo delata la homoclave. Dígito para
     * quien nació antes del 2000, letra para quien nació después. Sin esta
     * regla, un alumno de 2005 quedaría registrado como nacido en 1905.
     */
    public function test_deduce_el_siglo_por_la_homoclave(): void
    {
        $delSigloPasado = Curp::leer(self::VALIDA);
        $deEsteSiglo = Curp::leer('MAAJ050612HDFRRNA2');

        $this->assertSame('1956', $delSigloPasado->fechaNacimiento->format('Y'));
        $this->assertNotNull($deEsteSiglo);
        $this->assertSame('2005-06-12', $deEsteSiglo->fechaNacimiento->format('Y-m-d'));
        $this->assertSame('H', $deEsteSiglo->claveSexo);
    }

    /**
     * Una fecha que no existe pasa el patrón y el dígito, y aun así no es una
     * fecha: el 29 de febrero de 2001 no ocurrió.
     */
    public function test_rechaza_una_fecha_que_no_existe(): void
    {
        $this->assertNotNull(Curp::leer('LOPA000229MDFPRRA5'), '2000 sí fue bisiesto.');
        $this->assertNull(Curp::leer('LOPA010229MDFPRRA2'), '2001 no lo fue.');
    }

    /**
     * «EXTRANJERO» es la marca de «no tengo CURP», no una CURP. Guardarla tal
     * cual permitiría exactamente un extranjero por escuela: la columna es
     * única y el segundo chocaría con un error incomprensible.
     */
    public function test_la_marca_de_extranjero_se_reconoce_y_no_es_una_curp(): void
    {
        $this->assertTrue(Curp::esMarcaDeExtranjero('extranjero'));
        $this->assertTrue(Curp::esMarcaDeExtranjero('  EXTRANJERO '));
        $this->assertFalse(Curp::esMarcaDeExtranjero(self::VALIDA));
        $this->assertNull(Curp::leer('EXTRANJERO'));
    }
}
