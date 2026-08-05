<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\AsignaturaGrupoController;
use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\ControlEscolar\Grupo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Tests\Concerns\CreaEscuelaDePrueba;
use Tests\TenantTestCase;

/**
 * El mismo docente a varias materias de un tirón.
 *
 * Abrir un grupo son diez o doce materias, y el aviso «11 sin docente — nadie
 * podría firmar esas actas» se resolvía con once diálogos idénticos: elegir al
 * mismo profesor once veces. Al empezar un ciclo, eso se multiplica por todos
 * los grupos de la escuela.
 */
class AsignacionDocenteEnLoteTest extends TenantTestCase
{
    use CreaEscuelaDePrueba;

    private AsignaturaGrupoController $controlador;

    protected function setUp(): void
    {
        parent::setUp();

        $this->controlador = app(AsignaturaGrupoController::class);

        // `back()` y los mensajes flash necesitan sesión.
        Session::start();
    }

    public function test_asigna_el_mismo_docente_a_varias_materias(): void
    {
        $escuela = $this->escuelaConMaterias(3);
        $docente = $this->docente();

        $this->asignar($escuela['grupo'], $docente, $escuela['materias']);

        foreach ($escuela['materias'] as $materiaId) {
            $this->assertTrue(
                AsignaturaGrupo::find($materiaId)->docentes()->where('docentes.persona_id', $docente)->exists(),
                "La materia {$materiaId} debería haber quedado asignada.",
            );
        }
    }

    /**
     * Un titular por materia: es quien firma el acta.
     *
     * La que ya lo tiene se omite en vez de hacer fallar toda la operación.
     * Abortar dejaría a medias una asignación de doce por culpa de una, y
     * obligar a deseleccionarla a mano devuelve el trabajo que este panel quita.
     */
    public function test_omite_las_que_ya_tienen_otro_titular_sin_abortar(): void
    {
        $escuela = $this->escuelaConMaterias(3);
        $ocupada = $escuela['materias'][0];
        $otro = $this->docente();
        $nuevo = $this->docente();

        AsignaturaGrupo::find($ocupada)->docentes()->attach($otro, ['tipo' => 'titular']);

        $this->asignar($escuela['grupo'], $nuevo, $escuela['materias']);

        $this->assertFalse(
            AsignaturaGrupo::find($ocupada)->docentes()->where('docentes.persona_id', $nuevo)->exists(),
            'La que ya tenía titular no se toca.',
        );
        $this->assertTrue(
            AsignaturaGrupo::find($escuela['materias'][1])->docentes()->where('docentes.persona_id', $nuevo)->exists(),
            'Las demás sí se asignan: una ocupada no cancela el lote.',
        );
    }

    /** Como adjunto sí entra donde ya hay titular: acompaña, no firma. */
    public function test_el_adjunto_entra_aunque_haya_titular(): void
    {
        $escuela = $this->escuelaConMaterias(1);
        $titular = $this->docente();
        $adjunto = $this->docente();

        AsignaturaGrupo::find($escuela['materias'][0])->docentes()->attach($titular, ['tipo' => 'titular']);

        $this->asignar($escuela['grupo'], $adjunto, $escuela['materias'], 'adjunto');

        $this->assertTrue(
            AsignaturaGrupo::find($escuela['materias'][0])->docentes()->where('docentes.persona_id', $adjunto)->exists(),
        );
    }

    /** Los ids llegan del cliente: una materia de otro grupo no se toca. */
    public function test_no_alcanza_materias_de_otro_grupo(): void
    {
        $mio = $this->escuelaConMaterias(1);
        $ajeno = $this->escuelaConMaterias(1);
        $docente = $this->docente();

        $this->asignar($mio['grupo'], $docente, [...$mio['materias'], ...$ajeno['materias']]);

        $this->assertFalse(
            AsignaturaGrupo::find($ajeno['materias'][0])->docentes()->where('docentes.persona_id', $docente)->exists(),
        );
    }

    // ── Andamiaje ──────────────────────────────────────────────────────────

    /** @param  array<int, int>  $materias */
    private function asignar(int $grupo, int $docente, array $materias, string $tipo = 'titular'): void
    {
        $peticion = Request::create("/escolar/grupos/{$grupo}/docentes-en-lote", 'POST', [
            'persona_id' => $docente,
            'tipo' => $tipo,
            'asignatura_ids' => $materias,
        ]);

        $this->controlador->asignarDocenteEnLote($peticion, Grupo::findOrFail($grupo));
    }

    /** @return array{grupo: int, materias: array<int, int>} */
    private function escuelaConMaterias(int $cuantas): array
    {
        $escuela = $this->alumnoInscrito();
        $ciclo = $this->cicloDePrueba();
        $materias = [];

        foreach (range(1, $cuantas) as $i) {
            $abierta = $this->materiaAbierta($escuela['plan'], $escuela['campus'], $ciclo);
            $materias[] = $abierta['materia'];
            $grupo = $abierta['grupo'];
        }

        // `materiaAbierta` crea un grupo por materia; se juntan todas en el
        // primero, que es lo que pasa en la realidad: un grupo con sus doce.
        AsignaturaGrupo::whereIn('id', $materias)->update(['grupo_id' => $grupo]);

        return ['grupo' => $grupo, 'materias' => $materias];
    }

    private function docente(): int
    {
        $persona = $this->fila('personas', ['nombre' => 'Docente', 'primer_apellido' => 'De prueba']);

        DB::table('docentes')->insert([
            'persona_id' => $persona,
            'situacion_id' => $this->deCatalogo('situaciones_docente'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $persona;
    }
}
