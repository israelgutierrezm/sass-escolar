<?php

declare(strict_types=1);

namespace App\Models\Lms;

use App\Models\Concerns\TieneAuditoria;
use App\Support\DestinosGrabacionCatalogo;
use Illuminate\Database\Eloquent\Model;

/**
 * destinos_grabacion (TENANT) — dónde se archivan las grabaciones.
 *
 * Una fila por destino posible y SÓLO UNO activo: con dos habría que decidir qué
 * enlace se le enseña al alumno y se pagaría dos veces el mismo archivo. Lo
 * impone el controlador al encender, no la base.
 */
class DestinoGrabacion extends Model
{
    use TieneAuditoria;

    protected $table = 'destinos_grabacion';

    protected $attributes = ['activo' => false];

    protected $fillable = ['clave', 'activo', 'credenciales'];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
            'credenciales' => 'encrypted:array',
        ];
    }

    public static function para(string $clave): self
    {
        return static::query()->firstOrCreate(['clave' => $clave]);
    }

    /** El que está encendido, o null si la escuela no archiva. */
    public static function activo(): ?self
    {
        return static::query()->where('activo', true)->first();
    }

    /** @return array<string, mixed> */
    public function credencialesArray(): array
    {
        return $this->credenciales ?? [];
    }

    public function credencialesCompletas(): bool
    {
        foreach (DestinosGrabacionCatalogo::camposRequeridos($this->clave) as $campo) {
            if (blank($this->credencialesArray()[$campo] ?? null)) {
                return false;
            }
        }

        return true;
    }

    public function operativo(): bool
    {
        return $this->activo && $this->credencialesCompletas();
    }
}
