<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Identidad\Persona;
use App\Models\Identidad\Rol;
use App\Models\Identidad\Usuario;
use App\Models\Plataforma\Aviso;
use App\Models\Plataforma\AvisoLectura;
use App\Services\Plataforma\AvisosDeUsuario;
use Tests\TenantTestCase;

/**
 * A quién le llega cada aviso, qué le interrumpe y qué queda por escrito.
 *
 * Son las tres cosas de las que depende que el módulo sirva: si la
 * segmentación falla, un aviso llega a quien no debía o no llega a quien sí; si
 * la constancia falla, la escuela no puede demostrar que avisó.
 */
class AvisosDeUsuarioTest extends TenantTestCase
{
    private AvisosDeUsuario $avisos;

    protected function setUp(): void
    {
        parent::setUp();

        $this->avisos = app(AvisosDeUsuario::class);
    }

    public function test_un_aviso_para_toda_la_escuela_le_llega_a_cualquiera(): void
    {
        $usuario = $this->usuarioCon('docente');
        $this->aviso('critico', [['tipo' => 'todos', 'destino_id' => null]]);

        $this->assertCount(1, $this->avisos->pendientes($usuario));
    }

    public function test_un_aviso_por_rol_solo_le_llega_a_quien_tiene_ese_rol(): void
    {
        $alumno = $this->usuarioCon('alumno');
        $docente = $this->usuarioCon('docente');

        $this->aviso('critico', [['tipo' => 'rol', 'destino_id' => $this->rol('alumno')->id]]);

        $this->assertCount(1, $this->avisos->pendientes($alumno));
        $this->assertCount(0, $this->avisos->pendientes($docente));
    }

    /**
     * Quien es docente y además coordinador recibe lo de ambos aunque en ese
     * momento esté operando como uno de los dos.
     */
    public function test_le_llega_lo_de_todos_sus_roles_y_no_solo_lo_del_activo(): void
    {
        $usuario = $this->usuarioCon('docente');
        $coordinador = $this->rol('coordinador');

        $usuario->persona->rolesActivos()->attach($coordinador->id, ['activo' => true]);
        $usuario->update(['rol_activo_id' => $this->rol('docente')->id]);

        $this->aviso('critico', [['tipo' => 'rol', 'destino_id' => $coordinador->id]]);

        $this->assertCount(1, $this->avisos->pendientes($usuario->fresh()));
    }

    public function test_un_aviso_en_borrador_no_le_llega_a_nadie(): void
    {
        $usuario = $this->usuarioCon('alumno');
        $this->aviso('critico', [['tipo' => 'todos', 'destino_id' => null]], ['publicado' => false]);

        $this->assertCount(0, $this->avisos->pendientes($usuario));
        $this->assertSame(0, $this->avisos->sinLeer($usuario));
    }

    public function test_uno_programado_o_caducado_no_esta_vigente(): void
    {
        $usuario = $this->usuarioCon('alumno');

        $this->aviso('critico', [['tipo' => 'todos', 'destino_id' => null]], ['publicado_desde' => now()->addDay()]);
        $this->aviso('critico', [['tipo' => 'todos', 'destino_id' => null]], ['vigente_hasta' => now()->subDay()]);

        $this->assertCount(0, $this->avisos->pendientes($usuario));
    }

    public function test_los_pendientes_no_incluyen_informativos(): void
    {
        $usuario = $this->usuarioCon('alumno');

        $this->aviso('informativo', [['tipo' => 'todos', 'destino_id' => null]]);
        $this->aviso('importante', [['tipo' => 'todos', 'destino_id' => null]]);

        $pendientes = $this->avisos->pendientes($usuario);

        $this->assertCount(1, $pendientes);
        $this->assertSame('importante', $pendientes[0]['prioridad']);
        // Pero sí están en su pantalla de avisos: ahí se ve todo lo vigente.
        $this->assertCount(2, $this->avisos->todos($usuario));
    }

    public function test_el_critico_se_presenta_antes_que_el_importante(): void
    {
        $usuario = $this->usuarioCon('alumno');

        $this->aviso('importante', [['tipo' => 'todos', 'destino_id' => null]]);
        $this->aviso('critico', [['tipo' => 'todos', 'destino_id' => null]]);

        $this->assertSame('critico', $this->avisos->pendientes($usuario)[0]['prioridad']);
    }

    public function test_entregar_un_aviso_deja_constancia_y_no_la_pisa_despues(): void
    {
        $usuario = $this->usuarioCon('alumno');
        $aviso = $this->aviso('critico', [['tipo' => 'todos', 'destino_id' => null]]);

        $this->avisos->pendientes($usuario);

        $primera = AvisoLectura::where('aviso_id', $aviso->id)->firstOrFail();
        $this->assertNotNull($primera->visto_en);
        $this->assertNull($primera->confirmado_en, 'Mostrar un aviso no es confirmarlo.');

        // `visto_en` es la PRIMERA vez que lo recibió: una segunda carga de
        // página no puede reescribir esa hora.
        $this->travel(5)->minutes();
        $this->avisos->pendientes($usuario);

        $this->assertEquals(
            $primera->visto_en->toDateTimeString(),
            $primera->fresh()->visto_en->toDateTimeString(),
        );
        $this->assertSame(1, AvisoLectura::where('aviso_id', $aviso->id)->count());
    }

    public function test_confirmar_lo_quita_de_pendientes_y_deja_la_hora(): void
    {
        $usuario = $this->usuarioCon('alumno');
        $aviso = $this->aviso('critico', [['tipo' => 'todos', 'destino_id' => null]]);

        $this->avisos->pendientes($usuario);
        $visto = AvisoLectura::where('aviso_id', $aviso->id)->firstOrFail()->visto_en;

        $this->travel(3)->minutes();
        $this->assertTrue($this->avisos->confirmar($usuario, $aviso));

        $lectura = AvisoLectura::where('aviso_id', $aviso->id)->firstOrFail();

        $this->assertNotNull($lectura->confirmado_en);
        // Sin pisar el visto: entre las dos horas está lo que tardó en leerlo.
        $this->assertEquals($visto->toDateTimeString(), $lectura->visto_en->toDateTimeString());
        $this->assertCount(0, $this->avisos->pendientes($usuario));
    }

    /** Nadie firma de recibido algo que no iba dirigido a él, aunque adivine el id. */
    public function test_no_se_puede_confirmar_un_aviso_ajeno(): void
    {
        $docente = $this->usuarioCon('docente');
        $aviso = $this->aviso('critico', [['tipo' => 'rol', 'destino_id' => $this->rol('alumno')->id]]);

        $this->assertFalse($this->avisos->confirmar($docente, $aviso));
        $this->assertSame(0, AvisoLectura::where('aviso_id', $aviso->id)->count());
    }

    /**
     * El contador: el informativo deja de contar al verse; el importante, sólo
     * al confirmarse. Si contara igual a los dos, posponer un importante sería
     * la forma de hacerlo desaparecer.
     */
    public function test_el_contador_cuenta_lo_que_falta_por_atender(): void
    {
        $usuario = $this->usuarioCon('alumno');

        $this->aviso('informativo', [['tipo' => 'todos', 'destino_id' => null]]);
        $importante = $this->aviso('importante', [['tipo' => 'todos', 'destino_id' => null]]);

        $this->assertSame(2, $this->avisos->sinLeer($usuario), 'Ninguno se ha puesto delante todavía.');

        // Abrir su pantalla se los muestra los dos.
        $this->avisos->todos($usuario);

        $this->assertSame(1, $this->avisos->sinLeer($usuario), 'El informativo ya se vio; el importante sigue sin confirmar.');

        $this->avisos->confirmar($usuario, $importante);

        $this->assertSame(0, $this->avisos->sinLeer($usuario));
    }

    public function test_una_cuenta_sin_persona_no_es_destinataria_de_nada(): void
    {
        $usuario = $this->usuarioCon('alumno');
        $usuario->persona_id = null;

        $this->aviso('critico', [['tipo' => 'todos', 'destino_id' => null]]);

        $this->assertCount(0, $this->avisos->pendientes($usuario));
        $this->assertSame(0, $this->avisos->sinLeer($usuario));
        $this->assertCount(0, $this->avisos->todos($usuario));
    }

    // ── Andamiaje ──────────────────────────────────────────────────────────

    /** @param  array<int, array{tipo: string, destino_id: int|null}>  $destinos */
    private function aviso(string $prioridad, array $destinos, array $extra = []): Aviso
    {
        $aviso = Aviso::create([
            'titulo' => "Aviso {$prioridad}",
            'cuerpo' => 'Texto del aviso.',
            'prioridad' => $prioridad,
            'publicado' => true,
            ...$extra,
        ]);

        foreach ($destinos as $destino) {
            $aviso->destinos()->create($destino);
        }

        return $aviso;
    }

    private function usuarioCon(string $claveRol): Usuario
    {
        $persona = Persona::create([
            'nombre' => 'Prueba',
            'primer_apellido' => ucfirst($claveRol),
        ]);

        $rol = $this->rol($claveRol);

        $persona->rolesActivos()->attach($rol->id, ['activo' => true]);

        return Usuario::create([
            'persona_id' => $persona->id,
            'usuario' => $claveRol.'.'.$persona->id,
            'email' => "{$claveRol}.{$persona->id}@escuela.test",
            'password' => 'secreto',
            'rol_activo_id' => $rol->id,
        ]);
    }

    private function rol(string $clave): Rol
    {
        return Rol::firstOrCreate(
            ['name' => $clave, 'guard_name' => 'web'],
            ['nombre' => ucfirst($clave)],
        );
    }
}
