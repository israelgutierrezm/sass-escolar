<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\AspiranteController;
use App\Models\Admisiones\Aspirante;
use App\Models\Identidad\Persona;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreaEscuelaDePrueba;
use Tests\TenantTestCase;

/**
 * Sacar a alguien del embudo de admisión.
 *
 * El listado no tenía cómo: un prospecto capturado dos veces, o el que dejó un
 * teléfono falso, se quedaba ahí para siempre inflando el conteo. Se agregó el
 * borrado, con una condición que no se puede saltar: en cuanto el aspirante se
 * convierte en alumno, su matrícula cuelga de ese registro y borrarlo dejaría al
 * alumno sin de dónde vino.
 */
class EliminarAspiranteTest extends TenantTestCase
{
    use CreaEscuelaDePrueba;

    public function test_un_prospecto_se_saca_del_embudo(): void
    {
        $aspirante = $this->aspirante();

        $this->eliminar($aspirante);

        $this->assertNull(Aspirante::find($aspirante->id), 'Debió salir del embudo.');
    }

    /**
     * El caso que justifica la condición: ya es alumno. Su matrícula apunta a la
     * misma persona, y el expediente de admisión es lo único que dice cómo
     * llegó —de qué campaña, con qué asesor—.
     */
    public function test_uno_que_ya_es_alumno_no_se_elimina(): void
    {
        $escuela = $this->alumnoInscrito();

        $aspirante = Aspirante::create([
            'persona_id' => DB::table('matricula_oferta')->where('id', $escuela['matricula'])->value('persona_id'),
            'situacion_id' => $this->deCatalogo('situaciones_aspirante'),
        ]);

        $respuesta = $this->eliminar($aspirante);

        $this->assertNotNull(Aspirante::find($aspirante->id), 'Sigue ahí: tiene matrícula.');
        $this->assertSame(
            'No se puede eliminar: ya se convirtió en alumno y su matrícula cuelga de este registro.',
            $respuesta->getSession()?->get('error'),
        );
    }

    // ── Andamiaje ──────────────────────────────────────────────────────────

    private function eliminar(Aspirante $aspirante): RedirectResponse
    {
        $peticion = $this->peticionDe($this->usuarioConAlcance(), '/aspirantes');

        return app(AspiranteController::class)->destroy($peticion, $aspirante);
    }

    private function aspirante(): Aspirante
    {
        $persona = Persona::create(['nombre' => 'Prospecto', 'primer_apellido' => 'De prueba']);

        return Aspirante::create([
            'persona_id' => $persona->id,
            'situacion_id' => $this->deCatalogo('situaciones_aspirante'),
        ]);
    }
}
