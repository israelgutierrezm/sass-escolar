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
        'opciones',
    ];

    protected function casts(): array
    {
        return [
            'activa' => 'boolean',
            // Cifrado en reposo: las llaves nunca quedan en claro en la BD.
            'credenciales_pruebas' => 'encrypted:array',
            'credenciales_produccion' => 'encrypted:array',
            // Sin cifrar: que la escuela acepte OXXO no es un secreto, y
            // esto se lee en cada cobro.
            'opciones' => 'array',
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

    /**
     * Lo que esta pasarela ofrece, con los valores por omisión del catálogo
     * rellenando lo que la escuela no haya tocado.
     *
     * Se resuelve SIEMPRE contra el catálogo y no se devuelve lo guardado tal
     * cual: una opción nueva —una forma de pago que la pasarela estrene— tiene
     * que llegar con su valor por omisión a las escuelas que se configuraron
     * antes de que existiera, no como «apagada» por no estar en un JSON viejo.
     *
     * @return array<string, mixed>
     */
    public function opciones(): array
    {
        $guardadas = $this->opciones ?? [];

        return collect(PasarelasCatalogo::opciones($this->clave))
            ->map(fn (array $def, string $k) => $guardadas[$k] ?? $def['default'])
            ->all();
    }

    /** ¿Está encendida esta forma de pago? */
    public function aceptaMetodo(string $metodo): bool
    {
        return (bool) ($this->opciones()[$metodo] ?? false);
    }

    /**
     * Las formas de pago encendidas.
     *
     * @return array<int, string>
     */
    public function metodosAceptados(): array
    {
        return array_values(array_filter(
            PasarelasCatalogo::metodosDe($this->clave),
            fn (string $m) => $this->aceptaMetodo($m),
        ));
    }

    /**
     * Los plazos de meses sin intereses que se ofrecen, de mayor a menor.
     *
     * Ordenados así porque quien cobra piensa en el plazo MÁXIMO —«damos hasta
     * 12 meses»— y varias pasarelas sólo aceptan ese número, no la lista.
     *
     * @return array<int, int>
     */
    public function mesesSinIntereses(): array
    {
        $meses = $this->opciones()['msi'] ?? [];

        if (! is_array($meses)) {
            return [];
        }

        $meses = array_values(array_unique(array_map('intval', $meses)));
        rsort($meses);

        // Sin tarjeta no hay MSI: los meses los da la tarjeta de crédito, así
        // que ofrecerlos con la tarjeta apagada es ofrecer nada.
        return $this->aceptaMetodo('tarjeta') ? $meses : [];
    }

    public function ofreceMsi(): bool
    {
        return $this->mesesSinIntereses() !== [];
    }

    /** El plazo máximo ofrecido, o 1 (un solo pago) si no hay MSI. */
    public function mesesMaximos(): int
    {
        return $this->mesesSinIntereses()[0] ?? 1;
    }
}
