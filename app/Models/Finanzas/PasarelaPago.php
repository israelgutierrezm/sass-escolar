<?php

declare(strict_types=1);

namespace App\Models\Finanzas;

use App\Models\Concerns\TieneAuditoria;
use App\Support\PasarelasCatalogo;
use Illuminate\Database\Eloquent\Model;

/**
 * pasarelas_pago (TENANT) — una fila por pasarela habilitable (Stripe, Mercado
 * Pago, PayPal, OpenPay).
 *
 * Las credenciales van CIFRADAS y separadas por ambiente. La regla dura: una
 * pasarela solo se puede ACTIVAR si su ambiente activo tiene completos los
 * campos requeridos del catálogo — si no, no hay con qué operar y encenderla
 * sería mentirle al alumno.
 */
class PasarelaPago extends Model
{
    use TieneAuditoria;

    protected $table = 'pasarelas_pago';

    public const AMBIENTE_PRUEBAS = 'pruebas';
    public const AMBIENTE_PRODUCCION = 'produccion';

    protected $attributes = [
        'activa' => false,
        'ambiente' => 'pruebas',
    ];

    protected $fillable = [
        'clave',
        'activa',
        'ambiente',
        'credenciales_pruebas',
        'credenciales_produccion',
    ];

    protected function casts(): array
    {
        return [
            'activa' => 'boolean',
            // Cifrado en reposo: las llaves nunca quedan en claro en la BD.
            'credenciales_pruebas' => 'encrypted:array',
            'credenciales_produccion' => 'encrypted:array',
        ];
    }

    /** Trae la fila de una pasarela (creándola vacía si no existía todavía). */
    public static function para(string $clave): self
    {
        return static::query()->firstOrCreate(['clave' => $clave]);
    }

    public function esProduccion(): bool
    {
        return $this->ambiente === self::AMBIENTE_PRODUCCION;
    }

    /** Las credenciales del ambiente activo. @return array<string, mixed> */
    public function credencialesActivas(): array
    {
        return ($this->esProduccion() ? $this->credenciales_produccion : $this->credenciales_pruebas) ?? [];
    }

    /** Las credenciales de un ambiente dado. @return array<string, mixed> */
    public function credencialesDe(string $ambiente): array
    {
        return ($ambiente === self::AMBIENTE_PRODUCCION ? $this->credenciales_produccion : $this->credenciales_pruebas) ?? [];
    }

    /**
     * ¿Tiene TODOS los campos requeridos del ambiente indicado? Es lo que decide
     * si se puede activar (y si puede operar de verdad).
     */
    public function completaEn(string $ambiente): bool
    {
        $credenciales = $this->credencialesDe($ambiente);

        foreach (PasarelasCatalogo::camposRequeridos($this->clave) as $campo) {
            if (blank($credenciales[$campo] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /** ¿Puede activarse? Solo si el ambiente activo está completo. */
    public function puedeActivar(): bool
    {
        return $this->completaEn($this->ambiente);
    }
}
