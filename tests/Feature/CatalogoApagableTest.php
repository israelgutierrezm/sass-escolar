<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\CatalogoAcademicoController;
use App\Models\Academico\NivelEstudio;
use App\Models\Academico\TipoPeriodo;
use App\Models\Academico\Turno;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Concerns\CreaEscuelaDePrueba;
use Tests\TenantTestCase;

/**
 * Encender y apagar niveles de estudio y tipos de periodo.
 *
 * ── La regla que sostiene todo lo demás ────────────────────────────────────
 * Sólo se apaga lo que NADIE usa. Es lo que hace seguro que los catorce
 * desplegables del sistema filtren por encendido: si nada apunta a ese nivel,
 * quitarlo de las listas no puede dejar huérfano un dato ya guardado.
 *
 * Y el «nadie usa» tiene que mirar las OCHO tablas que lo referencian, dos de
 * ellas sin llave foránea —`evento_destinos` y `emisor_asignaciones` apuntan a
 * catálogos distintos según su tipo—. Preguntar sólo por `carreras`, que es lo
 * que había, dejaría apagar el nivel que sostiene un aviso del calendario.
 */
class CatalogoApagableTest extends TenantTestCase
{
    use CreaEscuelaDePrueba;

    public function test_se_apaga_lo_que_nadie_usa(): void
    {
        $nivel = $this->nivelLibre();

        $this->alternar('nivel', $nivel->id, false);

        $this->assertFalse($nivel->fresh()->activo);
        $this->assertFalse(
            NivelEstudio::activos()->whereKey($nivel->id)->exists(),
            'Sigue saliendo en el ámbito que alimenta los desplegables.',
        );
    }

    /** Apagado y vuelto a encender: encender nunca se bloquea. */
    public function test_se_puede_volver_a_encender(): void
    {
        $nivel = $this->nivelLibre();

        $this->alternar('nivel', $nivel->id, false);
        $this->alternar('nivel', $nivel->id, true);

        $this->assertTrue($nivel->fresh()->activo);
    }

    public function test_no_se_apaga_un_nivel_que_usa_una_carrera(): void
    {
        $escuela = $this->alumnoInscrito();
        $nivel = $this->nivelLibre();

        DB::table('carreras')->where('id', $escuela['carrera'])->update(['nivel_estudios_id' => $nivel->id]);

        $this->expectException(ValidationException::class);

        $this->alternar('nivel', $nivel->id, false);
    }

    /**
     * Tampoco si sostiene un aviso del calendario.
     *
     * `evento_destinos` no tiene foránea —apunta a tablas distintas según su
     * `tipo`—, así que este caso sólo se detecta preguntándole a mano. Sin esta
     * comprobación, apagar dejaría el aviso dirigido a un nivel que ya no se
     * ofrece en ninguna parte.
     */
    public function test_no_se_apaga_un_nivel_que_sostiene_un_aviso(): void
    {
        $nivel = $this->nivelLibre();

        $evento = DB::table('eventos_calendario')->insertGetId([
            'titulo' => 'Aviso de prueba',
            'tipo' => 'aviso',
            'inicia_en' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('evento_destinos')->insert([
            'evento_id' => $evento,
            'tipo' => 'nivel',
            'destino_id' => $nivel->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(ValidationException::class);

        $this->alternar('nivel', $nivel->id, false);
    }

    /** Lo mismo para el tipo de periodo, que lo sostiene el plan de estudios. */
    public function test_no_se_apaga_un_tipo_de_periodo_que_usa_un_plan(): void
    {
        $escuela = $this->alumnoInscrito();

        $tipo = TipoPeriodo::query()->create([
            'clave' => 'tp-'.uniqid(),
            'identificador' => '999',
            'nombre' => 'Periodo de prueba',
        ]);

        DB::table('planes_estudio')->where('id', $escuela['plan'])->update(['tipo_periodo_id' => $tipo->id]);

        $this->expectException(ValidationException::class);

        $this->alternar('tipoperiodo', $tipo->id, false);
    }

    /**
     * Un catálogo que NO declara `apagable` no tiene interruptor.
     *
     * Se comprueba porque la ruta es genérica: sin esta puerta, un PATCH a mano
     * escribiría una columna `activo` que en esas tablas ni siquiera existe.
     */
    public function test_un_catalogo_que_no_se_apaga_responde_404(): void
    {
        // Se crea uno en vez de buscarlo: la escuela de prueba no siempre
        // siembra turnos, y una prueba que se salta sola no comprueba nada.
        $turno = Turno::query()->create(['clave' => 'turno-'.uniqid(), 'nombre' => 'Turno de prueba']);

        $this->expectException(NotFoundHttpException::class);

        $this->alternar('turno', $turno->id, false);
    }

    // ── Andamiaje ──────────────────────────────────────────────────────────

    private function alternar(string $catalogo, int $id, bool $activo): void
    {
        $peticion = Request::create("/academico/catalogos/{$catalogo}/{$id}/activo", 'PATCH', ['activo' => $activo]);
        $peticion->setUserResolver(fn () => $this->usuarioConAlcance());

        app(CatalogoAcademicoController::class)->alternar($peticion, $catalogo, $id);
    }

    /** Un nivel recién creado, que por definición no usa nadie. */
    private function nivelLibre(): NivelEstudio
    {
        return NivelEstudio::query()->create([
            'clave' => 'nivel-'.uniqid(),
            'identificador' => '998',
            'nombre' => 'Nivel de prueba',
            'orden' => 99,
        ]);
    }
}
