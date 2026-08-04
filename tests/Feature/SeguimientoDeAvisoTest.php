<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Identidad\Persona;
use App\Models\Identidad\Rol;
use App\Models\Identidad\Usuario;
use App\Models\Plataforma\Aviso;
use App\Models\Plataforma\AvisoLectura;
use App\Services\Plataforma\AvisosDeUsuario;
use App\Services\Plataforma\DestinatariosDeAviso;
use App\Services\Plataforma\SeguimientoDeAviso;
use Tests\TenantTestCase;

/**
 * A cuánta gente alcanza un aviso y cómo va.
 *
 * Lo que se juega aquí es el denominador: «lo confirmaron doce» no dice nada
 * sin saber doce de cuántos, y si el alcance está mal, todos los porcentajes de
 * la pantalla mienten a la vez.
 */
class SeguimientoDeAvisoTest extends TenantTestCase
{
    private DestinatariosDeAviso $destinatarios;

    private SeguimientoDeAviso $seguimiento;

    protected function setUp(): void
    {
        parent::setUp();

        $this->destinatarios = app(DestinatariosDeAviso::class);
        $this->seguimiento = app(SeguimientoDeAviso::class);
    }

    public function test_un_aviso_por_rol_alcanza_a_los_de_ese_rol(): void
    {
        $alumnos = [$this->persona('alumno'), $this->persona('alumno')];
        $this->persona('docente');

        $aviso = $this->aviso('importante', [['tipo' => 'rol', 'destino_id' => $this->rol('alumno')->id]]);

        $alcance = $this->destinatarios->de($aviso);

        $this->assertCount(2, $alcance);
        $this->assertEqualsCanonicalizing($alumnos, $alcance);
    }

    /**
     * Un egresado conserva matrícula y seguiría cayendo en «los del plan 2020»,
     * pero ya no entra al sistema. Contarlo haría que ningún aviso llegara
     * nunca al 100% y el porcentaje dejaría de significar algo.
     */
    public function test_quien_no_tiene_rol_activo_no_es_destinatario(): void
    {
        $activo = $this->persona('alumno');
        $baja = $this->persona('alumno', activo: false);

        $aviso = $this->aviso('importante', [['tipo' => 'todos', 'destino_id' => null]]);

        $alcance = $this->destinatarios->de($aviso);

        $this->assertContains($activo, $alcance);
        $this->assertNotContains($baja, $alcance);
    }

    public function test_los_destinos_se_suman_sin_contar_dos_veces_a_nadie(): void
    {
        // Con los dos roles: cae por «rol alumno» y por «rol docente».
        $ambos = $this->persona('alumno');
        Persona::find($ambos)->rolesActivos()->attach($this->rol('docente')->id, ['activo' => true]);

        $aviso = $this->aviso('importante', [
            ['tipo' => 'rol', 'destino_id' => $this->rol('alumno')->id],
            ['tipo' => 'rol', 'destino_id' => $this->rol('docente')->id],
        ]);

        $this->assertSame([$ambos], $this->destinatarios->de($aviso));
    }

    /**
     * La prueba que impide que las dos direcciones diverjan.
     *
     * `DestinatariosDeAviso` contesta «¿quiénes son ellos?» y `AlcanceDeDestinos`
     * «¿este aviso es para mí?». Son la misma pertenencia mirada desde lados
     * opuestos: si un día una cambia sin la otra, esto lo dice.
     */
    public function test_quien_aparece_en_el_alcance_recibe_el_aviso(): void
    {
        $personaId = $this->persona('docente');
        $usuario = $this->usuarioDe($personaId);

        $aviso = $this->aviso('critico', [['tipo' => 'rol', 'destino_id' => $this->rol('docente')->id]]);

        $this->assertContains($personaId, $this->destinatarios->de($aviso));
        $this->assertCount(1, app(AvisosDeUsuario::class)->pendientes($usuario));
    }

    public function test_las_cifras_cuadran_con_lo_que_ha_pasado(): void
    {
        $personas = [$this->persona('alumno'), $this->persona('alumno'), $this->persona('alumno')];
        $aviso = $this->aviso('critico', [['tipo' => 'rol', 'destino_id' => $this->rol('alumno')->id]]);

        // Dos lo vieron; uno de ellos confirmó.
        AvisoLectura::create(['aviso_id' => $aviso->id, 'persona_id' => $personas[0], 'visto_en' => now()->subMinutes(30), 'confirmado_en' => now()]);
        AvisoLectura::create(['aviso_id' => $aviso->id, 'persona_id' => $personas[1], 'visto_en' => now()]);

        $cifras = $this->seguimiento->de($aviso);

        $this->assertSame(3, $cifras['alcance']);
        $this->assertSame(2, $cifras['vistos']);
        $this->assertSame(1, $cifras['confirmados']);
        $this->assertSame(1, $cifras['sin_ver']);
        $this->assertEqualsWithDelta(30, $cifras['minutos_hasta_confirmar'], 1.0);
    }

    /**
     * Alguien pudo recibirlo siendo alumno y haber causado baja. Su lectura
     * ocurrió, pero contarla daría porcentajes por encima del 100%.
     */
    public function test_la_lectura_de_quien_ya_no_es_destinatario_no_infla_los_porcentajes(): void
    {
        $vigente = $this->persona('alumno');
        $ajeno = $this->persona('docente');

        $aviso = $this->aviso('critico', [['tipo' => 'rol', 'destino_id' => $this->rol('alumno')->id]]);

        AvisoLectura::create(['aviso_id' => $aviso->id, 'persona_id' => $vigente, 'visto_en' => now()]);
        AvisoLectura::create(['aviso_id' => $aviso->id, 'persona_id' => $ajeno, 'visto_en' => now()]);

        $cifras = $this->seguimiento->de($aviso);

        $this->assertSame(1, $cifras['alcance']);
        $this->assertSame(1, $cifras['vistos'], 'Nunca más que el alcance.');
        $this->assertSame(1, $cifras['fuera_de_alcance'], 'Y se informa, para que los números cuadren a la vista.');
    }

    /**
     * El desglose es lo que revela que un 60% global es 95% entre docentes y
     * 20% entre alumnos: el total solo esconde al grupo al que hay que ir.
     */
    public function test_el_desglose_por_rol_separa_a_quien_falta(): void
    {
        $alumno = $this->persona('alumno');
        $this->persona('docente');
        $this->persona('docente');

        $aviso = $this->aviso('critico', [['tipo' => 'todos', 'destino_id' => null]]);

        AvisoLectura::create(['aviso_id' => $aviso->id, 'persona_id' => $alumno, 'visto_en' => now(), 'confirmado_en' => now()]);

        $porRol = collect($this->seguimiento->de($aviso)['por_rol'])->keyBy('rol');

        $this->assertSame(1, $porRol['Alumno']['confirmados']);
        $this->assertSame(0, $porRol['Alumno']['sin_ver']);
        $this->assertSame(2, $porRol['Docente']['sin_ver'], 'A los docentes no les ha llegado.');
    }

    /** Un informativo no pide confirmar: medírsela sería reprocharle lo que no se le pidió. */
    public function test_el_informativo_no_admite_confirmacion(): void
    {
        $this->persona('alumno');

        $informativo = $this->seguimiento->de($this->aviso('informativo', [['tipo' => 'todos', 'destino_id' => null]]));
        $importante = $this->seguimiento->de($this->aviso('importante', [['tipo' => 'todos', 'destino_id' => null]]));
        $critico = $this->seguimiento->de($this->aviso('critico', [['tipo' => 'todos', 'destino_id' => null]]));

        $this->assertFalse($informativo['admite_confirmacion']);
        $this->assertTrue($importante['admite_confirmacion']);
        $this->assertFalse($importante['exige_confirmacion'], 'La admite, no la exige: no bloquea.');
        $this->assertTrue($critico['exige_confirmacion']);
    }

    // ── Andamiaje ──────────────────────────────────────────────────────────

    /** @param  array<int, array{tipo: string, destino_id: int|null}>  $destinos */
    private function aviso(string $prioridad, array $destinos): Aviso
    {
        $aviso = Aviso::create([
            'titulo' => "Aviso {$prioridad}",
            'cuerpo' => 'Texto del aviso.',
            'prioridad' => $prioridad,
            'publicado' => true,
        ]);

        foreach ($destinos as $destino) {
            $aviso->destinos()->create($destino);
        }

        return $aviso;
    }

    private function persona(string $claveRol, bool $activo = true): int
    {
        $persona = Persona::create(['nombre' => 'Prueba', 'primer_apellido' => ucfirst($claveRol)]);

        // Directo al pivote: `rolesActivos()` filtra por `activo`, así que no
        // sirve para dar de alta a quien justamente NO está activo.
        $persona->rolesActivos()->attach($this->rol($claveRol)->id, ['activo' => $activo]);

        return $persona->id;
    }

    private function usuarioDe(int $personaId): Usuario
    {
        return Usuario::create([
            'persona_id' => $personaId,
            'usuario' => 'u'.$personaId,
            'email' => "u{$personaId}@escuela.test",
            'password' => 'secreto',
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
