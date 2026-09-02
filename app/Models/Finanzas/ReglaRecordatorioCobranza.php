<?php

declare(strict_types=1);

namespace App\Models\Finanzas;

use App\Enums\PrioridadAviso;
use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * reglas_recordatorio_cobranza (TENANT-CONFIG) — un peldaño de la escalera.
 *
 * `dias` va con signo: negativo antes de vencer, cero el día mismo, positivo
 * después. Es el mismo eje, así que una columna sola; con «tipo» + «días» se
 * podrían escribir estados imposibles y el orden dejaría de ser un `ORDER BY`.
 */
class ReglaRecordatorioCobranza extends Model
{
    use TieneAuditoria;

    protected $table = 'reglas_recordatorio_cobranza';

    protected $fillable = ['nombre', 'dias', 'titulo', 'cuerpo', 'prioridad', 'dias_vigente', 'activo'];

    protected function casts(): array
    {
        return ['dias' => 'integer', 'dias_vigente' => 'integer', 'activo' => 'boolean'];
    }

    public function scopeActivas(Builder $consulta): Builder
    {
        return $consulta->where('activo', true);
    }

    /** De la más suave a la más severa: el orden de la escalera. */
    public function scopeEnEscalera(Builder $consulta): Builder
    {
        return $consulta->orderBy('dias');
    }

    public function prioridadAviso(): PrioridadAviso
    {
        return PrioridadAviso::tryFrom($this->prioridad) ?? PrioridadAviso::Informativo;
    }

    /** Cómo se lee el peldaño en pantalla. */
    public function cuando(): string
    {
        return match (true) {
            $this->dias < 0 => abs($this->dias).' día(s) antes de vencer',
            $this->dias === 0 => 'El día del vencimiento',
            default => $this->dias.' día(s) después de vencer',
        };
    }

    /**
     * Rellena la plantilla.
     *
     * Los tokens son lo que hace configurable el TONO, que es la mitad de la
     * cobranza: no es lo mismo la primera vez que la cuarta, y esa diferencia la
     * escribe cada escuela.
     *
     * @param  array<string, string>  $valores
     */
    public static function rellenar(string $plantilla, array $valores): string
    {
        foreach ($valores as $token => $valor) {
            $plantilla = str_replace('{'.$token.'}', $valor, $plantilla);
        }

        return $plantilla;
    }

    /** @return array<int, string> */
    public static function tokens(): array
    {
        return ['alumno', 'matricula', 'cargos', 'monto', 'vence', 'dias'];
    }
}
