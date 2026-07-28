<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Identidad\Persona;
use App\Models\Identidad\PersonaRol;
use App\Models\Identidad\Rol;
use App\Models\Identidad\Usuario;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Da a una persona su rol y su cuenta, en un solo lugar.
 *
 * La regla del sistema: **toda persona con un rol es un usuario**. Un docente,
 * un alumno o un aspirante no son solo un registro de dominio (`docentes`,
 * `matricula_oferta`, `aspirantes`); son personas que deben aparecer en
 * `/plataforma/usuarios` y, cuando se habilite el acceso, poder entrar.
 *
 * Este servicio materializa ese invariante y es la ÚNICA vía para hacerlo, así
 * que las reglas viven en un sitio:
 *
 *  - **Idempotente.** Si la persona ya tiene el rol, no lo duplica; si ya tiene
 *    cuenta, no la vuelve a crear ni le toca la contraseña. Puede llamarse mil
 *    veces (al alta, en el backfill, al reintentar) sin efectos raros.
 *  - **Una cuenta por persona.** Quien es docente Y alumno tiene UNA cuenta con
 *    DOS roles, no dos cuentas. Lo garantiza el índice único de `persona_id`.
 *  - **Cuenta de censo, sin acceso aún.** La contraseña se crea inservible
 *    (`acceso_configurado = false`): la cuenta existe y se lista, pero nadie
 *    entra con ella hasta que la etapa de acceso fije una contraseña real. Nunca
 *    se pisa una cuenta que YA tiene acceso configurado.
 */
class AprovisionadorAcceso
{
    /**
     * Asegura que la persona tenga el rol `$rolClave` (activo) y una cuenta.
     *
     * Devuelve la cuenta, o null si el rol no está sembrado (sin él no se puede
     * asignar nada, y reventar aquí rompería el alta de un docente por una
     * config incompleta del catálogo de roles).
     */
    public function paraPersona(Persona $persona, string $rolClave): ?Usuario
    {
        $rol = Rol::query()->where('name', $rolClave)->first();

        if ($rol === null) {
            return null;
        }

        // El rol de la persona. No se reactiva uno desactivado a mano: si un
        // administrador lo apagó, fue a propósito.
        PersonaRol::query()->firstOrCreate(
            ['persona_id' => $persona->id, 'rol_id' => $rol->id],
            ['activo' => true],
        );

        $usuario = Usuario::query()->where('persona_id', $persona->id)->first();

        if ($usuario !== null) {
            // Ya tenía cuenta (quizá por otro rol): solo se le completa el rol
            // activo si venía sin ninguno. Jamás se toca su contraseña.
            if ($usuario->rol_activo_id === null) {
                $usuario->forceFill(['rol_activo_id' => $rol->id])->save();
            }

            return $usuario;
        }

        return Usuario::create([
            'persona_id' => $persona->id,
            'usuario' => $this->usuarioDisponible($persona),
            // El correo es la llave del login y es ÚNICO: si otra cuenta ya lo
            // usa, esta cuenta de censo nace SIN correo (existe y se lista, pero
            // no se entra por un correo ajeno). Que no colisione lo garantiza,
            // además, el índice único de `usuarios.email`.
            'email' => $this->correoLibre($persona->email),
            // Contraseña inservible a propósito: la cuenta existe para el censo,
            // pero no se entra con ella hasta la etapa de acceso.
            'password' => Hash::make(Str::random(40)),
            'acceso_configurado' => false,
            'rol_activo_id' => $rol->id,
        ]);
    }

    /**
     * El correo si nadie más lo usa como credencial; null si ya está tomado.
     * Evita que dos cuentas compartan correo de login (cruce de sesiones).
     */
    private function correoLibre(?string $email): ?string
    {
        if (! filled($email)) {
            return null;
        }

        $tomado = Usuario::query()->whereRaw('lower(email) = ?', [mb_strtolower(trim($email))])->exists();

        return $tomado ? null : $email;
    }

    /**
     * Un nombre de usuario libre, derivado del correo, del nombre o de la CURP.
     *
     * Es un identificador técnico —el acceso será por correo—, pero la columna
     * es única y NOT NULL, así que necesita un valor sin colisiones.
     */
    public function usuarioDisponible(Persona $persona): string
    {
        $correo = (string) ($persona->email ?? '');
        $base = strtolower((string) (explode('@', $correo)[0]
            ?: trim(($persona->nombre ?? '').($persona->primer_apellido ?? ''))
            ?: (string) $persona->curp));

        $base = preg_replace('/[^a-z0-9._-]/', '', $base) ?: 'usuario';
        $candidato = $base;
        $n = 1;

        while (Usuario::query()->where('usuario', $candidato)->exists()) {
            $candidato = $base.(++$n);
        }

        return $candidato;
    }
}
