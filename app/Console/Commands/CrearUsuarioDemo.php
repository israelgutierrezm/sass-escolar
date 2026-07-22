<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Academico\Campus;
use App\Models\Academico\TipoCampus;
use App\Models\Identidad\Persona;
use App\Models\Identidad\PersonaRol;
use App\Models\Identidad\Rol;
use App\Models\Identidad\Usuario;
use App\Models\Landlord\Sexo;
use App\Models\Tenant;
use Illuminate\Console\Command;

/**
 * Crea un usuario de prueba dentro de una escuela para poder entrar al sistema.
 *
 * Le asigna tres roles a propósito —uno administrativo, uno docente y uno
 * acotado a un campus— para que el conmutador de rol tenga algo que conmutar y
 * se vea la herencia de permisos y el alcance.
 *
 * Solo para desarrollo.
 */
class CrearUsuarioDemo extends Command
{
    protected $signature = 'acadion:usuario-demo
                            {--tenant=demo : Id de la escuela}
                            {--usuario=demo : Nombre de usuario}
                            {--password=demo1234 : Contraseña}';

    protected $description = 'Crea un usuario de prueba con varios roles en una escuela';

    public function handle(): int
    {
        $tenant = Tenant::find($this->option('tenant'));

        if ($tenant === null) {
            $this->error("No existe la escuela '{$this->option('tenant')}'.");

            return self::FAILURE;
        }

        tenancy()->initialize($tenant);

        $sexo = Sexo::query()->where('clave', 'H')->value('id');

        if ($sexo === null) {
            $this->error('Faltan los catálogos landlord. Corre el LandlordDatabaseSeeder.');

            return self::FAILURE;
        }

        $campus = Campus::query()->firstOrCreate(
            ['clave' => 'CEN'],
            [
                'nombre' => 'Campus Central',
                'tipo_campus_id' => TipoCampus::query()->where('clave', 'matriz')->value('id'),
            ],
        );

        $persona = Persona::query()->firstOrCreate(
            ['curp' => 'DEMO900101HDFXXX01'],
            [
                'nombre' => 'Ana',
                'primer_apellido' => 'Demo',
                'segundo_apellido' => 'Pruebas',
                'sexo_id' => $sexo,
            ],
        );

        // Varias figuras a propósito, para que el conmutador tenga qué conmutar
        // y se note que cada una abre distintos menús: admisiones no puede
        // editar el catálogo académico, dirección general sí.
        $roles = [
            ['encargado_admisiones', null],
            ['director_general', null],
            ['docente', null],
            ['director_campus', $campus->id],
        ];

        foreach ($roles as [$clave, $campusId]) {
            $rol = Rol::query()->where('name', $clave)->first();

            if ($rol === null) {
                continue;
            }

            PersonaRol::query()->firstOrCreate(
                ['persona_id' => $persona->id, 'rol_id' => $rol->id, 'campus_id' => $campusId],
                ['activo' => true],
            );
        }

        $usuario = Usuario::query()->firstOrNew(['persona_id' => $persona->id]);

        $usuario->fill([
            'usuario' => (string) $this->option('usuario'),
            'email' => $this->option('usuario').'@escuela.mx',
            'password' => (string) $this->option('password'),
        ]);

        /*
         * El rol activo NO se pisa al re-correr el comando.
         *
         * Antes se fijaba siempre a `encargado_admisiones` con un
         * updateOrCreate, así que volver a ejecutarlo —cosa que se hace seguido
         * durante el desarrollo— sacaba al usuario del rol en el que estaba
         * trabajando, sin decir nada. Restablecer la CONTRASEÑA sí es el
         * propósito del comando; cambiarle el contexto de trabajo, no.
         *
         * Solo se asigna cuando el usuario nace, o cuando el rol que trae dejó
         * de estar entre sus roles activos.
         */
        $rolValido = $usuario->rol_activo_id !== null
            && $usuario->exists
            && $usuario->puedeUsarRol((int) $usuario->rol_activo_id);

        if (! $rolValido) {
            $usuario->rol_activo_id = Rol::query()->where('name', 'encargado_admisiones')->value('id');
        }

        $usuario->save();

        // Se reportan los roles REALES que tiene la persona, no una lista fija:
        // el usuario demo acumula roles conforme se prueban módulos.
        $susRoles = $persona->rolesActivos()->pluck('nombre')->implode(', ');
        $rolActivo = Rol::find($usuario->rol_activo_id)?->nombre ?? 'ninguno';

        tenancy()->end();

        $this->info('Usuario de prueba listo.');
        $this->newLine();
        $this->line("  Escuela:    {$tenant->id}");
        $this->line("  Usuario:    {$usuario->usuario}");
        $this->line("  Contraseña: {$this->option('password')}");
        $this->line("  Roles:      {$susRoles}");
        $this->line("  Rol activo: {$rolActivo}");
        $this->newLine();
        $this->line("  Entra en:   http://{$tenant->domains()->value('domain')}:8000");

        return self::SUCCESS;
    }
}
