<?php

declare(strict_types=1);

namespace App\Models\Identidad;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * usuarios (TENANT) — credenciales de acceso de una persona.
 *
 * Es el modelo autenticable del guard `web` (ver config/auth.php). No lleva el
 * trait HasRoles de Spatie a propósito: los roles se asignan a la PERSONA
 * (persona_rol, con bandera de activo y alcance por campus), no al usuario.
 * Los permisos se resuelven a partir del rol activo.
 */
class Usuario extends Authenticatable
{
    use Notifiable;
    use TieneAuditoria;

    protected $table = 'usuarios';

    protected $fillable = [
        'persona_id',
        'usuario',
        'email',
        'password',
        'url_perfil',
        'conectado',
        'rol_activo_id',
        'tema_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'conectado' => 'boolean',
        ];
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class);
    }

    /** Rol con el que interactúa en este momento. */
    public function rolActivo(): BelongsTo
    {
        return $this->belongsTo(Rol::class, 'rol_activo_id');
    }

    public function tema(): BelongsTo
    {
        return $this->belongsTo(Tema::class);
    }

    public function overridesTema(): HasMany
    {
        return $this->hasMany(UsuarioTemaOverride::class, 'usuario_id');
    }

    /**
     * Roles que puede ejercer, vía su persona. La verdad sobre "qué es" una
     * persona vive en persona_rol, no aquí.
     */
    public function rolesDisponibles()
    {
        return $this->persona?->rolesActivos()->get() ?? collect();
    }

    /**
     * Defensa contra manipulación del cliente: valida que el rol pedido esté
     * entre los roles ACTIVOS de la persona antes de conmutar.
     */
    public function puedeUsarRol(int $rolId): bool
    {
        return PersonaRol::query()
            ->where('persona_id', $this->persona_id)
            ->where('rol_id', $rolId)
            ->where('activo', true)
            ->exists();
    }

    /** Conmuta el rol activo. Devuelve false si el rol no le corresponde. */
    public function conmutarRol(int $rolId): bool
    {
        if (! $this->puedeUsarRol($rolId)) {
            return false;
        }

        $this->rol_activo_id = $rolId;
        $this->save();

        return true;
    }

    /** ¿El rol activo concede este permiso (propio o heredado del padre)? */
    public function tienePermiso(string $permiso): bool
    {
        return $this->rolActivo?->concede($permiso) ?? false;
    }

    /** Campus a los que se acota el rol activo; vacío = alcance global. */
    public function campusDelRolActivo(): array
    {
        if ($this->rol_activo_id === null) {
            return [];
        }

        return PersonaRol::query()
            ->where('persona_id', $this->persona_id)
            ->where('rol_id', $this->rol_activo_id)
            ->where('activo', true)
            ->whereNotNull('campus_id')
            ->pluck('campus_id')
            ->all();
    }
}
