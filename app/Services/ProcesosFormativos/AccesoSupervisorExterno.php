<?php

declare(strict_types=1);

namespace App\Services\ProcesosFormativos;

use App\Exceptions\AvisoParaElUsuario;
use App\Models\Identidad\Persona;
use App\Models\Identidad\PersonaRol;
use App\Models\Identidad\Rol;
use App\Models\Identidad\Usuario;
use App\Models\ProcesosFormativos\OrganizacionContacto;
use App\Services\AprovisionadorAcceso;
use App\Support\CatalogoPermisos;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * El ACCESO de un supervisor externo a su portal, en un solo lugar.
 *
 * ── Qué NO se reinventa ────────────────────────────────────────────────────
 * La cuenta la crea {@see AprovisionadorAcceso} —la misma pieza que da cuenta a
 * un docente o a un alumno—, el rol lo apaga `persona_rol.activo`, y el login
 * sigue siendo por correo o CURP. Lo único propio de aquí es la VIGENCIA —desde
 * cuándo y hasta cuándo vale ese acceso— y la marca de revocación, que viven en
 * el contacto (ver la migración que las agregó).
 *
 * ── El login es de PERSONAS, así que el contacto necesita una ──────────────
 * `organizacion_contactos.persona_id` es opcional a propósito —no se llena el
 * padrón de la escuela con gente de fuera hasta que hace falta—. Invitar es
 * justo cuando hace falta: si el contacto no tiene persona, se le crea una
 * MÍNIMA (nombre y correo), que es la columna que su docblock dejó anotada
 * «para cuando llegue su portal».
 *
 * ── Nunca se pisa una contraseña ya configurada ────────────────────────────
 * Un supervisor externo suele estrenar cuenta, pero podría ser alguien que ya
 * entra a la escuela por otro rol. En ese caso invitarlo le SUMA la faceta y su
 * vigencia, sin tocar su contraseña: reescribirla lo dejaría fuera de su otra
 * puerta. La contraseña temporal sólo nace para la cuenta que no tenía acceso.
 */
class AccesoSupervisorExterno
{
    public function __construct(private readonly AprovisionadorAcceso $aprovisionador) {}

    /**
     * Deja al supervisor con cuenta, faceta y vigencia. Devuelve la cuenta y la
     * contraseña temporal (null si la cuenta ya tenía acceso propio).
     *
     * @param  array{desde?: ?string, hasta?: ?string}  $datos
     * @return array{usuario: Usuario, password: ?string}
     *
     * @throws AvisoParaElUsuario 422 con el motivo concreto
     */
    public function invitar(OrganizacionContacto $contacto, array $datos, Usuario $porQuien): array
    {
        AvisoParaElUsuario::aMenosQue(
            filled($contacto->correo),
            422,
            'Este contacto no tiene correo, y sin un correo no hay con qué entrar. Captúralo primero.',
        );

        return DB::transaction(function () use ($contacto, $datos): array {
            $persona = $this->personaDelContacto($contacto);

            $usuario = $this->aprovisionador->paraPersona($persona, CatalogoPermisos::SUPERVISOR);

            AvisoParaElUsuario::si(
                $usuario === null,
                422,
                'Falta el rol «supervisor externo» en el catálogo. Ejecuta los seeders de roles.',
            );

            // El aprovisionador NO reactiva un rol apagado a mano —y una revocación
            // previa lo apagó—. Al re-invitar hay que volver a encenderlo.
            $this->reactivarFaceta($persona->id);

            // La contraseña temporal SÓLO para la cuenta que aún no tiene acceso:
            // ver el docblock de la clase.
            $password = null;

            if (! $usuario->acceso_configurado) {
                $password = Str::password(12, symbols: false, spaces: false);
                $usuario->forceFill([
                    'password' => Hash::make($password),
                    'acceso_configurado' => true,
                ])->save();
            }

            $contacto->persona_id = $persona->id;
            $contacto->invitado_en = now();
            $contacto->acceso_desde = $datos['desde'] ?? now()->toDateString();
            $contacto->acceso_hasta = $datos['hasta'] ?? null;
            // Re-invitar levanta una revocación anterior.
            $contacto->acceso_revocado_en = null;
            $contacto->acceso_revocado_por = null;
            $contacto->save();

            return ['usuario' => $usuario, 'password' => $password];
        });
    }

    /**
     * Corta el acceso de ESTE contacto y, si la persona no supervisa en ningún
     * otro contacto vigente, apaga su faceta para que no pueda ni entrar.
     *
     * El corte de verdad lo hace el ALCANCE —que sólo lista expedientes cuyo
     * contacto de supervisión está vigente—, así que revocar aquí un contacto no
     * toca los expedientes que la persona supervisa por OTRO contacto que siga
     * vivo. Apagar la faceta es la limpieza final: si no le queda nada, tampoco
     * la puerta.
     */
    public function revocar(OrganizacionContacto $contacto, Usuario $porQuien): void
    {
        DB::transaction(function () use ($contacto, $porQuien): void {
            $contacto->acceso_revocado_en = now();
            $contacto->acceso_revocado_por = $porQuien->id;
            $contacto->save();

            if ($contacto->persona_id === null) {
                return;
            }

            $leQuedaAlgo = OrganizacionContacto::query()
                ->where('persona_id', $contacto->persona_id)
                ->where('id', '!=', $contacto->id)
                ->conAccesoVigente()
                ->exists();

            if (! $leQuedaAlgo) {
                $this->apagarFaceta($contacto->persona_id);
            }
        });
    }

    private function personaDelContacto(OrganizacionContacto $contacto): Persona
    {
        if ($contacto->persona_id !== null && $contacto->persona !== null) {
            return $contacto->persona;
        }

        // Persona MÍNIMA: el nombre completo va en `nombre` y el apellido queda
        // vacío. No se adivina un apellido —un nombre de contacto puede venir de
        // mil formas— y `nombreCompleto()` lo recompone bien igual (hace trim).
        return Persona::create([
            'nombre' => (string) ($contacto->nombre ?: 'Supervisor externo'),
            'primer_apellido' => '',
            'email' => $contacto->correo,
        ]);
    }

    private function reactivarFaceta(int $personaId): void
    {
        $rol = Rol::query()->where('name', CatalogoPermisos::SUPERVISOR)->first();

        if ($rol !== null) {
            PersonaRol::query()
                ->where('persona_id', $personaId)
                ->where('rol_id', $rol->id)
                ->update(['activo' => true]);
        }
    }

    private function apagarFaceta(int $personaId): void
    {
        $rol = Rol::query()->where('name', CatalogoPermisos::SUPERVISOR)->first();

        if ($rol !== null) {
            PersonaRol::query()
                ->where('persona_id', $personaId)
                ->where('rol_id', $rol->id)
                ->update(['activo' => false]);
        }
    }
}
