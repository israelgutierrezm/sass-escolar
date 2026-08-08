<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Sso\EstadoDeGoogle;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * El sobre que lleva la escuela de ida y vuelta hasta Google.
 *
 * Google devuelve siempre al dominio central, así que el retorno llega a un
 * sitio que no sabe de dónde venía. El sobre lo dice, y por eso es exactamente
 * lo que hay que proteger: con él se decide A DÓNDE se manda un código de
 * autorización válido. Un sobre que se pueda falsificar es un código entregado
 * a quien ponga el destino.
 */
class SsoCallbackCentralTest extends TestCase
{
    private EstadoDeGoogle $sobres;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sobres = new EstadoDeGoogle;
    }

    /** Lo que se guarda es lo que se saca. */
    public function test_el_sobre_conserva_la_escuela(): void
    {
        $sobre = $this->sobres->crear('demo.acadion.mx');

        $this->assertSame('demo.acadion.mx', $this->sobres->abrir($sobre));
    }

    /**
     * Un sobre manipulado no se abre.
     *
     * Es la defensa principal: sin ella, se escribe «vengo de la escuela X» a
     * mano —o peor, un dominio de fuera— y el centro manda ahí el código.
     */
    public function test_un_sobre_manipulado_no_vale(): void
    {
        $sobre = $this->sobres->crear('demo.acadion.mx');

        // Cambiar el contenido conservando la firma.
        [$cuerpo, $firma] = explode('.', $sobre, 2);
        $otro = rtrim(strtr(base64_encode(json_encode([
            'd' => 'servidor-del-atacante.com',
            'exp' => now()->addMinute()->timestamp,
            'n' => 'xxxx',
        ])), '+/', '-_'), '=');

        $this->assertNull($this->sobres->abrir($otro.'.'.$firma));
    }

    /** Y uno inventado de cero tampoco. */
    public function test_un_sobre_inventado_no_vale(): void
    {
        $this->assertNull($this->sobres->abrir('cualquier.cosa'));
        $this->assertNull($this->sobres->abrir('sinpunto'));
        $this->assertNull($this->sobres->abrir(null));
        $this->assertNull($this->sobres->abrir(''));
    }

    /**
     * El sobre caduca.
     *
     * Sin fecha serviría para siempre: quien capturara una URL de retorno podría
     * reusarla meses después.
     */
    public function test_el_sobre_caduca(): void
    {
        $sobre = $this->sobres->crear('demo.acadion.mx');

        Carbon::setTestNow(now()->addMinutes(4));
        $this->assertSame('demo.acadion.mx', $this->sobres->abrir($sobre), 'A los 4 minutos todavía vale.');

        Carbon::setTestNow(now()->addMinutes(10));
        $this->assertNull($this->sobres->abrir($sobre), 'Pasados los 5, ya no.');

        Carbon::setTestNow();
    }

    /**
     * Dos intentos seguidos dan sobres distintos.
     *
     * Sin eso, el sobre de una sesión sería idéntico al de otra y uno podría
     * pasar por el otro.
     */
    public function test_cada_intento_tiene_su_propio_sobre(): void
    {
        $this->assertNotSame(
            $this->sobres->crear('demo.acadion.mx'),
            $this->sobres->crear('demo.acadion.mx'),
        );
    }

    /**
     * El sobre de una escuela no sirve en otra.
     *
     * Lo comprueba la escuela al recibir el reparto: un sobre legítimo de otra
     * escuela es legítimo y aun así no debe abrir sesión aquí.
     */
    public function test_el_sobre_dice_de_que_escuela_es(): void
    {
        $deOtra = $this->sobres->crear('otra.acadion.mx');

        $this->assertNotSame('demo.acadion.mx', $this->sobres->abrir($deOtra));
    }
}
