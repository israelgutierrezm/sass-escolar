<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\AvisoParaElUsuario;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Qué mensajes de error llegan a la pantalla y cuáles no.
 *
 * Los `abort(403, '...')` traían explicaciones buenas —«Este alumno no está
 * vinculado a tu cuenta»— que la pantalla de error tiraba para mostrar siempre
 * el texto genérico. Ahora se muestran, pero SÓLO los que alguien marcó como
 * destinados a quien los va a leer.
 *
 * Esta prueba existe por lo fácil que es deshacer esa distinción: en el
 * manejador de excepciones, «mostrar el mensaje de cualquier excepción» es una
 * línea MÁS CORTA que la correcta, y se ve como una simplificación inocente. No
 * lo es. Un 403 lo lanzan también los Gates de Laravel y las librerías: sus
 * mensajes van en inglés, nombran clases, describen la mecánica interna y a
 * veces confirman que existe un registro que quien pregunta no debería saber
 * que existe.
 *
 * No toca la base ni levanta la aplicación: es una decisión pura.
 */
class MotivoParaElUsuarioTest extends TestCase
{
    public function test_el_motivo_marcado_si_se_muestra(): void
    {
        $excepcion = new AvisoParaElUsuario(403, 'Este alumno no está vinculado a tu cuenta.');

        $this->assertSame(
            'Este alumno no está vinculado a tu cuenta.',
            AvisoParaElUsuario::motivoDe($excepcion),
        );
    }

    /**
     * Una excepción HTTP cualquiera NO habla, aunque traiga mensaje.
     *
     * Es el caso que sostiene todo: `AccessDeniedHttpException` es lo que
     * lanzan el middleware de permisos y los Gates, y su mensaje —«This action
     * is unauthorized.»— no está escrito para nadie.
     */
    public function test_una_excepcion_http_sin_marcar_no_muestra_su_mensaje(): void
    {
        $this->assertNull(AvisoParaElUsuario::motivoDe(
            new AccessDeniedHttpException('This action is unauthorized.')
        ));

        $this->assertNull(AvisoParaElUsuario::motivoDe(
            new HttpException(403, 'El usuario 42 no tiene el permiso ver-alumnos.')
        ));
    }

    /** Y una excepción de programa, menos: ahí es donde salen las rutas y las clases. */
    public function test_una_excepcion_cualquiera_tampoco(): void
    {
        $this->assertNull(AvisoParaElUsuario::motivoDe(
            new RuntimeException('SQLSTATE[42S02]: Base table or view not found')
        ));
    }

    /** Los tres constructores dejan la excepción lista para mostrarse. */
    public function test_los_ayudantes_producen_una_excepcion_que_habla(): void
    {
        try {
            AvisoParaElUsuario::si(true, 403, 'Motivo por condición.');
            $this->fail('Debió lanzarse.');
        } catch (AvisoParaElUsuario $e) {
            $this->assertSame('Motivo por condición.', AvisoParaElUsuario::motivoDe($e));
            $this->assertSame(403, $e->getStatusCode());
        }

        try {
            AvisoParaElUsuario::aMenosQue(false, 404, 'Motivo por negación.');
            $this->fail('Debió lanzarse.');
        } catch (AvisoParaElUsuario $e) {
            $this->assertSame('Motivo por negación.', AvisoParaElUsuario::motivoDe($e));
        }

        try {
            AvisoParaElUsuario::lanzar(403, 'Motivo directo.');
        } catch (AvisoParaElUsuario $e) {
            $this->assertSame('Motivo directo.', AvisoParaElUsuario::motivoDe($e));
        }
    }

    /** Y no lanzan cuando no toca. */
    public function test_los_ayudantes_no_lanzan_cuando_la_condicion_no_se_cumple(): void
    {
        AvisoParaElUsuario::si(false, 403, 'No debería verse.');
        AvisoParaElUsuario::aMenosQue(true, 403, 'Tampoco.');

        $this->expectNotToPerformAssertions();
    }
}
