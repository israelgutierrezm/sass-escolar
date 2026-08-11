<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Credencial\CredencialesDeLaPersona;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Identidad\CredencialRol;
use App\Models\Identidad\Rol;
use App\Models\Identidad\Usuario;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreaEscuelaDePrueba;
use Tests\TenantTestCase;

/**
 * Cuántas credenciales tiene una persona.
 *
 * ── La pregunta que originó esto ───────────────────────────────────────────
 * «Un alumno con dos carreras tendría dos credenciales». Sí, y es lo correcto:
 * el proyecto ya decidió que el alumno es la MATRÍCULA y no la persona, y por
 * eso su historial académico es independiente por inscripción. Emitir una sola —la de la
 * matrícula más reciente— dejaría sin credencial la otra carrera en la que esa
 * persona TAMBIÉN está inscrita, que es justo la que va a enseñar el día que
 * entre a esa clase. En la escuela de ejemplo hay tres personas así.
 *
 * Y al revés: quien no es alumno tiene una sola, porque no hay matrícula que
 * multiplique nada. La variante por nivel de estudios tampoco le aplica.
 */
class CredencialesDeLaPersonaTest extends TenantTestCase
{
    use CreaEscuelaDePrueba;

    public function test_un_alumno_con_dos_carreras_tiene_dos_credenciales(): void
    {
        $usuario = $this->alumnoCon(2);
        $this->configurar($usuario->rol_activo_id);

        $credenciales = $this->credenciales($usuario);

        $this->assertCount(2, $credenciales);
        $this->assertCount(
            2,
            $credenciales->pluck('clave')->unique(),
            'Cada una es distinta de la otra: se identifican por matrícula.',
        );
        $this->assertNotNull($credenciales[0]['matricula']);
    }

    /** Sin configuración, el rol no emite: no aparece una credencial a medias. */
    public function test_un_rol_sin_configurar_no_emite(): void
    {
        $this->assertCount(0, $this->credenciales($this->alumnoCon(1)));
    }

    /** Apagada tampoco, aunque esté configurada. */
    public function test_una_credencial_apagada_no_emite(): void
    {
        $usuario = $this->alumnoCon(1);
        $this->configurar($usuario->rol_activo_id, ['activa' => false]);

        $this->assertCount(0, $this->credenciales($usuario));
    }

    /**
     * Quien no es alumno tiene UNA, sin matrícula.
     *
     * Se comprueba con un rol administrativo porque es el caso que el cliente
     * señaló: docentes, padres, administradores y tutores no cursan nada.
     */
    public function test_quien_no_es_alumno_tiene_una_sola_y_sin_matricula(): void
    {
        $usuario = $this->usuarioConAlcance();
        $this->configurar($usuario->rol_activo_id);

        $credenciales = $this->credenciales($usuario);

        $this->assertCount(1, $credenciales);
        $this->assertNull($credenciales[0]['matricula']);
        $this->assertSame('rol', $credenciales[0]['clave']);
    }

    /**
     * La variante por nivel gana sobre la general, y la general es el respaldo.
     *
     * Es lo que hace opcional la variante: una escuela que no distinga niveles
     * configura una sola credencial y todos sus alumnos la reciben.
     */
    public function test_la_variante_de_nivel_gana_y_la_general_es_el_respaldo(): void
    {
        $usuario = $this->alumnoCon(1);
        $rolId = $usuario->rol_activo_id;
        $nivel = $this->nivelDeSuCarrera($usuario);

        $this->configurar($rolId, ['diseno' => 'clasico']);

        $this->assertSame('clasico', $this->credenciales($usuario)[0]['config']->diseno, 'Sin variante, cae a la general.');

        $this->configurar($rolId, ['diseno' => 'moderno', 'nivel_estudios_id' => $nivel]);

        $this->assertSame('moderno', $this->credenciales($usuario)[0]['config']->diseno, 'Con variante, gana la del nivel.');
    }

    // ── Andamiaje ──────────────────────────────────────────────────────────

    private function credenciales(Usuario $usuario)
    {
        return app(CredencialesDeLaPersona::class)->para($usuario->fresh())->values();
    }

    /** @param array<string, mixed> $cambios */
    private function configurar(int $rolId, array $cambios = []): CredencialRol
    {
        return CredencialRol::query()->create(array_merge([
            'rol_id' => $rolId,
            'nivel_estudios_id' => null,
            'activa' => true,
            'diseno' => 'clasico',
        ], $cambios));
    }

    /** Un usuario de la faceta alumno con tantas matrículas como se pidan. */
    private function alumnoCon(int $cuantas): Usuario
    {
        $primera = MatriculaOferta::findOrFail($this->alumnoInscrito()['matricula']);

        for ($i = 1; $i < $cuantas; $i++) {
            // Otra inscripción de la MISMA persona: es lo que hace multicarrera.
            $otra = MatriculaOferta::findOrFail($this->alumnoInscrito()['matricula']);
            $otra->update(['persona_id' => $primera->persona_id]);
        }

        $usuario = $this->usuarioConAlcance(rol: 'alumno');
        $usuario->persona_id = $primera->persona_id;
        $usuario->rol_activo_id = Rol::query()->where('name', 'alumno')->value('id');
        $usuario->save();

        return $usuario->fresh();
    }

    private function nivelDeSuCarrera(Usuario $usuario): int
    {
        $matricula = MatriculaOferta::query()
            ->with('oferta.carrera')
            ->where('persona_id', $usuario->persona_id)
            ->firstOrFail();

        $nivel = $matricula->oferta?->carrera?->nivel_estudios_id;

        if ($nivel === null) {
            // La escuela de prueba no siempre le pone nivel a la carrera; se le
            // pone uno para que la aserción compare algo real.
            $nivel = DB::table('niveles_estudio')->value('id')
                ?? DB::table('niveles_estudio')->insertGetId(['nombre' => 'Licenciatura']);

            DB::table('carreras')->where('id', $matricula->oferta->carrera_id)
                ->update(['nivel_estudios_id' => $nivel]);
        }

        return (int) $nivel;
    }
}
