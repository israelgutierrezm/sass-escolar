<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Curp;
use PHPUnit\Framework\TestCase;

/**
 * El dígito verificador, ahora que se expone para fabricar CURP de demo.
 *
 * El seeder armaba `…DF` + tres consonantes + `'09'` fijo: homoclave y dígito
 * inventados, así que NINGUNA CURP de la base demo pasaba `Curp::leer()`. Como
 * de la CURP se deducen fecha, sexo y entidad de nacimiento, toda prueba contra
 * demo ejercía la rama degradada —la de «no se pudo leer»— y nunca la normal.
 */
class CurpDemoTest extends TestCase
{
    /**
     * El dígito calculado es EL que la validación exige, y sólo ese.
     *
     * Se comprueba contra `esValida()`, que ya vivía aquí y no se tocó: si
     * `digitoVerificador` se hubiera copiado mal, el único dígito que pasa no
     * sería el que devuelve.
     */
    public function test_solo_el_digito_calculado_pasa_la_validacion(): void
    {
        $primeros17 = 'GARC900315HDFRRR0';
        $calculado = Curp::digitoVerificador($primeros17);

        $aceptados = array_values(array_filter(
            range(0, 9),
            fn (int $d) => Curp::esValida($primeros17.$d),
        ));

        $this->assertSame([(int) $calculado], $aceptados);
    }

    public function test_lo_que_se_arma_con_el_digito_calculado_es_valido(): void
    {
        $primeros17 = 'VAPM080221MDFZNLA';

        $curp = $primeros17.Curp::digitoVerificador($primeros17);

        $this->assertTrue(Curp::esValida($curp), "No validó: {$curp}");
    }

    /**
     * La posición 17 es lo que decide el siglo al leer. Con el `0` fijo que
     * tenía el seeder, un alumno nacido en 2008 se registraba como de 1908.
     */
    public function test_la_homoclave_letra_coloca_el_nacimiento_en_este_siglo(): void
    {
        $conLetra = 'VAPM080221MDFZNLA';
        $conDigito = 'VAPM080221MDFZNL0';

        $este = Curp::leer($conLetra.Curp::digitoVerificador($conLetra));
        $pasado = Curp::leer($conDigito.Curp::digitoVerificador($conDigito));

        $this->assertSame('2008', $este?->fechaNacimiento?->format('Y'));
        $this->assertSame('1908', $pasado?->fechaNacimiento?->format('Y'));
    }

    public function test_no_calcula_nada_sobre_una_longitud_que_no_es_la_suya(): void
    {
        $this->assertNull(Curp::digitoVerificador('VAPM080221MDFZNL'));
        $this->assertNull(Curp::digitoVerificador('VAPM080221MDFZNLA9'));
    }
}
