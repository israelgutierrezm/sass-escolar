<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\RespuestaFormularioController;
use App\Models\Admisiones\Aspirante;
use App\Models\Admisiones\RespuestaCampo;
use App\Models\Formularios\Formulario;
use App\Models\Formularios\FormularioAsignacion;
use App\Models\Identidad\Persona;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\CreaEscuelaDePrueba;
use Tests\TenantTestCase;

/**
 * Contestar un formulario del expediente.
 *
 * El resolvedor ya decía qué formularios tocan y cuánto llevaba contestado;
 * faltaba dónde contestarlos, así que el avance de todos era siempre cero y no
 * había forma de moverlo.
 *
 * Lo que se prueba aquí son las reglas que la escuela definió campo por campo:
 * son las que hacen que el dato guardado sirva para algo.
 */
class CapturaFormularioTest extends TenantTestCase
{
    use CreaEscuelaDePrueba;

    public function test_se_guarda_lo_contestado(): void
    {
        [$aspirante, $formulario] = $this->escenario();
        $campo = $this->campo($formulario, 'Alergias');

        $this->guardar($aspirante, $formulario, [$campo => 'Ninguna']);

        $this->assertSame('Ninguna', $this->respuesta($aspirante, $campo)?->valor);
    }

    /** Reguardar corrige, no acumula: una fila por campo. */
    public function test_volver_a_guardar_reescribe_la_misma_fila(): void
    {
        [$aspirante, $formulario] = $this->escenario();
        $campo = $this->campo($formulario, 'Alergias');

        $this->guardar($aspirante, $formulario, [$campo => 'Ninguna']);
        $this->guardar($aspirante, $formulario, [$campo => 'Penicilina']);

        $this->assertSame(1, RespuestaCampo::where('aspirante_id', $aspirante->id)->count());
        $this->assertSame('Penicilina', $this->respuesta($aspirante, $campo)?->valor);
    }

    public function test_un_campo_obligatorio_vacio_no_pasa(): void
    {
        [$aspirante, $formulario] = $this->escenario();
        $campo = $this->campo($formulario, 'Alergias', obligatorio: true);

        $this->expectException(ValidationException::class);
        $this->guardar($aspirante, $formulario, [$campo => '']);
    }

    /**
     * Un campo escondido por su condición NO es obligatorio.
     *
     * Se pregunta «¿fumas?» y sólo si contesta que sí aparece «¿cuántos al
     * día?». Exigir el segundo cuando ni siquiera se muestra deja el formulario
     * imposible de enviar, con un error señalando algo que no está en pantalla.
     */
    public function test_un_obligatorio_escondido_por_su_condicion_no_se_exige(): void
    {
        [$aspirante, $formulario] = $this->escenario();
        $padre = $this->campo($formulario, '¿Fumas?');
        $hijo = $this->campo($formulario, '¿Cuántos al día?', obligatorio: true, padre: $padre, condicional: 'si');

        // Contesta que NO: el hijo no se muestra y no se exige.
        $this->guardar($aspirante, $formulario, [$padre => 'no', $hijo => '']);

        $this->assertSame('no', $this->respuesta($aspirante, $padre)?->valor);
    }

    /** Y cuando la condición SÍ se cumple, vuelve a exigirse. */
    public function test_al_cumplirse_la_condicion_el_hijo_si_se_exige(): void
    {
        [$aspirante, $formulario] = $this->escenario();
        $padre = $this->campo($formulario, '¿Fumas?');
        $hijo = $this->campo($formulario, '¿Cuántos al día?', obligatorio: true, padre: $padre, condicional: 'si');

        $this->expectException(ValidationException::class);
        $this->guardar($aspirante, $formulario, [$padre => 'si', $hijo => '']);
    }

    /** El patrón que la escuela definió, con el mensaje que ella escribió. */
    public function test_se_respeta_el_patron_del_campo_con_su_mensaje(): void
    {
        [$aspirante, $formulario] = $this->escenario();
        $campo = $this->campo($formulario, 'CURP', regex: '^[A-Z]{4}\d{6}', mensaje: 'Escribe una CURP con formato válido.');

        try {
            $this->guardar($aspirante, $formulario, [$campo => 'lo que sea']);
            $this->fail('Debió rechazarse.');
        } catch (ValidationException $e) {
            $this->assertSame('Escribe una CURP con formato válido.', $e->errors()["campos.{$campo}"][0]);
        }
    }

    public function test_un_numero_fuera_de_rango_no_pasa(): void
    {
        [$aspirante, $formulario] = $this->escenario();
        $campo = $this->campo($formulario, 'Edad', tipo: 'numero', min: 15, max: 99);

        $this->expectException(ValidationException::class);
        $this->guardar($aspirante, $formulario, [$campo => 5]);
    }

    /**
     * Una selección múltiple se guarda como JSON.
     *
     * `valor` es una columna de texto; unirlas con comas rompería en cuanto una
     * opción tuviera una coma dentro.
     */
    public function test_una_seleccion_multiple_se_guarda_y_se_recupera_como_lista(): void
    {
        [$aspirante, $formulario] = $this->escenario();
        $campo = $this->campo($formulario, 'Idiomas', tipo: 'multiselect');
        $this->opcion($campo, 'ingles');
        $this->opcion($campo, 'frances');

        $this->guardar($aspirante, $formulario, [$campo => ['ingles', 'frances']]);

        $this->assertSame(['ingles', 'frances'], json_decode((string) $this->respuesta($aspirante, $campo)?->valor, true));
    }

    /** Una opción que no está en la lista no se acepta. */
    public function test_no_se_acepta_una_opcion_inventada(): void
    {
        [$aspirante, $formulario] = $this->escenario();
        $campo = $this->campo($formulario, 'Turno', tipo: 'select');
        $this->opcion($campo, 'matutino');

        $this->expectException(ValidationException::class);
        $this->guardar($aspirante, $formulario, [$campo => 'nocturno']);
    }

    /**
     * Un formulario que no le toca no se contesta.
     *
     * La ficha sólo enlaza los que sí, pero la URL lleva ids y se puede teclear:
     * el dato aparecería después en su expediente sin que nadie supiera de dónde
     * salió.
     */
    public function test_no_se_puede_contestar_un_formulario_que_no_le_toca(): void
    {
        [$aspirante] = $this->escenario();
        $ajeno = Formulario::create(['clave' => 'ajeno', 'titulo' => 'Ajeno', 'version' => 1, 'orden' => 1]);
        $campo = $this->campo($ajeno, 'Lo que sea');

        $this->expectException(HttpException::class);
        $this->guardar($aspirante, $ajeno, [$campo => 'x']);
    }

    /** Guardar a medias se permite: se captura lo que el interesado trae. */
    public function test_se_puede_guardar_incompleto_si_nada_obligatorio_falta(): void
    {
        [$aspirante, $formulario] = $this->escenario();
        $uno = $this->campo($formulario, 'Alergias');
        $dos = $this->campo($formulario, 'Tipo de sangre');

        $this->guardar($aspirante, $formulario, [$uno => 'Ninguna', $dos => '']);

        $this->assertSame('Ninguna', $this->respuesta($aspirante, $uno)?->valor);
        $this->assertNull($this->respuesta($aspirante, $dos)?->valor);
    }

    // ── Andamiaje ──────────────────────────────────────────────────────────

    /** @return array{0: Aspirante, 1: Formulario} */
    private function escenario(): array
    {
        $persona = Persona::create(['nombre' => 'Prospecto', 'primer_apellido' => 'De prueba']);

        $aspirante = Aspirante::create([
            'persona_id' => $persona->id,
            'situacion_id' => $this->deCatalogo('situaciones_aspirante'),
        ]);

        $formulario = Formulario::create(['clave' => 'salud', 'titulo' => 'Salud', 'version' => 1, 'orden' => 1]);

        FormularioAsignacion::create([
            'formulario_id' => $formulario->id,
            'rol_id' => $this->rolAspirante(),
        ]);

        return [$aspirante, $formulario];
    }

    private function rolAspirante(): int
    {
        return (int) (DB::table('roles')->where('name', 'aspirante')->value('id')
            ?? $this->fila('roles', ['name' => 'aspirante', 'nombre' => 'Aspirante', 'guard_name' => 'web']));
    }

    private function campo(
        Formulario $formulario,
        string $pregunta,
        string $tipo = 'texto',
        bool $obligatorio = false,
        ?int $padre = null,
        ?string $condicional = null,
        ?string $regex = null,
        ?string $mensaje = null,
        ?int $min = null,
        ?int $max = null,
    ): int {
        return $this->fila('campos_formulario', [
            'formulario_id' => $formulario->id,
            'pregunta' => $pregunta,
            'tipo_campo_id' => $this->tipoCampo($tipo),
            'obligatorio' => $obligatorio,
            'campo_padre_id' => $padre,
            'condicional' => $condicional,
            'regex' => $regex,
            'mensaje_error' => $mensaje,
            'min' => $min,
            'max' => $max,
            'orden' => 1,
        ]);
    }

    private function tipoCampo(string $clave): int
    {
        return (int) (DB::table('tipos_campo')->where('clave', $clave)->value('id')
            ?? $this->fila('tipos_campo', ['clave' => $clave, 'nombre' => ucfirst($clave)]));
    }

    private function opcion(int $campoId, string $valor): void
    {
        $this->fila('opciones_campo', [
            'campo_formulario_id' => $campoId,
            'valor' => $valor,
            'etiqueta' => ucfirst($valor),
            'orden' => 1,
        ]);
    }

    /** @param  array<int, mixed>  $respuestas */
    private function guardar(Aspirante $aspirante, Formulario $formulario, array $respuestas): void
    {
        $peticion = Request::create(
            "/aspirantes/{$aspirante->id}/formularios/{$formulario->id}",
            'POST',
            ['campos' => $respuestas],
        );
        $peticion->setUserResolver(fn () => $this->usuarioConAlcance());

        app(RespuestaFormularioController::class)->guardar($peticion, $aspirante, $formulario);
    }

    private function respuesta(Aspirante $aspirante, int $campoId): ?RespuestaCampo
    {
        return RespuestaCampo::where('aspirante_id', $aspirante->id)
            ->where('campo_formulario_id', $campoId)
            ->first();
    }
}
