<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Admisiones\Aspirante;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Formularios\Formulario;
use App\Models\Formularios\FormularioAsignacion;
use App\Models\Identidad\Persona;
use App\Services\ConvertidorAspirante;
use App\Services\ResolutorFormularios;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\Concerns\CreaEscuelaDePrueba;
use Tests\TenantTestCase;

/**
 * Qué formularios le tocan a alguien.
 *
 * Las asignaciones se guardaban, se listaban y se copiaban al versionar, y
 * NADIE las leía: configurarlas no cambiaba nada en ninguna pantalla. Esto es
 * lo que las vuelve efecto, así que lo que se prueba aquí es exactamente el
 * criterio con el que se decide quién ve qué.
 */
class ResolutorFormulariosTest extends TenantTestCase
{
    use CreaEscuelaDePrueba;

    private ResolutorFormularios $resolutor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolutor = app(ResolutorFormularios::class);
    }

    public function test_un_formulario_asignado_a_su_rol_le_toca(): void
    {
        $aspirante = $this->aspirante();
        $formulario = $this->formulario('salud');

        $this->asignar($formulario, $this->rol('aspirante'));

        $this->assertSame(['salud'], $this->clavesPara($aspirante));
    }

    public function test_lo_asignado_a_otro_rol_no_le_toca(): void
    {
        $aspirante = $this->aspirante();

        $this->asignar($this->formulario('nomina'), $this->rol('docente'));

        $this->assertSame([], $this->clavesPara($aspirante));
    }

    /**
     * Un rol hijo recibe lo asignado a su faceta.
     *
     * Es la misma herencia que la de permisos: sin ella, cada escuela tendría
     * que repetir la asignación en cada variante de rol que se haya inventado.
     */
    public function test_un_rol_hijo_hereda_lo_de_su_faceta(): void
    {
        $faceta = $this->rol('aspirante');
        $hijo = $this->rol('aspirante_posgrado', padre: $faceta);

        $aspirante = $this->aspirante();
        $this->darRol($aspirante->persona_id, $hijo);

        $this->asignar($this->formulario('salud'), $faceta);

        $this->assertSame(['salud'], $this->clavesPara($aspirante));
    }

    // ── El recorte académico ───────────────────────────────────────────────

    public function test_el_recorte_por_programa_academico_deja_fuera_a_quien_no_es_de_ese_programa_academico(): void
    {
        $escuela = $this->alumnoInscrito();
        $otra = $this->alumnoInscrito();

        $aspirante = $this->aspirante(ofertaId: $escuela['oferta']);
        $formulario = $this->formulario('titulacion');

        $this->asignar($formulario, $this->rol('aspirante'), 'programa_academico', $otra['programa_academico']);

        $this->assertSame([], $this->clavesPara($aspirante));

        // Y con SU programa académico, sí.
        FormularioAsignacion::query()->update(['ambito_id' => $escuela['programa_academico']]);

        $this->assertSame(['titulacion'], $this->clavesPara($aspirante));
    }

    public function test_el_recorte_por_nivel_alcanza_a_toda_programa_academico_de_ese_nivel(): void
    {
        $escuela = $this->alumnoInscrito();
        $aspirante = $this->aspirante(ofertaId: $escuela['oferta']);

        $this->asignar($this->formulario('beca'), $this->rol('aspirante'), 'nivel', $this->nivelDePrueba());

        $this->assertSame(['beca'], $this->clavesPara($aspirante));
    }

    /**
     * Sin programa de interés no hay programa académico contra la que comparar, y se queda
     * fuera: un formulario acotado a Derecho no le toca a quien todavía no ha
     * dicho qué quiere estudiar.
     */
    public function test_sin_programa_de_interes_los_recortados_no_aplican(): void
    {
        $escuela = $this->alumnoInscrito();
        $aspirante = $this->aspirante();

        $this->asignar($this->formulario('titulacion'), $this->rol('aspirante'), 'programa_academico', $escuela['programa_academico']);
        $this->asignar($this->formulario('salud'), $this->rol('aspirante'));

        $this->assertSame(['salud'], $this->clavesPara($aspirante), 'El sin recorte sí; el recortado no.');
    }

    // ── El expediente viaja ────────────────────────────────────────────────

    /**
     * Al alumno le toca lo suyo con el MISMO criterio.
     *
     * Si aspirante y alumno resolvieran distinto, el expediente cambiaría de
     * forma al convertirlo y quedaría medio vacío sin que nadie borrara nada.
     */
    public function test_a_la_matricula_se_le_resuelve_igual_que_al_aspirante(): void
    {
        $escuela = $this->alumnoInscrito();
        $matricula = MatriculaOferta::findOrFail($escuela['matricula']);

        $this->asignar($this->formulario('salud'), $this->rol('alumno'), 'programa_academico', $escuela['programa_academico']);

        $this->assertSame(['salud'], $this->clavesPara($matricula));
    }

    /**
     * Lo contestado siendo aspirante sigue contando ya de alumno.
     *
     * Es la promesa entera del expediente: `ConvertidorAspirante` re-liga las
     * respuestas a la matrícula nueva, y como los dos titulares se resuelven
     * con el mismo criterio, el avance que traía no se pierde al cruzar. Si
     * esto fallara, el alumno vería su expediente en cero el día que se
     * inscribe y volvería a capturar lo mismo.
     */
    public function test_lo_contestado_de_aspirante_sigue_contando_de_alumno(): void
    {
        $escuela = $this->alumnoInscrito();
        $matricula = MatriculaOferta::findOrFail($escuela['matricula']);

        $aspirante = $this->aspirante(ofertaId: $escuela['oferta']);
        $formulario = $this->formulario('salud');
        $campo = $this->campo($formulario, 'Alergias');

        // EL MISMO bloque, asignado a los dos roles: es lo que hace que el
        // expediente siga siendo uno al cruzar.
        $this->asignar($formulario, $this->rol('aspirante'));
        $this->asignar($formulario, $this->rol('alumno'));

        $this->responder($aspirante, $campo, 'Ninguna');

        // El religado REAL de la conversión, no una copia suya: si mañana
        // cambia, esta prueba tiene que enterarse.
        $religar = new ReflectionMethod(ConvertidorAspirante::class, 'religarRespuestas');
        $religar->setAccessible(true);
        $religar->invoke(app(ConvertidorAspirante::class), $aspirante, $matricula);

        $this->assertSame(1, $this->resolutor->para($matricula)->first()['contestados']);
    }

    // ── Quien no es ni aspirante ni alumno ─────────────────────────────────

    /**
     * A un docente o a un tutor también se le piden bloques de datos.
     *
     * El titular es la PERSONA: no hay matrícula ni programa académico de la que colgarlos.
     * Sin esto, los formularios sólo sabían de quien estudia.
     */
    public function test_a_una_persona_le_tocan_los_formularios_de_su_rol(): void
    {
        $persona = $this->persona();
        $this->darRol($persona->id, $this->rol('docente'));

        $this->asignar($this->formulario('constancia_fiscal'), $this->rol('docente'));

        $this->assertSame(['constancia_fiscal'], $this->clavesPara($persona));
    }

    /**
     * Sin roles, ninguno.
     *
     * Un aspirante sin cuenta cae a la faceta «aspirante» porque su TIPO ya
     * dice a qué vino; «persona» no dice nada, y suponerle un rol sería
     * inventarle un expediente que nadie le asignó.
     */
    public function test_una_persona_sin_roles_no_recibe_ninguno(): void
    {
        $persona = $this->persona();

        $this->asignar($this->formulario('constancia_fiscal'), $this->rol('docente'));
        // También el de la faceta a la que cae un aspirante sin cuenta: si
        // alguien extendiera ese respaldo a las personas, aparecería aquí.
        $this->asignar($this->formulario('salud'), $this->rol('aspirante'));

        $this->assertSame([], $this->clavesPara($persona));
    }

    /**
     * Lo contestado en otra calidad NO cuenta aquí.
     *
     * La misma persona puede ser docente y haber sido aspirante. Sus respuestas
     * de aspirante son suyas, pero se dieron en otra capacidad y a otras
     * preguntas: si contaran, su expediente de docente aparecería avanzado sin
     * que él hubiera contestado nada como tal. Es lo que exige que la consulta
     * por persona pida además las dos columnas de capacidad en null.
     */
    public function test_una_persona_no_ve_como_suyo_lo_que_contesto_siendo_aspirante(): void
    {
        $aspirante = $this->aspirante();
        $persona = Persona::findOrFail($aspirante->persona_id);
        $this->darRol($persona->id, $this->rol('docente'));

        $formulario = $this->formulario('salud');
        $campo = $this->campo($formulario, 'Alergias');
        $this->asignar($formulario, $this->rol('docente'));

        $this->responder($aspirante, $campo, 'Ninguna');

        $this->assertSame(0, $this->resolutor->para($persona)->first()['contestados']);

        // Y al contestarlo COMO PERSONA sí cuenta: lo que separa las dos no es
        // que una no vea nada, es que cada una ve lo suyo.
        $this->responderComoPersona($persona, $campo, 'Ninguna');

        $this->assertSame(1, $this->resolutor->para($persona)->first()['contestados']);
    }

    /**
     * Los recortes académicos no le llegan.
     *
     * Un formulario acotado a Derecho no le toca a quien no cursa nada. Sin
     * esto, un docente recibiría los bloques de todos los programas académicos a la vez.
     */
    public function test_a_una_persona_no_le_llegan_los_recortados_por_programa_academico(): void
    {
        $escuela = $this->alumnoInscrito();
        $persona = $this->persona();
        $this->darRol($persona->id, $this->rol('docente'));

        $this->asignar($this->formulario('titulacion'), $this->rol('docente'), 'programa_academico', $escuela['programa_academico']);
        $this->asignar($this->formulario('constancia_fiscal'), $this->rol('docente'));

        $this->assertSame(['constancia_fiscal'], $this->clavesPara($persona), 'El sin recorte sí; el recortado no.');
    }

    // ── Versiones ──────────────────────────────────────────────────────────

    /**
     * De cada clave, sólo la versión más alta.
     *
     * Al publicar una versión nueva se copian sus asignaciones; sin filtrar, la
     * persona vería la v1 y la v2 del mismo bloque como dos formularios
     * distintos y no sabría cuál llenar.
     */
    public function test_solo_se_ofrece_la_version_mas_alta_de_cada_clave(): void
    {
        $aspirante = $this->aspirante();
        $rol = $this->rol('aspirante');

        $this->asignar($this->formulario('salud', version: 1), $rol);
        $this->asignar($this->formulario('salud', version: 2), $rol);

        $resueltos = $this->resolutor->para($aspirante);

        $this->assertCount(1, $resueltos);
        $this->assertSame(2, $resueltos->first()['version']);
    }

    // ── Avance ─────────────────────────────────────────────────────────────

    public function test_cuenta_lo_contestado_y_solo_lo_que_trae_valor(): void
    {
        $aspirante = $this->aspirante();
        $formulario = $this->formulario('salud');
        $uno = $this->campo($formulario, 'Alergias');
        $this->campo($formulario, 'Tipo de sangre');

        $this->asignar($formulario, $this->rol('aspirante'));

        $this->responder($aspirante, $uno, 'Ninguna');

        $resuelto = $this->resolutor->para($aspirante)->first();

        $this->assertSame(2, $resuelto['campos']);
        $this->assertSame(1, $resuelto['contestados']);
        $this->assertFalse($resuelto['completo']);
    }

    /** Una respuesta en blanco no es una respuesta. */
    public function test_una_respuesta_vacia_no_cuenta_como_contestada(): void
    {
        $aspirante = $this->aspirante();
        $formulario = $this->formulario('salud');
        $campo = $this->campo($formulario, 'Alergias');

        $this->asignar($formulario, $this->rol('aspirante'));
        $this->responder($aspirante, $campo, null);

        $this->assertSame(0, $this->resolutor->para($aspirante)->first()['contestados']);
    }

    /**
     * Un formulario opcional asignado como obligatorio a un programa académico, lo es.
     * Son dos decisiones distintas y gana la que exige.
     */
    public function test_la_asignacion_puede_volver_obligatorio_un_formulario_que_no_lo_era(): void
    {
        $escuela = $this->alumnoInscrito();
        $aspirante = $this->aspirante(ofertaId: $escuela['oferta']);

        $formulario = $this->formulario('salud', obligatorio: false);
        $this->asignar($formulario, $this->rol('aspirante'), 'programa_academico', $escuela['programa_academico'], obligatorio: true);

        $this->assertTrue($this->resolutor->para($aspirante)->first()['obligatorio']);
    }

    /**
     * Un condicional que NO aplica no cuenta para el avance.
     *
     * Se pregunta «¿fumas?» y sólo si contesta que sí aparece «¿cuántos al
     * día?». Contarlo de todos modos dejaba a cualquier no fumador en 1 de 2
     * para siempre, con un «falta 1 obligatorio» que no tenía forma de
     * resolver. Salió al usarlo desde el portal, no de una prueba.
     */
    public function test_un_condicional_que_no_aplica_no_cuenta_para_el_avance(): void
    {
        $aspirante = $this->aspirante();
        $formulario = $this->formulario('salud');
        $padre = $this->campo($formulario, '¿Fumas?');
        $this->campoCondicional($formulario, '¿Cuántos al día?', $padre, 'si');

        $this->asignar($formulario, $this->rol('aspirante'));
        $this->responder($aspirante, $padre, 'no');

        $resuelto = $this->resolutor->para($aspirante)->first();

        $this->assertSame(1, $resuelto['campos'], 'La condicional no se cuenta.');
        $this->assertTrue($resuelto['completo']);
    }

    /** Y cuando SÍ aplica, vuelve a contar y falta contestarla. */
    public function test_al_cumplirse_la_condicion_el_campo_vuelve_a_contar(): void
    {
        $aspirante = $this->aspirante();
        $formulario = $this->formulario('salud');
        $padre = $this->campo($formulario, '¿Fumas?');
        $this->campoCondicional($formulario, '¿Cuántos al día?', $padre, 'si');

        $this->asignar($formulario, $this->rol('aspirante'));
        $this->responder($aspirante, $padre, 'si');

        $resuelto = $this->resolutor->para($aspirante)->first();

        $this->assertSame(2, $resuelto['campos']);
        $this->assertFalse($resuelto['completo']);
    }

    // ── Andamiaje ──────────────────────────────────────────────────────────

    private function campoCondicional(Formulario $formulario, string $pregunta, int $padre, string $condicional): int
    {
        return $this->fila('campos_formulario', [
            'formulario_id' => $formulario->id,
            'pregunta' => $pregunta,
            'tipo_campo_id' => $this->deCatalogo('tipos_campo'),
            'campo_padre_id' => $padre,
            'condicional' => $condicional,
            'orden' => 2,
            'obligatorio' => true,
        ]);
    }

    /** @return array<int, string> */
    private function clavesPara(Aspirante|MatriculaOferta|Persona $titular): array
    {
        return $this->resolutor->para($titular)->pluck('clave')->sort()->values()->all();
    }

    private function formulario(string $clave, int $version = 1, bool $obligatorio = true): Formulario
    {
        return Formulario::create([
            'clave' => $clave,
            'titulo' => ucfirst($clave),
            'version' => $version,
            'obligatorio' => $obligatorio,
            'orden' => 1,
        ]);
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

    private function responder(Aspirante $aspirante, int $campoId, ?string $valor): void
    {
        $this->fila('respuestas_campo', [
            'campo_formulario_id' => $campoId,
            'formulario_version' => 1,
            'persona_id' => $aspirante->persona_id,
            'aspirante_id' => $aspirante->id,
            'valor' => $valor,
        ]);
    }

    /** Una respuesta dada COMO PERSONA: sin aspirante ni matrícula detrás. */
    private function responderComoPersona(Persona $persona, int $campoId, ?string $valor): void
    {
        $this->fila('respuestas_campo', [
            'campo_formulario_id' => $campoId,
            'formulario_version' => 1,
            'persona_id' => $persona->id,
            'valor' => $valor,
        ]);
    }

    private function persona(): Persona
    {
        return Persona::create(['nombre' => 'Alguien', 'primer_apellido' => 'De prueba']);
    }

    private function asignar(
        Formulario $formulario,
        int $rolId,
        ?string $ambitoTipo = null,
        ?int $ambitoId = null,
        bool $obligatorio = false,
    ): void {
        FormularioAsignacion::create([
            'formulario_id' => $formulario->id,
            'rol_id' => $rolId,
            'ambito_tipo' => $ambitoTipo,
            'ambito_id' => $ambitoId,
            'obligatorio' => $obligatorio,
        ]);
    }

    private function rol(string $clave, ?int $padre = null): int
    {
        $id = DB::table('roles')->where('name', $clave)->value('id');

        if ($id !== null) {
            return (int) $id;
        }

        return $this->fila('roles', [
            'name' => $clave,
            'nombre' => ucfirst($clave),
            'guard_name' => 'web',
            'rol_padre_id' => $padre,
        ]);
    }

    private function darRol(int $personaId, int $rolId): void
    {
        $this->fila('persona_rol', ['persona_id' => $personaId, 'rol_id' => $rolId, 'activo' => true]);
    }

    private function aspirante(?int $ofertaId = null): Aspirante
    {
        $persona = Persona::create(['nombre' => 'Prospecto', 'primer_apellido' => 'De prueba']);

        return Aspirante::create([
            'persona_id' => $persona->id,
            'oferta_interes_id' => $ofertaId,
        ]);
    }
}
