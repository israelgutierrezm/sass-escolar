<?php

declare(strict_types=1);

namespace App\Models\Permanencia;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * niveles_riesgo (TENANT-CONFIG) — qué puntaje es «alto» en esta escuela.
 *
 * ── Por qué es catálogo ────────────────────────────────────────────────────
 * Cinco niveles con umbrales cableados obligarían a todas las escuelas a llamar
 * «crítico» a lo mismo. Lo que en un bachillerato de mil alumnos es una cola
 * manejable, en una normal de ciento veinte es media escuela — y quien tiene que
 * poder ajustarlo es quien va a atender esa cola.
 *
 * ── `pide_seguimiento` NO dispara nada ─────────────────────────────────────
 * Es lo que la pantalla usa para separar «esto hay que atenderlo» de «esto se
 * anota», y nada más. **Ninguna parte de este módulo ejecuta una acción por
 * llegar a un nivel**: el pedido lo prohíbe con esas palabras y una prueba lo
 * vigila. El día que la escuela quiera que un nivel abra un caso, eso será un
 * acto de una persona con su permiso.
 */
class NivelRiesgo extends Model
{
    use TieneAuditoria;

    protected $table = 'niveles_riesgo';

    protected $attributes = ['pide_seguimiento' => false, 'color' => 'gris', 'activo' => true];

    protected $fillable = [
        'clave',
        'nombre',
        'descripcion',
        'desde_puntaje',
        'pide_seguimiento',
        'color',
        'orden',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'desde_puntaje' => 'integer',
            'pide_seguimiento' => 'boolean',
            'orden' => 'integer',
            'activo' => 'boolean',
        ];
    }

    public function scopeActivos(Builder $c): Builder
    {
        return $c->where('activo', true)->orderBy('desde_puntaje');
    }

    /**
     * El nivel que corresponde a un puntaje: el MÁS ALTO que alcance.
     *
     * Los umbrales son un corte y no un rango, así que no se pueden solapar por
     * definición — y eso evita el hueco entre dos rangos, que es como un puntaje
     * se queda sin nivel y la pantalla enseña un guión.
     *
     * Con el catálogo vacío devuelve null y quien llama lo dice: inventar un
     * nivel sería afirmar algo que la escuela no ha configurado.
     */
    public static function paraPuntaje(int $puntaje): ?self
    {
        return self::query()
            ->where('activo', true)
            ->where('desde_puntaje', '<=', $puntaje)
            /*
             * `activos()` NO se usa aquí, y no es descuido: ese scope trae su
             * propio `ORDER BY desde_puntaje ASC`, y encadenarle uno descendente
             * produce `ORDER BY desde_puntaje ASC, desde_puntaje DESC` — donde
             * gana el primero. El resultado era que TODO puntaje caía en el
             * nivel más bajo, sin un solo error.
             *
             * Vale como regla: un scope que lleva orden dentro envenena
             * cualquier consulta que quiera otro. Se usa `reorder()` o no se usa
             * el scope.
             */
            ->orderByDesc('desde_puntaje')
            ->first();
    }
}
