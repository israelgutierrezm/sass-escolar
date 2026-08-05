<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ControlEscolar\Ciclo;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreaEscuelaDePrueba;
use Tests\TenantTestCase;

/**
 * Con qué ciclos se trabaja y cuál se ofrece primero.
 *
 * Una escuela con años de historia acumula veinte ciclos y sólo uno o dos están
 * vivos. Ofrecerlos todos convertía cada tarea diaria —capturar, inscribir,
 * asignar tutorías— en elegir entre veintiuna opciones donde sólo una tiene
 * sentido, con «2016-1» a un clic de la que se buscaba.
 */
class CiclosVigentesTest extends TenantTestCase
{
    use CreaEscuelaDePrueba;

    protected function setUp(): void
    {
        parent::setUp();

        // «El ciclo en curso» es una respuesta única, así que la base tiene que
        // partir vacía: un ciclo que dejó otra prueba la contestaría por ésta.
        // Se deshace con la transacción al terminar.
        DB::table('ciclos')->delete();
    }

    public function test_los_cerrados_no_se_ofrecen(): void
    {
        $vivo = $this->ciclo('VIVO', '2026-08-01', '2026-12-15');
        $viejo = $this->ciclo('VIEJO', '2016-01-01', '2016-06-30', 'cerrado');

        $ofrecidos = Ciclo::query()->vigentes()->pluck('id');

        $this->assertTrue($ofrecidos->contains($vivo));
        $this->assertFalse($ofrecidos->contains($viejo));
    }

    /**
     * En una pantalla de edición, el ciclo del registro tiene que seguir
     * apareciendo aunque esté cerrado: si desaparece del desplegable, guardar
     * mueve el registro a otro ciclo y le falsea la historia.
     */
    public function test_el_que_se_esta_editando_se_conserva(): void
    {
        $viejo = $this->ciclo('VIEJO', '2016-01-01', '2016-06-30', 'cerrado');

        $this->assertTrue(Ciclo::query()->vigentes($viejo)->pluck('id')->contains($viejo));
    }

    /** El que contiene el día de hoy: es en el que la escuela está trabajando. */
    public function test_el_ciclo_en_curso_es_el_de_hoy(): void
    {
        $this->ciclo('PASADO', '2020-01-01', '2020-06-30', 'cerrado');
        $hoy = $this->ciclo('HOY', now()->subMonth()->toDateString(), now()->addMonths(3)->toDateString());
        $this->ciclo('FUTURO', now()->addYear()->toDateString(), now()->addYear()->addMonths(4)->toDateString());

        $this->assertSame($hoy, Ciclo::enCurso()?->id);
    }

    /**
     * Entre semestres —vacaciones— no hay ninguno corriendo. Se ofrece el que
     * está por empezar, que es lo que se va a preparar.
     */
    public function test_entre_ciclos_se_toma_el_proximo(): void
    {
        $this->ciclo('TERMINADO', '2026-01-01', '2026-06-30', 'cerrado');
        $proximo = $this->ciclo('PROXIMO', now()->addMonth()->toDateString(), now()->addMonths(5)->toDateString());

        $this->assertSame($proximo, Ciclo::enCurso()?->id);
    }

    /**
     * La situación la mueve una persona a mano y se queda como quedó: en `demo`
     * hay veinte «cerrados» y uno «abierto» que ya terminó. Por eso el ciclo en
     * curso se busca por fecha; la situación sólo decide si se sigue ofreciendo.
     */
    public function test_un_cerrado_nunca_es_el_ciclo_en_curso(): void
    {
        $this->ciclo('CERRADO-PERO-VIGENTE', now()->subMonth()->toDateString(), now()->addMonth()->toDateString(), 'cerrado');
        $abierto = $this->ciclo('ABIERTO', now()->subWeek()->toDateString(), now()->addMonths(2)->toDateString());

        $this->assertSame($abierto, Ciclo::enCurso()?->id);
    }

    // ── Andamiaje ──────────────────────────────────────────────────────────

    private function ciclo(string $clave, string $inicio, string $fin, string $situacion = 'abierto'): int
    {
        return $this->fila('ciclos', [
            'clave' => $clave.'-'.uniqid(),
            'nombre' => 'Ciclo '.$clave,
            'fecha_inicio' => $inicio,
            'fecha_fin' => $fin,
            'situacion_id' => $this->situacionCon('situaciones_ciclo', $situacion),
        ]);
    }
}
