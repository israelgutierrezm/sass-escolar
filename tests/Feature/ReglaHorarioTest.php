<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\AvisoParaElUsuario;
use App\Http\Controllers\ReglaHorarioController;
use App\Models\ControlEscolar\ReglaHorario;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreaEscuelaDePrueba;
use Tests\TenantTestCase;

/**
 * Configurar con qué criterios se arma un horario.
 *
 * Lo que se prueba aquí son las dos formas de dejar una regla inservible sin
 * que nada avise: una jornada donde no cabe ninguna clase, y dos reglas para el
 * mismo alcance. Las dos pasan cualquier validación de campo —las horas son
 * horas y los números son números— y sólo se descubren al generar, cuando el
 * motor no coloca nada y su motivo apunta a la disponibilidad de los docentes,
 * que no tiene nada que ver.
 */
class ReglaHorarioTest extends TenantTestCase
{
    use CreaEscuelaDePrueba;

    private ReglaHorarioController $controlador;

    protected function setUp(): void
    {
        parent::setUp();

        $this->controlador = app(ReglaHorarioController::class);
    }

    public function test_se_crea_una_regla(): void
    {
        $this->crear(['nombre' => 'Jornada matutina']);

        $this->assertSame(1, ReglaHorario::count());
        $this->assertSame('Jornada matutina', ReglaHorario::first()->nombre);
    }

    /**
     * Una jornada donde no cabe ni un bloque.
     *
     * De 7 a 8 con clases de 90 minutos: los datos son válidos uno por uno y el
     * resultado es cero huecos.
     */
    public function test_rechaza_una_jornada_donde_no_cabe_ninguna_clase(): void
    {
        $this->expectException(AvisoParaElUsuario::class);

        $this->crear([
            'hora_apertura' => '07:00',
            'hora_cierre' => '08:00',
            'minutos_bloque' => 90,
        ]);
    }

    /**
     * Y una donde no cabe la sesión MÁS LARGA que ella misma permite.
     *
     * Dos horas de jornada con sesiones de hasta tres bloques: cabe algo, pero
     * nunca lo que la regla dice preferir, y el generador acabaría partiendo
     * todo en pedazos mínimos sin explicar por qué.
     */
    public function test_rechaza_una_jornada_menor_que_su_sesion_mas_larga(): void
    {
        $this->expectException(AvisoParaElUsuario::class);

        $this->crear([
            'hora_apertura' => '07:00',
            'hora_cierre' => '09:00',
            'minutos_bloque' => 60,
            'bloques_max_por_sesion' => 3,
        ]);
    }

    /** La escuela no puede cerrar antes de abrir. */
    public function test_rechaza_una_jornada_invertida(): void
    {
        $this->expectException(ValidationException::class);

        $this->crear(['hora_apertura' => '15:00', 'hora_cierre' => '07:00']);
    }

    /**
     * Dos reglas para el mismo alcance.
     *
     * El índice único de la base ya lo impide, pero su error no le dice a nadie
     * qué hacer. Aquí se nombra la que ya existe.
     */
    public function test_rechaza_dos_reglas_para_el_mismo_alcance(): void
    {
        $this->crear(['nombre' => 'La primera']);

        $this->expectException(AvisoParaElUsuario::class);

        $this->crear(['nombre' => 'La segunda']);
    }

    /** Pero para alcances distintos sí conviven: es el punto de las excepciones. */
    public function test_permite_reglas_de_alcances_distintos(): void
    {
        $escuela = $this->alumnoInscrito();

        $this->crear(['nombre' => 'Base']);
        $this->crear(['nombre' => 'De un campus', 'campus_id' => $escuela['campus']]);
        $this->crear(['nombre' => 'De un ciclo', 'ciclo_id' => $this->cicloDePrueba()]);

        $this->assertSame(3, ReglaHorario::count());
    }

    /** Al editar, la propia regla no cuenta como duplicada de sí misma. */
    public function test_editar_una_regla_no_choca_consigo_misma(): void
    {
        $this->crear(['nombre' => 'La única']);
        $regla = ReglaHorario::first();

        $this->controlador->update($this->peticion(['nombre' => 'Renombrada']), $regla);

        $this->assertSame('Renombrada', $regla->fresh()->nombre);
    }

    // ── Andamiaje ──────────────────────────────────────────────────────────

    /** @param  array<string, mixed>  $cambios */
    private function crear(array $cambios = []): void
    {
        $this->controlador->store($this->peticion($cambios));
    }

    /** @param  array<string, mixed>  $cambios */
    private function peticion(array $cambios = []): Request
    {
        $peticion = Request::create('/escolar/reglas-horario', 'POST', array_merge([
            'nombre' => 'De prueba',
            'ciclo_id' => null,
            'campus_id' => null,
            'dias' => [1, 2, 3, 4, 5],
            'hora_apertura' => '07:00',
            'hora_cierre' => '15:00',
            'minutos_bloque' => 60,
            'bloques_min_por_sesion' => 1,
            'bloques_max_por_sesion' => 2,
            'max_sesiones_por_dia' => 1,
            'horas_max_dia_docente' => null,
            'horas_max_semana_docente' => null,
            'minutos_descanso_docente' => 0,
            'reparto' => ReglaHorario::REPARTIR,
            'permite_huecos_grupo' => false,
            'activa' => true,
        ], $cambios));

        $peticion->setUserResolver(fn () => $this->usuarioConAlcance());

        return $peticion;
    }
}
