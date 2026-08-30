<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\ReglaMatriculaController;
use App\Models\Admisiones\ReglaMatricula;
use Illuminate\Http\Request;
use Tests\Concerns\CreaEscuelaDePrueba;
use Tests\TenantTestCase;

/**
 * La pantalla que configura el formato de matrícula.
 *
 * Lo que se prueba aquí no es el formato —de eso va `GeneradorMatriculaTest`—
 * sino que la pantalla no deje la configuración en un estado imposible: es la
 * que decide con qué número sale cada alumno de la escuela.
 */
class ReglasMatriculaPantallaTest extends TenantTestCase
{
    use CreaEscuelaDePrueba;

    /**
     * Regresión: crear una regla para un alcance que ya tuvo una borrada.
     *
     * El borrado es lógico, pero el índice único de (ambito, ambito_id) no
     * distingue: la fila en la papelera seguía ocupando el sitio y el alta
     * moría con un 1062 que la pantalla mostraba como un 500. Desde la interfaz
     * era incomprensible, porque la regla vieja no se ve por ningún lado.
     */
    public function test_se_puede_volver_a_crear_una_regla_que_se_borro(): void
    {
        $programaAcademico = $this->alumnoInscrito()['programa_academico'];

        $primera = $this->crear($programaAcademico, 'A{###}');
        app(ReglaMatriculaController::class)->destroy($primera);

        $segunda = $this->crear($programaAcademico, 'B{###}');

        $this->assertSame('B{###}', $segunda->plantilla);
        $this->assertSame($primera->id, $segunda->id, 'Se revive la misma fila, no se crea otra.');
    }

    /** Dos reglas vivas para el mismo alcance no tendrían desempate. */
    public function test_no_se_pueden_tener_dos_reglas_para_el_mismo_alcance(): void
    {
        $programaAcademico = $this->alumnoInscrito()['programa_academico'];

        $this->crear($programaAcademico, 'A{###}');

        $this->expectExceptionMessage('Ya hay una regla para ese alcance');
        $this->crear($programaAcademico, 'B{###}');
    }

    /** Sin consecutivo, todos los alumnos del año saldrían con el mismo número. */
    public function test_una_plantilla_sin_consecutivo_se_rechaza(): void
    {
        $programaAcademico = $this->alumnoInscrito()['programa_academico'];

        $this->expectExceptionMessage('necesita un consecutivo');
        $this->crear($programaAcademico, '{AAAA}-SIN-NUMERO');
    }

    /**
     * La global no se elimina: es la que aplica a todo lo que no tiene una
     * propia, y sin ella convertir a cualquier aspirante revienta.
     */
    public function test_la_regla_global_no_se_elimina(): void
    {
        $global = ReglaMatricula::create([
            'ambito' => 'global',
            'plantilla' => 'G{###}',
            'consecutivo_dimensiones' => [],
            'consecutivo_reinicia' => 'anio',
            'activo' => true,
        ]);

        app(ReglaMatriculaController::class)->destroy($global);

        $this->assertNotNull(ReglaMatricula::find($global->id));
    }

    // ── Andamiaje ──────────────────────────────────────────────────────────

    private function crear(int $programaAcademico, string $plantilla): ReglaMatricula
    {
        $peticion = Request::create('/admisiones/reglas-matricula', 'POST', [
            'ambito' => 'programa_academico',
            'ambito_id' => $programaAcademico,
            'plantilla' => $plantilla,
            'consecutivo_dimensiones' => ['campus', 'programa_academico'],
            'consecutivo_reinicia' => 'ciclo',
            'activo' => true,
        ]);

        app(ReglaMatriculaController::class)->store($peticion);

        return ReglaMatricula::where('ambito', 'programa_academico')->where('ambito_id', $programaAcademico)->firstOrFail();
    }
}
