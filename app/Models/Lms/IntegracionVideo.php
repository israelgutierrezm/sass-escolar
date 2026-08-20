<?php

declare(strict_types=1);

namespace App\Models\Lms;

use App\Models\Concerns\TieneAuditoria;
use App\Support\ProveedoresVideoCatalogo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * integraciones_videoconferencia (TENANT) — una fila por proveedor habilitable.
 *
 * Mismo trato que `pasarelas_pago`: credenciales cifradas y la regla dura de que
 * un proveedor sólo se puede ENCENDER si tiene completos los campos requeridos
 * de su catálogo. Encenderlo sin credenciales sería prometerle una clase a un
 * grupo y fallar al empezar.
 */
class IntegracionVideo extends Model
{
    use TieneAuditoria;

    protected $table = 'integraciones_videoconferencia';

    protected $attributes = ['activa' => false];

    protected $fillable = ['clave', 'activa', 'credenciales'];

    protected function casts(): array
    {
        return [
            'activa' => 'boolean',
            // Cifrado en reposo: un client_secret en claro es una clase de
            // cualquier grupo a disposición de quien lea la tabla.
            'credenciales' => 'encrypted:array',
        ];
    }

    /** La fila de un proveedor, creándola vacía si es la primera vez. */
    public static function para(string $clave): self
    {
        return static::query()->firstOrCreate(['clave' => $clave]);
    }

    public function cuentas(): HasMany
    {
        return $this->hasMany(CuentaVideo::class, 'proveedor', 'clave');
    }

    /** @return array<string, mixed> */
    public function credencialesArray(): array
    {
        return $this->credenciales ?? [];
    }

    /** Si tiene con qué operar: todos los campos requeridos, llenos. */
    public function credencialesCompletas(): bool
    {
        $credenciales = $this->credencialesArray();

        foreach (ProveedoresVideoCatalogo::camposRequeridos($this->clave) as $campo) {
            if (blank($credenciales[$campo] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /** Encendida Y con credenciales: las dos cosas, o no opera. */
    public function operativa(): bool
    {
        return $this->activa && $this->credencialesCompletas();
    }

    /** Si una cuenta suya sostiene una sola reunión a la vez. */
    public function unaReunionPorCuenta(): bool
    {
        return ProveedoresVideoCatalogo::unaReunionPorCuenta($this->clave);
    }
}
