<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Plataforma\AvisoController;
use App\Models\Identidad\Persona;
use App\Models\Plataforma\Aviso;
use App\Models\Plataforma\AvisoLectura;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Tests\TenantTestCase;

/**
 * Las reglas del lado de quien publica.
 *
 * Se ejercita el controlador directamente y no por HTTP: las rutas de escuela
 * viven detrás del middleware que resuelve el tenant por dominio, y levantar eso
 * probaría el enrutado, no estas reglas. Lo que aquí importa —qué pasa con las
 * confirmaciones al borrar, qué pasa con los destinos al reeditar— es del
 * controlador, y es donde se rompería.
 */
class AvisoAdminTest extends TenantTestCase
{
    private AvisoController $controlador;

    protected function setUp(): void
    {
        parent::setUp();

        $this->controlador = app(AvisoController::class);

        // `back()` y los mensajes flash necesitan sesión; en una petición real
        // la pone el middleware.
        Session::start();
    }

    /**
     * Las confirmaciones son la constancia de que alguien declaró haber leído
     * esto. Borrarlas junto al aviso destruye justo la prueba para la que
     * existen, así que el aviso se retira y el rastro se conserva.
     */
    public function test_un_aviso_con_confirmaciones_no_se_borra_se_retira(): void
    {
        $aviso = $this->aviso();
        $persona = Persona::create(['nombre' => 'Quien', 'primer_apellido' => 'Confirmó']);

        AvisoLectura::create([
            'aviso_id' => $aviso->id,
            'persona_id' => $persona->id,
            'visto_en' => now(),
            'confirmado_en' => now(),
        ]);

        $this->controlador->eliminar($aviso);

        $this->assertNotNull(Aviso::find($aviso->id), 'El aviso debería seguir ahí.');
        $this->assertFalse($aviso->fresh()->publicado, 'Y retirado, no publicado.');
        $this->assertSame(1, AvisoLectura::where('aviso_id', $aviso->id)->count());
    }

    public function test_sin_confirmaciones_si_se_borra(): void
    {
        $aviso = $this->aviso();
        $persona = Persona::create(['nombre' => 'Sólo', 'primer_apellido' => 'Lovio']);

        // Vio el aviso pero no lo confirmó: no hay constancia que proteger.
        AvisoLectura::create(['aviso_id' => $aviso->id, 'persona_id' => $persona->id, 'visto_en' => now()]);

        $this->controlador->eliminar($aviso);

        $this->assertNull(Aviso::find($aviso->id));
    }

    /** Un aviso que nadie ve no es un aviso: es un texto guardado en una tabla. */
    public function test_no_se_puede_guardar_sin_destinatarios(): void
    {
        $peticion = Request::create('/plataforma/avisos', 'POST', [
            'titulo' => 'Sin destinatarios',
            'cuerpo' => 'Texto.',
            'prioridad' => 'informativo',
            'publicado' => true,
            'destinos' => [],
        ]);

        try {
            $this->controlador->guardar($peticion);
            $this->fail('Debería haber rechazado el aviso sin destinatarios.');
        } catch (ValidationException $e) {
            $this->assertSame('Elige a quién va dirigido.', $e->validator->errors()->first('destinos'));
        }

        $this->assertSame(0, Aviso::count());
    }

    public function test_la_vigencia_no_puede_terminar_antes_de_empezar(): void
    {
        $peticion = Request::create('/plataforma/avisos', 'POST', [
            'titulo' => 'Al revés',
            'cuerpo' => 'Texto.',
            'prioridad' => 'informativo',
            'publicado_desde' => now()->addWeek()->toDateTimeString(),
            'vigente_hasta' => now()->toDateTimeString(),
            'destinos' => [['tipo' => 'todos', 'destino_id' => null]],
        ]);

        try {
            $this->controlador->guardar($peticion);
            $this->fail('Debería haber rechazado la vigencia invertida.');
        } catch (ValidationException $e) {
            $this->assertSame(
                'La vigencia no puede terminar antes de empezar.',
                $e->validator->errors()->first('vigente_hasta'),
            );
        }
    }

    /**
     * Los destinos se rehacen enteros al reeditar. Es lo que garantiza que no
     * sobreviva un destinatario que se quitó del formulario.
     */
    public function test_al_reeditar_los_destinos_se_rehacen_enteros(): void
    {
        $aviso = $this->aviso();
        $aviso->destinos()->create(['tipo' => 'rol', 'destino_id' => 7]);
        $aviso->destinos()->create(['tipo' => 'campus', 'destino_id' => 3]);

        $peticion = Request::create('/plataforma/avisos/1', 'PUT', [
            'titulo' => 'Ya no es para el campus 3',
            'cuerpo' => 'Texto.',
            'prioridad' => 'informativo',
            'publicado' => true,
            'destinos' => [['tipo' => 'rol', 'destino_id' => 7]],
        ]);

        $this->controlador->guardar($peticion, $aviso);

        $destinos = $aviso->fresh()->destinos;

        $this->assertCount(1, $destinos);
        $this->assertSame('rol', $destinos[0]->tipo->value);
        $this->assertSame(7, $destinos[0]->destino_id);
    }

    public function test_publicar_y_retirar_desde_el_renglon(): void
    {
        $aviso = $this->aviso(['publicado' => false]);

        // El campo llega como `publicada`: es el contrato de InterruptorVisible,
        // el mismo componente de todos los listados.
        $this->controlador->publicacion(Request::create('/x', 'PATCH', ['publicada' => '1']), $aviso);
        $this->assertTrue($aviso->fresh()->publicado);

        $this->controlador->publicacion(Request::create('/x', 'PATCH', ['publicada' => '0']), $aviso);
        $this->assertFalse($aviso->fresh()->publicado);
    }

    /** El scope que decide qué está en pantalla hoy. */
    public function test_vigentes_deja_fuera_lo_que_no_toca(): void
    {
        $envigor = $this->aviso();
        $this->aviso(['publicado' => false]);
        $this->aviso(['publicado_desde' => now()->addDay()]);
        $this->aviso(['vigente_hasta' => now()->subDay()]);

        $vigentes = Aviso::vigentes()->get();

        $this->assertCount(1, $vigentes);
        $this->assertSame($envigor->id, $vigentes->first()->id);
    }

    private function aviso(array $extra = []): Aviso
    {
        return Aviso::create([
            'titulo' => 'Aviso',
            'cuerpo' => 'Texto del aviso.',
            'prioridad' => 'informativo',
            'publicado' => true,
            ...$extra,
        ]);
    }
}
