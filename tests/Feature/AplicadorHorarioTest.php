<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\AvisoParaElUsuario;
use App\Models\ControlEscolar\HorarioAsignaturaGrupo;
use App\Services\Horarios\AplicadorHorario;
use Tests\Concerns\CreaEscuelaDePrueba;
use Tests\TenantTestCase;

/**
 * Escribir un horario, que es el paso irreversible.
 *
 * El generador propone y alguien acepta; lo que llega aquí viene del navegador
 * y pudo haber pasado por veinte minutos y otra persona. Un validador que
 * confía en lo que le devuelven es un validador decorativo, así que lo que se
 * prueba es que TODO se revisa otra vez contra el estado actual de la base.
 */
class AplicadorHorarioTest extends TenantTestCase
{
    use CreaEscuelaDePrueba;

    private AplicadorHorario $aplicador;

    protected function setUp(): void
    {
        parent::setUp();

        $this->aplicador = app(AplicadorHorario::class);
    }

    public function test_escribe_los_bloques(): void
    {
        $materia = $this->materia();

        $this->aplicador->aplicar([
            $this->bloque($materia, dia: 1, de: '07:00', a: '09:00'),
            $this->bloque($materia, dia: 3, de: '07:00', a: '10:00'),
        ]);

        $this->assertSame(2, HorarioAsignaturaGrupo::where('asignatura_grupo_id', $materia)->count());
    }

    /**
     * Aplicar dos veces no duplica: reemplaza.
     *
     * Es lo que hace que «volver a generar y aplicar» sea seguro. Sin esto, el
     * segundo intento dejaría el horario con el doble de clases y nadie lo
     * notaría hasta ver la rejilla.
     */
    public function test_aplicar_dos_veces_reemplaza_en_vez_de_duplicar(): void
    {
        $materia = $this->materia();

        $this->aplicador->aplicar([$this->bloque($materia, dia: 1, de: '07:00', a: '09:00')]);
        $this->aplicador->aplicar([$this->bloque($materia, dia: 2, de: '10:00', a: '12:00')]);

        $bloques = HorarioAsignaturaGrupo::where('asignatura_grupo_id', $materia)->get();

        $this->assertCount(1, $bloques);
        $this->assertSame(2, (int) $bloques->first()->dia_semana);
    }

    // ── Lo que se rechaza ──────────────────────────────────────────────────

    /** El grupo no puede tener dos clases a la vez. */
    public function test_rechaza_dos_clases_del_mismo_grupo_encimadas(): void
    {
        $escuela = $this->alumnoInscrito();
        $grupo = $this->grupoDe($escuela);
        $una = $this->materia($escuela, $grupo);
        $otra = $this->materia($escuela, $grupo);

        $this->expectException(AvisoParaElUsuario::class);

        $this->aplicador->aplicar([
            $this->bloque($una, dia: 1, de: '07:00', a: '09:00'),
            $this->bloque($otra, dia: 1, de: '08:00', a: '10:00'),
        ]);
    }

    /** Ni el mismo salón dos grupos a la vez. */
    public function test_rechaza_dos_clases_en_el_mismo_salon(): void
    {
        $escuela = $this->alumnoInscrito();
        $aula = $this->aula($escuela['campus']);

        $una = $this->materia($escuela, $this->grupoDe($escuela));
        $otra = $this->materia($escuela, $this->grupoDe($escuela)); // OTRO grupo

        $this->expectException(AvisoParaElUsuario::class);

        $this->aplicador->aplicar([
            $this->bloque($una, dia: 1, de: '07:00', a: '09:00', aula: $aula),
            $this->bloque($otra, dia: 1, de: '08:00', a: '10:00', aula: $aula),
        ]);
    }

    /**
     * Y se compara contra lo que YA está guardado de otras materias.
     *
     * Es el caso que la validación en el navegador no puede cubrir: entre
     * generar y aplicar, alguien más capturó una clase a mano.
     */
    public function test_rechaza_el_choque_con_un_horario_ya_guardado(): void
    {
        $escuela = $this->alumnoInscrito();
        $grupo = $this->grupoDe($escuela);
        $yaTiene = $this->materia($escuela, $grupo);
        $nueva = $this->materia($escuela, $grupo);

        $this->fila('horarios_asignatura_grupo', [
            'asignatura_grupo_id' => $yaTiene,
            'dia_semana' => 1,
            'hora_inicio' => '07:00:00',
            'hora_fin' => '09:00:00',
            'modalidad' => 'presencial',
        ]);

        $this->expectException(AvisoParaElUsuario::class);

        $this->aplicador->aplicar([$this->bloque($nueva, dia: 1, de: '08:00', a: '10:00')]);
    }

    /** Una materia que ya no existe detiene todo: la propuesta está vieja. */
    public function test_rechaza_una_materia_inexistente(): void
    {
        $this->expectException(AvisoParaElUsuario::class);

        $this->aplicador->aplicar([$this->bloque(999999, dia: 1, de: '07:00', a: '09:00')]);
    }

    // ── El docente ─────────────────────────────────────────────────────────

    /** Con la opción activa, se le pone docente a la materia que no tenía. */
    public function test_asigna_docente_a_quien_no_tenia(): void
    {
        $materia = $this->materia();
        $persona = $this->docente();

        $this->aplicador->aplicar(
            [$this->bloque($materia, dia: 1, de: '07:00', a: '09:00', persona: $persona)],
            asignarDocentes: true,
        );

        $this->assertDatabaseHas('docente_asignatura_grupo', [
            'asignatura_grupo_id' => $materia,
            'persona_id' => $persona,
        ]);
    }

    /**
     * Pero al que YA tiene titular no se le toca.
     *
     * Reasignar en silencio al aplicar un horario sería cambiar una decisión de
     * la coordinación por la vía de un botón que dice otra cosa.
     */
    public function test_no_reemplaza_al_docente_que_ya_estaba(): void
    {
        $materia = $this->materia();
        $titular = $this->docente();
        $otro = $this->docente();

        $this->fila('docente_asignatura_grupo', [
            'asignatura_grupo_id' => $materia,
            'persona_id' => $titular,
            'tipo' => 'titular',
        ]);

        $this->aplicador->aplicar(
            [$this->bloque($materia, dia: 1, de: '07:00', a: '09:00', persona: $otro)],
            asignarDocentes: true,
        );

        $this->assertDatabaseMissing('docente_asignatura_grupo', [
            'asignatura_grupo_id' => $materia,
            'persona_id' => $otro,
        ]);
        $this->assertDatabaseHas('docente_asignatura_grupo', [
            'asignatura_grupo_id' => $materia,
            'persona_id' => $titular,
        ]);
    }

    // ── Captura manual ─────────────────────────────────────────────────────

    /** Un bloque suelto se agrega SIN borrar lo que ya tenía esa materia. */
    public function test_la_captura_manual_suma_en_vez_de_reemplazar(): void
    {
        $materia = $this->materia();

        $this->aplicador->aplicarUno($this->bloque($materia, dia: 1, de: '07:00', a: '09:00'));
        $this->aplicador->aplicarUno($this->bloque($materia, dia: 2, de: '07:00', a: '09:00'));

        $this->assertSame(2, HorarioAsignaturaGrupo::where('asignatura_grupo_id', $materia)->count());
    }

    /**
     * Y pasa por la misma validación que la propuesta.
     *
     * Dos puertas al mismo dato con dos criterios distintos es cómo se llena
     * una base de horarios imposibles: el generador tiene prohibido crear un
     * choque y la captura manual no puede ser el agujero por donde entran.
     */
    public function test_la_captura_manual_tambien_rechaza_choques(): void
    {
        $materia = $this->materia();

        $this->aplicador->aplicarUno($this->bloque($materia, dia: 1, de: '07:00', a: '09:00'));

        $this->expectException(AvisoParaElUsuario::class);

        $this->aplicador->aplicarUno($this->bloque($materia, dia: 1, de: '08:00', a: '10:00'));
    }

    // ── Andamiaje ──────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function bloque(
        int $materia,
        int $dia,
        string $de,
        string $a,
        ?int $aula = null,
        ?int $persona = null,
    ): array {
        return [
            'asignatura_grupo_id' => $materia,
            'dia' => $dia,
            'hora_inicio' => $de,
            'hora_fin' => $a,
            'aula_id' => $aula,
            'persona_id' => $persona,
            'modalidad' => 'presencial',
        ];
    }

    private function materia(?array $escuela = null, ?int $grupo = null): int
    {
        $escuela ??= $this->alumnoInscrito();
        $grupo ??= $this->grupoDe($escuela);
        $unico = uniqid();

        $asignatura = $this->fila('asignaturas', [
            'identificador' => "ASI-{$unico}",
            'clave' => "A-{$unico}",
            'nombre' => 'Materia de prueba',
            'creditos' => 8,
            'tipo_asignatura_id' => $this->deCatalogo('tipos_asignatura'),
            'horas_teoria' => 5,
            'horas_practica' => 0,
        ]);

        $planMateria = $this->fila('plan_materias', [
            'plan_id' => $escuela['plan'],
            'asignatura_id' => $asignatura,
            'clave_en_plan' => "PM-{$unico}",
            'periodo' => 1,
            'tipo' => 'obligatoria',
        ]);

        return $this->fila('asignatura_grupo', [
            'grupo_id' => $grupo,
            'plan_materia_id' => $planMateria,
            'situacion_id' => $this->deCatalogo('situaciones_asignatura_grupo'),
        ]);
    }

    private function grupoDe(array $escuela): int
    {
        return $this->fila('grupos', [
            'ciclo_id' => $this->cicloDePrueba(),
            'campus_id' => $escuela['campus'],
            'plan_id' => $escuela['plan'],
            'clave' => 'G-'.uniqid(),
            'cupo' => 40,
            'situacion_id' => $this->deCatalogo('situaciones_grupo'),
            'nivel_estudios_id' => $this->nivelDePrueba(),
            'semestre' => 1,
        ]);
    }

    private function aula(int $campus): int
    {
        return $this->fila('aulas', [
            'campus_id' => $campus,
            'clave' => 'A-'.uniqid(),
            'nombre' => 'Aula de prueba',
            'capacidad' => 40,
        ]);
    }

    private function docente(): int
    {
        $persona = $this->fila('personas', ['nombre' => 'Profe', 'primer_apellido' => 'De prueba']);

        $this->fila('docentes', [
            'persona_id' => $persona,
            'tipo_docente_id' => $this->deCatalogo('tipos_docente'),
            'situacion_id' => $this->deCatalogo('situaciones_docente'),
        ]);

        return $persona;
    }
}
