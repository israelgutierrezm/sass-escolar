<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\RespuestaFormularioController;
use App\Models\Admisiones\Aspirante;
use App\Models\Admisiones\RespuestaCampo;
use App\Models\ControlEscolar\Docente;
use App\Models\Formularios\Formulario;
use App\Models\Formularios\FormularioAsignacion;
use App\Models\Identidad\Persona;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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

    // ── El documento sube y BAJA ───────────────────────────────────────────

    public function test_se_puede_bajar_el_documento_que_se_subio(): void
    {
        [$aspirante, $formulario] = $this->escenario();
        $campo = $this->campo($formulario, 'Acta', tipo: 'documento');

        $this->subir($aspirante, $formulario, $campo);

        $descarga = app(RespuestaFormularioController::class)->descargar(
            $this->peticionDe($this->usuarioConAlcance(), '/aspirantes'),
            $aspirante,
            $this->respuesta($aspirante, $campo),
        );

        $this->assertSame(200, $descarga->getStatusCode());
    }

    /**
     * El archivo de otro NO se baja.
     *
     * La ruta lleva el id del aspirante y el de la respuesta: sin comprobar que
     * la segunda sea del primero, cambiar un número bajaría el acta de
     * cualquiera. El disco es privado, así que ÉSTE es el único control.
     */
    public function test_no_se_baja_el_documento_de_otro_aspirante(): void
    {
        [$aspirante, $formulario] = $this->escenario();
        $campo = $this->campo($formulario, 'Acta', tipo: 'documento');
        $this->subir($aspirante, $formulario, $campo);

        $ajeno = Aspirante::create([
            'persona_id' => Persona::create(['nombre' => 'Otro', 'primer_apellido' => 'Distinto'])->id,
        ]);

        $this->expectException(HttpException::class);

        app(RespuestaFormularioController::class)->descargar(
            $this->peticionDe($this->usuarioConAlcance(), '/aspirantes'),
            $ajeno,
            $this->respuesta($aspirante, $campo),
        );
    }

    /** Una respuesta de texto no tiene archivo que bajar. */
    public function test_una_respuesta_sin_archivo_no_se_descarga(): void
    {
        [$aspirante, $formulario] = $this->escenario();
        $campo = $this->campo($formulario, 'Alergias');

        $this->guardar($aspirante, $formulario, [$campo => 'Ninguna']);

        $this->expectException(HttpException::class);

        app(RespuestaFormularioController::class)->descargar(
            $this->peticionDe($this->usuarioConAlcance(), '/aspirantes'),
            $aspirante,
            $this->respuesta($aspirante, $campo),
        );
    }

    /** Reguardar sin archivo nuevo no borra el que ya estaba. */
    public function test_reguardar_no_borra_el_documento_ya_subido(): void
    {
        [$aspirante, $formulario] = $this->escenario();
        $campo = $this->campo($formulario, 'Acta', tipo: 'documento');
        $otro = $this->campo($formulario, 'Alergias');

        $this->subir($aspirante, $formulario, $campo);
        $ruta = $this->respuesta($aspirante, $campo)?->documento_ruta;

        $this->guardar($aspirante, $formulario, [$otro => 'Ninguna']);

        $this->assertSame($ruta, $this->respuesta($aspirante, $campo)?->documento_ruta);
    }

    // ── El titular puede ser una persona ───────────────────────────────────

    /**
     * Un docente contesta lo suyo, y se guarda colgado de la PERSONA.
     *
     * No es ni aspirante ni matrícula: las dos columnas de capacidad quedan en
     * null, que es lo que significa «contestado como persona».
     */
    public function test_un_docente_contesta_y_su_respuesta_cuelga_de_la_persona(): void
    {
        [$docente, $formulario] = $this->escenarioDocente();
        $campo = $this->campo($formulario, 'Régimen fiscal');

        $this->guardarDeDocente($docente, $formulario, [$campo => 'Sueldos y salarios']);

        $fila = RespuestaCampo::where('persona_id', $docente->persona_id)
            ->where('campo_formulario_id', $campo)
            ->firstOrFail();

        $this->assertSame('Sueldos y salarios', $fila->valor);
        $this->assertNull($fila->aspirante_id);
        $this->assertNull($fila->matricula_oferta_id);
    }

    /**
     * Y no pisa lo que esa misma persona contestó siendo aspirante.
     *
     * Es el riesgo concreto de colgar de la persona: sin los dos null en la
     * llave, guardar como docente encuentra la fila del aspirante y la
     * sobreescribe. Serían dos expedientes distintos machacándose.
     */
    public function test_contestar_como_docente_no_pisa_lo_que_contesto_de_aspirante(): void
    {
        [$aspirante, $formulario] = $this->escenario();
        $campo = $this->campo($formulario, 'Alergias');
        $this->guardar($aspirante, $formulario, [$campo => 'Penicilina']);

        // La MISMA persona, ahora dada de alta como docente y con el mismo
        // bloque asignado a su rol.
        $docente = $this->docenteDe($aspirante->persona_id);
        FormularioAsignacion::create([
            'formulario_id' => $formulario->id,
            'rol_id' => $this->rolDocente(),
        ]);

        $this->guardarDeDocente($docente, $formulario, [$campo => 'Ninguna']);

        $this->assertSame('Penicilina', $this->respuesta($aspirante, $campo)?->valor, 'Lo del aspirante sigue intacto.');
        $this->assertSame(2, RespuestaCampo::where('campo_formulario_id', $campo)->count());
    }

    /** El archivo de otra persona NO se baja. */
    public function test_no_se_baja_el_documento_de_otra_persona(): void
    {
        [$docente, $formulario] = $this->escenarioDocente();
        $campo = $this->campo($formulario, 'Constancia', tipo: 'documento');
        $this->subirDeDocente($docente, $formulario, $campo);

        $ajeno = $this->docenteDe(Persona::create(['nombre' => 'Otra', 'primer_apellido' => 'Distinta'])->id);

        $this->expectException(HttpException::class);

        app(RespuestaFormularioController::class)->descargarDeDocente(
            $this->peticionDe($this->usuarioConAlcance(), '/escolar/docentes'),
            $ajeno,
            RespuestaCampo::where('persona_id', $docente->persona_id)->firstOrFail(),
        );
    }

    // ── Andamiaje ──────────────────────────────────────────────────────────

    /** Sube un archivo de mentira al campo indicado. */
    private function subir(Aspirante $aspirante, Formulario $formulario, int $campoId): void
    {
        Storage::fake('local');

        $peticion = Request::create(
            "/aspirantes/{$aspirante->id}/formularios/{$formulario->id}",
            'POST',
            [],
            [],
            ['campos' => [$campoId => UploadedFile::fake()->create('acta.pdf', 10, 'application/pdf')]],
        );
        $peticion->setUserResolver(fn () => $this->usuarioConAlcance());

        app(RespuestaFormularioController::class)->guardar($peticion, $aspirante, $formulario);
    }

    /** @return array{0: Docente, 1: Formulario} */
    private function escenarioDocente(): array
    {
        $persona = Persona::create(['nombre' => 'Profe', 'primer_apellido' => 'De prueba']);
        $docente = $this->docenteDe($persona->id);

        $formulario = Formulario::create(['clave' => 'fiscal', 'titulo' => 'Datos fiscales', 'version' => 1, 'orden' => 1]);

        FormularioAsignacion::create([
            'formulario_id' => $formulario->id,
            'rol_id' => $this->rolDocente(),
        ]);

        return [$docente, $formulario];
    }

    private function docenteDe(int $personaId): Docente
    {
        $this->fila('persona_rol', ['persona_id' => $personaId, 'rol_id' => $this->rolDocente(), 'activo' => true]);

        return Docente::create([
            'persona_id' => $personaId,
            'tipo_docente_id' => $this->deCatalogo('tipos_docente'),
            'situacion_id' => $this->deCatalogo('situaciones_docente'),
        ]);
    }

    private function rolDocente(): int
    {
        return (int) (DB::table('roles')->where('name', 'docente')->value('id')
            ?? $this->fila('roles', ['name' => 'docente', 'nombre' => 'Docente', 'guard_name' => 'web']));
    }

    /** @param  array<int, mixed>  $respuestas */
    private function guardarDeDocente(Docente $docente, Formulario $formulario, array $respuestas): void
    {
        $peticion = Request::create(
            "/escolar/docentes/{$docente->persona_id}/formularios/{$formulario->id}",
            'POST',
            ['campos' => $respuestas],
        );
        $peticion->setUserResolver(fn () => $this->usuarioConAlcance());

        app(RespuestaFormularioController::class)->guardarDeDocente($peticion, $docente, $formulario);
    }

    private function subirDeDocente(Docente $docente, Formulario $formulario, int $campoId): void
    {
        Storage::fake('local');

        $peticion = Request::create(
            "/escolar/docentes/{$docente->persona_id}/formularios/{$formulario->id}",
            'POST',
            [],
            [],
            ['campos' => [$campoId => UploadedFile::fake()->create('constancia.pdf', 10, 'application/pdf')]],
        );
        $peticion->setUserResolver(fn () => $this->usuarioConAlcance());

        app(RespuestaFormularioController::class)->guardarDeDocente($peticion, $docente, $formulario);
    }

    /** @return array{0: Aspirante, 1: Formulario} */
    private function escenario(): array
    {
        $persona = Persona::create(['nombre' => 'Prospecto', 'primer_apellido' => 'De prueba']);

        $aspirante = Aspirante::create([
            'persona_id' => $persona->id,
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
