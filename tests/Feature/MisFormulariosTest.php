<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\RespuestaFormularioController;
use App\Models\Admisiones\RespuestaCampo;
use App\Models\Formularios\Formulario;
use App\Models\Formularios\FormularioAsignacion;
use App\Models\Identidad\Persona;
use App\Models\Identidad\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\CreaEscuelaDePrueba;
use Tests\TenantTestCase;

/**
 * «Mis formularios»: el autoservicio de cualquier persona.
 *
 * El aspirante llena los suyos en su solicitud, el alumno en su portal y el
 * docente dentro de «Mi expediente». Un padre de familia no tenía dónde —su
 * portal es sobre sus HIJOS— ni un tutor educativo, así que la escuela podía
 * asignarles un bloque que ellos jamás verían.
 *
 * Esta puerta no habla de ningún oficio: resuelve a la persona de la sesión.
 * Lo que se prueba aquí es justamente eso —que el titular sale de quién está
 * dentro y de ningún otro lado—, porque es lo que la vuelve segura sin `can:`.
 */
class MisFormulariosTest extends TenantTestCase
{
    use CreaEscuelaDePrueba;

    public function test_muestra_los_formularios_que_le_tocan_a_quien_entro(): void
    {
        [$usuario, $formulario] = $this->escenario();

        $peticion = $this->peticionDe($usuario, '/mis-formularios');
        $props = $this->propsDe(app(RespuestaFormularioController::class)->mios($peticion), $peticion);

        $this->assertSame([$formulario->id], collect($props['formularios'])->pluck('id')->all());
    }

    /**
     * Un padre al que no se le asignó nada no ve nada, y tampoco un error.
     *
     * La página es universal para su ámbito: entrar y encontrarla vacía es un
     * resultado válido, no una falla.
     */
    public function test_a_quien_no_se_le_asigno_nada_le_sale_la_lista_vacia(): void
    {
        $usuario = $this->usuarioConAlcance(rol: 'padre_familia');

        $peticion = $this->peticionDe($usuario, '/mis-formularios');
        $props = $this->propsDe(app(RespuestaFormularioController::class)->mios($peticion), $peticion);

        $this->assertSame([], collect($props['formularios'])->all());
    }

    /**
     * Lo contestado cuelga de la PERSONA.
     *
     * Un padre de familia no es aspirante ni tiene matrícula: las dos columnas
     * de capacidad quedan en null, que es lo que significa «contestado como
     * persona».
     */
    public function test_lo_que_contesta_cuelga_de_su_persona(): void
    {
        [$usuario, $formulario] = $this->escenario();
        $campo = $this->campo($formulario, 'Teléfono de emergencia');

        $this->guardar($usuario, $formulario, [$campo => '5512345678']);

        $fila = RespuestaCampo::where('persona_id', $usuario->persona_id)
            ->where('campo_formulario_id', $campo)
            ->firstOrFail();

        $this->assertSame('5512345678', $fila->valor);
        $this->assertNull($fila->aspirante_id);
        $this->assertNull($fila->matricula_oferta_id);
    }

    /**
     * Un formulario que no le toca no se contesta.
     *
     * Es LA comprobación que sostiene esta puerta sin permiso: la URL lleva el
     * id del formulario y se puede teclear, así que sin esto cualquiera con
     * cuenta podría llenarse un bloque que la escuela nunca le asignó y ese
     * dato aparecería en su expediente sin que nadie supiera de dónde salió.
     */
    public function test_no_se_puede_contestar_un_formulario_que_no_le_toca(): void
    {
        $usuario = $this->usuarioConAlcance(rol: 'padre_familia');
        $ajeno = Formulario::create(['clave' => 'ajeno', 'titulo' => 'Ajeno', 'version' => 1, 'orden' => 1]);
        $this->campo($ajeno, 'Lo que sea');

        $this->expectException(HttpException::class);

        app(RespuestaFormularioController::class)->mostrarPersonal(
            $this->peticionDe($usuario, '/mis-formularios'),
            $ajeno,
        );
    }

    /**
     * Y no se baja el archivo de otro.
     *
     * Aquí no hay id de titular en la URL —sale de la sesión—, así que la
     * comprobación no es contra un número tecleado sino contra quién entró: la
     * respuesta de otra persona no aparece entre las suyas.
     */
    public function test_no_se_baja_el_documento_de_otra_persona(): void
    {
        [$usuario, $formulario] = $this->escenario();
        $campo = $this->campo($formulario, 'Comprobante');

        /*
         * El archivo EXISTE de verdad.
         *
         * Con una ruta inventada la prueba pasaba sola: `entregar()` también
         * aborta cuando el archivo no está en disco, así que el 404 llegaba por
         * ahí y no por la comprobación que se quiere probar. Al mutarla, la
         * prueba seguía en verde.
         */
        Storage::fake('local');
        $ruta = 'formularios/ajena/comprobante.pdf';
        Storage::disk('local')->put($ruta, 'contenido');

        $ajena = Persona::create(['nombre' => 'Otra', 'primer_apellido' => 'Distinta']);
        $respuestaAjena = RespuestaCampo::create([
            'persona_id' => $ajena->id,
            'campo_formulario_id' => $campo,
            'formulario_version' => 1,
            'documento_ruta' => $ruta,
        ]);

        $this->expectException(HttpException::class);

        app(RespuestaFormularioController::class)->descargarPersonal(
            $this->peticionDe($usuario, '/mis-formularios'),
            $respuestaAjena,
        );
    }

    // ── Andamiaje ──────────────────────────────────────────────────────────

    /** @return array{0: Usuario, 1: Formulario} */
    private function escenario(): array
    {
        $usuario = $this->usuarioConAlcance(rol: 'padre_familia');

        $formulario = Formulario::create([
            'clave' => 'datos_del_tutor',
            'titulo' => 'Datos del tutor',
            'version' => 1,
            'obligatorio' => true,
            'orden' => 1,
        ]);

        FormularioAsignacion::create([
            'formulario_id' => $formulario->id,
            'rol_id' => (int) DB::table('roles')->where('name', 'padre_familia')->value('id'),
        ]);

        return [$usuario, $formulario];
    }

    private function campo(Formulario $formulario, string $pregunta): int
    {
        return $this->fila('campos_formulario', [
            'formulario_id' => $formulario->id,
            'pregunta' => $pregunta,
            'tipo_campo_id' => $this->deCatalogo('tipos_campo'),
            'orden' => 1,
            'obligatorio' => false,
        ]);
    }

    /** @param  array<int, mixed>  $respuestas */
    private function guardar(Usuario $usuario, Formulario $formulario, array $respuestas): void
    {
        $peticion = Request::create("/mis-formularios/{$formulario->id}", 'POST', ['campos' => $respuestas]);
        $peticion->setUserResolver(fn () => $usuario);

        app(RespuestaFormularioController::class)->guardarPersonal($peticion, $formulario);
    }
}
