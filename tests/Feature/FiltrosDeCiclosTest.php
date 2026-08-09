<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\CicloController;
use App\Models\ControlEscolar\Ciclo;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreaEscuelaDePrueba;
use Tests\TenantTestCase;

/**
 * Los filtros del listado de ciclos.
 *
 * ── Lo que hay que cuidar aquí ─────────────────────────────────────────────
 * Un ciclo SIN campus es global —vale para toda la escuela— y uno SIN niveles
 * vale para cualquiera. Un filtro ingenuo por campus los dejaría fuera, que es
 * exactamente al revés de lo que espera quien filtra: escondería los ciclos que
 * SÍ aplican a esa sede.
 *
 * Y «con inscripción abierta» se resuelve en SQL, no en PHP: filtrar después de
 * paginar daría páginas de tamaño irregular y un total que no corresponde a lo
 * que se ve en pantalla.
 */
class FiltrosDeCiclosTest extends TenantTestCase
{
    use CreaEscuelaDePrueba;

    public function test_filtra_por_situacion(): void
    {
        $abierta = $this->situacionCon('situaciones_ciclo', 'abierto');
        $otra = DB::table('situaciones_ciclo')->where('id', '!=', $abierta)->value('id') ?? $abierta;

        $this->ciclo('CON-SIT', ['situacion_id' => $abierta]);
        $this->ciclo('OTRA-SIT', ['situacion_id' => $otra]);

        $claves = $this->claves(['situacion' => $abierta]);

        $this->assertContains('CON-SIT', $claves);
        if ($otra !== $abierta) {
            $this->assertNotContains('OTRA-SIT', $claves);
        }
    }

    /**
     * Al filtrar por campus salen los de ese campus Y los globales.
     *
     * Es el caso que un filtro escrito de corrido se come: el ciclo global es
     * el que más aplica a esa sede, y desaparecería.
     */
    public function test_el_filtro_por_campus_conserva_los_globales(): void
    {
        $escuela = $this->alumnoInscrito();
        $otro = $this->otroCampus();

        $delCampus = $this->ciclo('DEL-CAMPUS');
        $delCampus->campus()->sync([$escuela['campus']]);

        $ajeno = $this->ciclo('AJENO');
        $ajeno->campus()->sync([$otro]);

        $this->ciclo('GLOBAL'); // sin campus

        $claves = $this->claves(['campus' => $escuela['campus']]);

        $this->assertContains('DEL-CAMPUS', $claves);
        $this->assertContains('GLOBAL', $claves, 'Un ciclo global aplica a todos los campus.');
        $this->assertNotContains('AJENO', $claves);
    }

    /** Mismo criterio con los niveles: sin niveles = cualquier nivel. */
    public function test_el_filtro_por_nivel_conserva_los_de_cualquier_nivel(): void
    {
        $nivel = $this->nivelDePrueba();

        $delNivel = $this->ciclo('DEL-NIVEL');
        $delNivel->niveles()->sync([$nivel]);

        $this->ciclo('CUALQUIERA'); // sin niveles

        $claves = $this->claves(['nivel' => $nivel]);

        $this->assertContains('DEL-NIVEL', $claves);
        $this->assertContains('CUALQUIERA', $claves);
    }

    /**
     * «Con inscripción abierta» deja fuera al que ya cerró.
     *
     * Y conserva al que no tiene fechas capturadas: sin ventana configurada no
     * hay restricción, así que está abierto.
     */
    public function test_filtra_los_de_inscripcion_abierta(): void
    {
        $this->ciclo('SIN-VENTANA');
        $this->ciclo('ABIERTO', [
            'inscripcion_desde' => now()->subDays(5)->toDateString(),
            'inscripcion_hasta' => now()->addDays(5)->toDateString(),
        ]);
        $this->ciclo('CERRADO', [
            'inscripcion_desde' => now()->subDays(30)->toDateString(),
            'inscripcion_hasta' => now()->subDays(10)->toDateString(),
        ]);

        $claves = $this->claves(['abiertos' => '1']);

        $this->assertContains('SIN-VENTANA', $claves, 'Sin fechas no hay restricción.');
        $this->assertContains('ABIERTO', $claves);
        $this->assertNotContains('CERRADO', $claves);
    }

    /** Sin filtros no se esconde nada. */
    public function test_sin_filtros_salen_todos(): void
    {
        $this->ciclo('UNO');
        $this->ciclo('DOS', ['inscripcion_desde' => now()->subDays(30)->toDateString(), 'inscripcion_hasta' => now()->subDays(10)->toDateString()]);

        $claves = $this->claves([]);

        $this->assertContains('UNO', $claves);
        $this->assertContains('DOS', $claves);
    }

    /** Los desplegables se llenan con los catálogos de la escuela. */
    public function test_el_listado_manda_las_opciones_de_los_filtros(): void
    {
        // Un ciclo cualquiera deja sembrados el campus, el nivel y la situación
        // que los desplegables van a listar.
        $this->alumnoInscrito();
        $this->ciclo('PARA-CATALOGOS');

        $opciones = $this->props([])['opciones'];

        $this->assertNotEmpty($opciones['situaciones']);
        $this->assertNotEmpty($opciones['campus']);
        $this->assertNotEmpty($opciones['niveles']);
    }

    // ── Andamiaje ──────────────────────────────────────────────────────────

    private function ciclo(string $clave, array $extra = []): Ciclo
    {
        return Ciclo::create([
            'clave' => $clave.'-'.uniqid(),
            'nombre' => $clave,
            'anio' => 2026,
            'numero_periodo' => 1,
            'situacion_id' => $extra['situacion_id'] ?? $this->deCatalogo('situaciones_ciclo'),
            'fecha_inicio' => now()->subMonth()->toDateString(),
            'fecha_fin' => now()->addMonths(4)->toDateString(),
            ...array_diff_key($extra, ['situacion_id' => null]),
        ]);
    }

    private function otroCampus(): int
    {
        $unico = uniqid();
        $institucion = $this->fila('instituciones', ['clave' => "INS2-{$unico}", 'nombre' => 'Otra institución']);

        return $this->fila('campus', [
            'clave' => "CAM2-{$unico}",
            'nombre' => 'Otro campus',
            'institucion_id' => $institucion,
        ]);
    }

    /** @return array<string, mixed> */
    private function props(array $filtros): array
    {
        // Sin campus en el rol: alcance global, para que el filtro sea lo único
        // que acota y no el alcance.
        $usuario = $this->usuarioConAlcance();
        $peticion = $this->peticionDe($usuario, '/escolar/ciclos', $filtros);

        return $this->propsDe(app(CicloController::class)->index($peticion), $peticion);
    }

    /** @return array<int, string> */
    private function claves(array $filtros): array
    {
        return collect($this->props($filtros)['ciclos']['data'])->pluck('nombre')->all();
    }
}
