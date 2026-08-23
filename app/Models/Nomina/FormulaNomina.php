<?php

declare(strict_types=1);

namespace App\Models\Nomina;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * formulas_nomina (TENANT-CONFIG) — un porcentaje sobre una base, con tope.
 *
 * ── Eso es TODO lo que hace, y es deliberado ──────────────────────────────
 * Cubre lo que de verdad es un porcentaje: la cuota obrera del IMSS, un
 * descuento proporcional, un bono sobre lo gravable.
 *
 * **El ISR no se calcula con esto.** Sale de la tarifa por rangos del artículo
 * 96 más el subsidio al empleo, que no es un factor plano. Una fórmula de ISR
 * con un porcentaje inventado daría un número que parece bueno, que alguien
 * enteraría al SAT y que nadie descubriría hasta la primera revisión. El
 * concepto `isr` se captura a mano hasta que exista la tarifa oficial.
 *
 * ── Y por eso es relacional y no un blob ──────────────────────────────────
 * Base, factor y tope son tres columnas que se leen con un SELECT. Una fórmula
 * guardada como texto hay que interpretarla en el código para saber qué hace, y
 * entonces nadie puede auditar la nómina sin leer el intérprete.
 */
class FormulaNomina extends Model
{
    use TieneAuditoria;

    /** Sobre lo que suma y además grava. Aquí es donde `es_gravable` se lee. */
    public const BASE_GRAVABLE = 'percepciones_gravables';

    /** Sobre todo lo que suma, grave o no. */
    public const BASE_PERCEPCIONES = 'total_percepciones';

    protected $table = 'formulas_nomina';

    protected $fillable = ['clave', 'nombre', 'base', 'factor', 'tope', 'activo'];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
            'factor' => 'decimal:6',
            'tope' => 'decimal:2',
        ];
    }

    public function scopeActivos(Builder $consulta): Builder
    {
        return $consulta->where('activo', true)->orderBy('nombre');
    }

    /** @return array<string, string> */
    public static function bases(): array
    {
        return [
            self::BASE_GRAVABLE => 'Percepciones gravables',
            self::BASE_PERCEPCIONES => 'Total de percepciones',
        ];
    }

    /**
     * Aplica el factor a una base y respeta el tope.
     *
     * Redondea a dos decimales AQUÍ y no al sumar: si no, el total llevaría
     * fracciones de centavo que ningún renglón del recibo explica y la suma de
     * lo que se ve no daría el neto que se paga.
     */
    public function aplicar(float $base): float
    {
        $importe = round($base * (float) $this->factor, 2);

        return $this->tope === null ? $importe : min($importe, (float) $this->tope);
    }
}
