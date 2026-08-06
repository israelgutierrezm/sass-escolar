<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ControlEscolar\DisponibilidadDocente;
use App\Models\ControlEscolar\ReglaHorario;
use App\Services\Horarios\GeneradorHorarios;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreaEscuelaDePrueba;
use Tests\TenantTestCase;

/**
 * El motor que propone un horario.
 *
 * Lo que se prueba aquí no es que el horario sea «bueno» —eso depende de mil
 * preferencias que ninguna escuela escribe— sino que sea VÁLIDO y que DIGA lo
 * que no pudo resolver. Un generador que produce choques es peor que no tener
 * generador: el choque se descubre cuando treinta alumnos están parados en un
 * pasillo.
 */
class GeneradorHorariosTest extends TenantTestCase
{
    use CreaEscuelaDePrueba;

    private GeneradorHorarios $generador;

    protected function setUp(): void
    {
        parent::setUp();

        $this->generador = app(GeneradorHorarios::class);
    }

    // ── Sin configuración no rompe ─────────────────────────────────────────

    /**
     * Sin reglas configuradas avisa, no revienta.
     *
     * La generación es opcional: una escuela que no la use tiene que poder
     * abrir la pantalla sin encontrarse un error.
     */
    public function test_sin_reglas_avisa_en_vez_de_romperse(): void
    {
        $escuela = $this->escuelaConMateria(horas: 4);

        $salida = $this->generador->paraGrupos([$escuela['grupo']]);

        $this->assertFalse($salida['ok']);
        $this->assertStringContainsString('reglas de horario', $salida['aviso']);
    }

    public function test_sin_grupos_avisa(): void
    {
        $this->assertFalse($this->generador->paraGrupos([])['ok']);
    }

    // ── El caso feliz ──────────────────────────────────────────────────────

    /** Una materia de 4 horas con un docente disponible queda colocada. */
    public function test_coloca_las_horas_de_una_materia(): void
    {
        $escuela = $this->escuelaConMateria(horas: 4);
        $this->regla($escuela);
        $docente = $this->docenteApto($escuela, disponibleDe: '07:00', a: '13:00');

        $salida = $this->generador->paraGrupos([$escuela['grupo']]);

        $this->assertTrue($salida['ok']);
        $this->assertSame([], $salida['sin_colocar']);
        $this->assertSame(4.0, $salida['resumen']['horas_colocadas']);

        foreach ($salida['bloques'] as $bloque) {
            $this->assertSame($docente, $bloque['persona_id']);
        }
    }

    /**
     * Y las parte en sesiones del tamaño permitido.
     *
     * Con máximo 2 bloques por sesión, una materia de 5 horas sale 2+2+1: es la
     * regla de división que la escuela pidió poder configurar.
     */
    public function test_parte_la_materia_en_sesiones_del_tamano_permitido(): void
    {
        $escuela = $this->escuelaConMateria(horas: 5);
        $this->regla($escuela, maxBloquesSesion: 2);
        $this->docenteApto($escuela, disponibleDe: '07:00', a: '13:00');

        $salida = $this->generador->paraGrupos([$escuela['grupo']]);

        $duraciones = collect($salida['bloques'])
            ->map(fn (array $b) => (int) (DisponibilidadDocente::aMinutos($b['hora_fin']) - DisponibilidadDocente::aMinutos($b['hora_inicio'])) / 60)
            ->sort()->values()->all();

        $this->assertSame([1, 2, 2], $duraciones);
        $this->assertSame([], $salida['sin_colocar']);
    }

    // ── Lo que NO puede hacer ──────────────────────────────────────────────

    /**
     * Dos clases del mismo grupo nunca a la misma hora.
     *
     * Es la restricción que no se puede violar jamás: los alumnos son los
     * mismos y no pueden estar en dos aulas.
     */
    public function test_nunca_encima_dos_materias_del_mismo_grupo(): void
    {
        $escuela = $this->escuelaConMateria(horas: 3);
        $otra = $this->otraMateriaEnElGrupo($escuela, horas: 3);
        $this->regla($escuela);

        /*
         * Un docente DISTINTO para cada materia, a propósito.
         *
         * Con el mismo docente en las dos, lo que impide el choque es la
         * restricción del docente y esta prueba pasa aunque la del grupo no
         * exista —se comprobó mutando: quitarla no la tumbaba—. Con dos
         * personas libres a la misma hora, lo único que puede separarlas es
         * que los alumnos son los mismos.
         */
        $this->docenteApto($escuela, disponibleDe: '07:00', a: '15:00');
        $this->docenteDe($otra, $this->ultimaAsignatura, disponibleDe: '07:00', a: '15:00');

        /*
         * Y un aula de sobra.
         *
         * Con una sola, lo que impedía el choque era que el salón estaba
         * ocupado —también se comprobó mutando—. Con dos docentes libres y dos
         * salones libres, lo único que puede separar las clases es que los
         * alumnos del grupo son los mismos.
         */
        $this->aulaExtra($escuela['campus']);

        $salida = $this->generador->paraGrupos([$escuela['grupo']]);

        $this->assertNoHayChoques($salida['bloques']);
        $this->assertSame(6.0, $salida['resumen']['horas_colocadas']);
    }

    /** Ni pone al mismo docente en dos lugares a la vez. */
    public function test_nunca_encima_al_mismo_docente(): void
    {
        $escuela = $this->escuelaConMateria(horas: 3);
        $this->otraMateriaEnElGrupo($escuela, horas: 3);
        $this->regla($escuela);
        $this->docenteApto($escuela, disponibleDe: '07:00', a: '15:00', todasLasMaterias: true);

        $salida = $this->generador->paraGrupos([$escuela['grupo']]);

        $porDocente = collect($salida['bloques'])->groupBy('persona_id');

        foreach ($porDocente as $bloques) {
            $this->assertNoHayChoques($bloques->all());
        }
    }

    // ── Los diagnósticos ───────────────────────────────────────────────────

    /**
     * Sin nadie que pueda dar la materia, lo dice.
     *
     * Es el diagnóstico más útil de todos: la respuesta no es «no se pudo» sino
     * «falta registrar quién sabe dar esto».
     */
    public function test_dice_cuando_nadie_puede_impartir_la_materia(): void
    {
        $escuela = $this->escuelaConMateria(horas: 4);
        $this->regla($escuela);
        // Sin docente apto a propósito.

        $salida = $this->generador->paraGrupos([$escuela['grupo']]);

        $this->assertCount(1, $salida['sin_colocar']);
        $this->assertStringContainsString('pueda impartir', $salida['sin_colocar'][0]['motivo']);
        $this->assertSame(0, $salida['sin_colocar'][0]['horas_colocadas']);
    }

    /**
     * Y cuando el docente existe pero no tiene hueco suficiente, coloca lo que
     * cabe y reporta el resto.
     *
     * Media materia colocada deja ver dónde aprieta; un cero no dice nada.
     */
    public function test_coloca_lo_que_cabe_y_reporta_lo_que_falta(): void
    {
        $escuela = $this->escuelaConMateria(horas: 6);
        $this->regla($escuela);
        // Sólo 2 horas disponibles, un día: caben 2 de las 6.
        $this->docenteApto($escuela, disponibleDe: '07:00', a: '09:00', dias: [1]);

        $salida = $this->generador->paraGrupos([$escuela['grupo']]);

        $this->assertCount(1, $salida['sin_colocar']);
        $this->assertSame(2, $salida['sin_colocar'][0]['horas_colocadas']);
        $this->assertSame(6, $salida['sin_colocar'][0]['horas_pedidas']);
    }

    // ── Lo que ya existe se respeta ────────────────────────────────────────

    /**
     * Un horario ya capturado no se pisa.
     *
     * Generar el horario de un grupo sin mirar lo que ya está resuelto produce
     * choques de aula y de docente que aparecerían en producción.
     */
    public function test_respeta_los_horarios_que_ya_existen(): void
    {
        $escuela = $this->escuelaConMateria(horas: 2);
        $otra = $this->otraMateriaEnElGrupo($escuela, horas: 2);
        $this->regla($escuela);
        $this->docenteApto($escuela, disponibleDe: '07:00', a: '11:00', todasLasMaterias: true);

        // La otra materia YA tiene clase el lunes de 7 a 9.
        $this->fila('horarios_asignatura_grupo', [
            'asignatura_grupo_id' => $otra,
            'dia_semana' => 1,
            'hora_inicio' => '07:00:00',
            'hora_fin' => '09:00:00',
            'modalidad' => 'presencial',
        ]);

        $salida = $this->generador->paraGrupos([$escuela['grupo']]);

        foreach ($salida['bloques'] as $bloque) {
            $chocaConLoExistente = $bloque['dia'] === 1
                && DisponibilidadDocente::aMinutos($bloque['hora_inicio']) < 9 * 60
                && DisponibilidadDocente::aMinutos($bloque['hora_fin']) > 7 * 60;

            $this->assertFalse($chocaConLoExistente, 'Se encimó con un horario que ya estaba capturado.');
        }
    }

    // ── El docente ya asignado manda ───────────────────────────────────────

    /**
     * Si la materia ya tiene docente, se le respeta.
     *
     * El generador acomoda horas, no reasigna gente: cambiar de titular es una
     * decisión de la coordinación, y hacerlo en silencio sería lo peor que
     * podría hacer.
     */
    public function test_respeta_al_docente_ya_asignado(): void
    {
        $escuela = $this->escuelaConMateria(horas: 2);
        $this->regla($escuela);

        /*
         * El titular es el MENOS preferido de los dos.
         *
         * Si los dos fueran iguales, el generador podría elegir al titular por
         * casualidad y la prueba pasaría aunque no se respetara la asignación
         * —se comprobó mutando—. Con el otro marcado como preferido, elegirlo a
         * él es la única salida posible si la asignación se ignora.
         */
        $titular = $this->docenteApto($escuela, disponibleDe: '07:00', a: '13:00', preferencia: -1);
        $otro = $this->docenteApto($escuela, disponibleDe: '07:00', a: '13:00', preferencia: 1);

        $this->fila('docente_asignatura_grupo', [
            'asignatura_grupo_id' => $escuela['asignaturaGrupo'],
            'persona_id' => $titular,
            'tipo' => 'titular',
        ]);

        $salida = $this->generador->paraGrupos([$escuela['grupo']]);

        $this->assertNotEmpty($salida['bloques']);

        foreach ($salida['bloques'] as $bloque) {
            $this->assertSame($titular, $bloque['persona_id']);
            $this->assertNotSame($otro, $bloque['persona_id']);
        }
    }

    // ── Modalidad ──────────────────────────────────────────────────────────

    /** Un docente disponible sólo en línea produce clases en línea, sin aula. */
    public function test_la_disponibilidad_en_linea_produce_clases_sin_aula(): void
    {
        $escuela = $this->escuelaConMateria(horas: 2);
        $this->regla($escuela);
        $this->docenteApto($escuela, disponibleDe: '07:00', a: '13:00', modalidad: DisponibilidadDocente::EN_LINEA);

        $salida = $this->generador->paraGrupos([$escuela['grupo']]);

        $this->assertNotEmpty($salida['bloques']);

        foreach ($salida['bloques'] as $bloque) {
            $this->assertSame('en_linea', $bloque['modalidad']);
            $this->assertNull($bloque['aula_id'], 'Una clase en línea no debe ocupar aula.');
        }
    }

    // ── Topes de carga ─────────────────────────────────────────────────────

    /** El tope diario del docente se respeta aunque tenga hueco. */
    public function test_respeta_el_tope_de_horas_por_dia(): void
    {
        $escuela = $this->escuelaConMateria(horas: 6);
        $this->regla($escuela, maxHorasDia: 2);
        $this->docenteApto($escuela, disponibleDe: '07:00', a: '19:00');

        $salida = $this->generador->paraGrupos([$escuela['grupo']]);

        $porDia = collect($salida['bloques'])
            ->groupBy('dia')
            ->map(fn ($bloques) => $bloques->sum(
                fn (array $b) => (DisponibilidadDocente::aMinutos($b['hora_fin']) - DisponibilidadDocente::aMinutos($b['hora_inicio'])) / 60
            ));

        foreach ($porDia as $dia => $horas) {
            $this->assertLessThanOrEqual(2, $horas, "El día {$dia} pasa del tope diario.");
        }
    }

    // ── Andamiaje ──────────────────────────────────────────────────────────

    /** @param  array<int, array<string, mixed>>  $bloques */
    private function assertNoHayChoques(array $bloques): void
    {
        foreach ($bloques as $i => $a) {
            foreach (array_slice($bloques, $i + 1) as $b) {
                $seEnciman = $a['dia'] === $b['dia']
                    && DisponibilidadDocente::aMinutos($a['hora_inicio']) < DisponibilidadDocente::aMinutos($b['hora_fin'])
                    && DisponibilidadDocente::aMinutos($b['hora_inicio']) < DisponibilidadDocente::aMinutos($a['hora_fin']);

                $this->assertFalse($seEnciman, 'Dos bloques se enciman.');
            }
        }
    }

    /** @return array<string, int> */
    private function escuelaConMateria(int $horas): array
    {
        $escuela = $this->alumnoInscrito();
        $ciclo = $this->cicloDePrueba();

        $grupo = $this->fila('grupos', [
            'ciclo_id' => $ciclo,
            'campus_id' => $escuela['campus'],
            'plan_id' => $escuela['plan'],
            'clave' => 'G-'.uniqid(),
            'situacion_id' => $this->deCatalogo('situaciones_grupo'),
            'nivel_estudios_id' => $this->nivelDePrueba(),
            'semestre' => 1,
            'cupo' => 40,
        ]);

        [$asignaturaGrupo, $asignatura] = $this->materiaEnGrupo($grupo, $escuela['plan'], $horas);

        // Un aula, para que lo presencial tenga dónde caber.
        $this->fila('aulas', [
            'campus_id' => $escuela['campus'],
            'clave' => 'A-'.uniqid(),
            'nombre' => 'Aula de prueba',
            'capacidad' => 40,
        ]);

        return $escuela + [
            'ciclo' => $ciclo,
            'grupo' => $grupo,
            'asignaturaGrupo' => $asignaturaGrupo,
            'asignatura' => $asignatura,
        ];
    }

    /** @return array{0: int, 1: int} */
    private function materiaEnGrupo(int $grupo, int $plan, int $horas): array
    {
        $unico = uniqid();

        $asignatura = $this->fila('asignaturas', [
            'identificador' => "ASI-{$unico}",
            'clave' => "A-{$unico}",
            'nombre' => 'Materia de prueba',
            'creditos' => 8,
            'tipo_asignatura_id' => $this->deCatalogo('tipos_asignatura'),
            'horas_teoria' => $horas,
            'horas_practica' => 0,
        ]);

        $planMateria = $this->fila('plan_materias', [
            'plan_id' => $plan,
            'asignatura_id' => $asignatura,
            'clave_en_plan' => "PM-{$unico}",
            'periodo' => 1,
            'tipo' => 'obligatoria',
        ]);

        $asignaturaGrupo = $this->fila('asignatura_grupo', [
            'grupo_id' => $grupo,
            'plan_materia_id' => $planMateria,
            'situacion_id' => $this->deCatalogo('situaciones_asignatura_grupo'),
        ]);

        return [$asignaturaGrupo, $asignatura];
    }

    private function otraMateriaEnElGrupo(array $escuela, int $horas): int
    {
        [$asignaturaGrupo, $asignatura] = $this->materiaEnGrupo($escuela['grupo'], $escuela['plan'], $horas);

        // Se recuerda para que `docenteApto(todasLasMaterias: true)` la cubra.
        $this->otrasAsignaturas[] = $asignatura;
        $this->ultimaAsignatura = $asignatura;

        return $asignaturaGrupo;
    }

    /** @var int[] */
    private array $otrasAsignaturas = [];

    /** La asignatura de la última materia agregada con `otraMateriaEnElGrupo`. */
    private int $ultimaAsignatura = 0;

    private function aulaExtra(int $campus): void
    {
        $this->fila('aulas', [
            'campus_id' => $campus,
            'clave' => 'A-'.uniqid(),
            'nombre' => 'Aula extra',
            'capacidad' => 40,
        ]);
    }

    /** Un docente apto para UNA asignatura concreta, no la del escenario base. */
    private function docenteDe(int $asignaturaGrupo, int $asignatura, string $disponibleDe, string $a): int
    {
        $persona = $this->fila('personas', ['nombre' => 'Profe', 'primer_apellido' => 'Segundo']);

        $this->fila('docentes', [
            'persona_id' => $persona,
            'tipo_docente_id' => $this->deCatalogo('tipos_docente'),
            'situacion_id' => $this->deCatalogo('situaciones_docente'),
        ]);

        DB::table('asignatura_docente')->insert([
            'asignatura_id' => $asignatura,
            'persona_id' => $persona,
            'preferencia' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([1, 2, 3, 4, 5] as $dia) {
            DisponibilidadDocente::create([
                'persona_id' => $persona,
                'ciclo_id' => null,
                'dia_semana' => $dia,
                'hora_inicio' => $disponibleDe,
                'hora_fin' => $a,
                'modalidad' => DisponibilidadDocente::AMBAS,
            ]);
        }

        return $persona;
    }

    private function docenteApto(
        array $escuela,
        string $disponibleDe,
        string $a,
        array $dias = [1, 2, 3, 4, 5],
        string $modalidad = DisponibilidadDocente::AMBAS,
        bool $todasLasMaterias = false,
        int $preferencia = 0,
    ): int {
        $persona = $this->fila('personas', ['nombre' => 'Profe', 'primer_apellido' => 'De prueba']);

        $this->fila('docentes', [
            'persona_id' => $persona,
            'tipo_docente_id' => $this->deCatalogo('tipos_docente'),
            'situacion_id' => $this->deCatalogo('situaciones_docente'),
        ]);

        $asignaturas = $todasLasMaterias
            ? array_merge([$escuela['asignatura']], $this->otrasAsignaturas)
            : [$escuela['asignatura']];

        foreach (array_unique($asignaturas) as $asignatura) {
            DB::table('asignatura_docente')->insert([
                'asignatura_id' => $asignatura,
                'persona_id' => $persona,
                'preferencia' => $preferencia,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach ($dias as $dia) {
            DisponibilidadDocente::create([
                'persona_id' => $persona,
                'ciclo_id' => null,
                'dia_semana' => $dia,
                'hora_inicio' => $disponibleDe,
                'hora_fin' => $a,
                'modalidad' => $modalidad,
            ]);
        }

        return $persona;
    }

    private function regla(
        array $escuela,
        int $maxBloquesSesion = 3,
        ?int $maxHorasDia = null,
    ): ReglaHorario {
        return ReglaHorario::create([
            'nombre' => 'De prueba',
            'ciclo_id' => $escuela['ciclo'],
            'campus_id' => $escuela['campus'],
            'dias' => [1, 2, 3, 4, 5],
            'hora_apertura' => '07:00',
            'hora_cierre' => '21:00',
            'minutos_bloque' => 60,
            'bloques_min_por_sesion' => 1,
            'bloques_max_por_sesion' => $maxBloquesSesion,
            'max_sesiones_por_dia' => 1,
            'horas_max_dia_docente' => $maxHorasDia,
        ]);
    }
}
