<?php

declare(strict_types=1);

namespace App\Models\Lms;

use App\Enums\TipoReactivo;
use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * reactivos (TENANT) — una pregunta del banco del curso.
 *
 * Del CURSO y no del examen: por eso la misma pregunta sirve en el parcial y en
 * el extraordinario, y por eso se pueden sortear diez de treinta. Un reactivo
 * que solo existe dentro de un examen se vuelve a capturar cada vez.
 */
class Reactivo extends Model
{
    use SoftDeletes;
    use TieneAuditoria;

    protected $table = 'reactivos';

    protected $fillable = [
        'curso_id',
        'tipo',
        'enunciado',
        'imagen',
        'puntos',
        'retroalimentacion',
        'respuesta',
        'tema',
        'dificultad',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoReactivo::class,
            'puntos' => 'decimal:2',
            'respuesta' => 'array',
        ];
    }

    public function curso(): BelongsTo
    {
        return $this->belongsTo(Curso::class, 'curso_id');
    }

    public function opciones(): HasMany
    {
        return $this->hasMany(ReactivoOpcion::class, 'reactivo_id')->orderBy('orden');
    }

    /**
     * Lo que se le manda al alumno: el enunciado y las opciones SIN decir cuál
     * es la correcta.
     *
     * Existe para que ninguna pantalla tenga que acordarse de quitar el campo
     * `correcta` antes de mandar el examen. Si esa decisión se toma en cada
     * lugar, algún día se olvida en uno y el examen se contesta leyendo el
     * código fuente de la página.
     */
    public function paraResolver(bool $barajarOpciones = false): array
    {
        $opciones = $this->opciones->map(fn (ReactivoOpcion $o) => [
            'id' => $o->id,
            'texto' => $o->texto,
        ]);

        if ($this->tipo === TipoReactivo::Ordenamiento || $barajarOpciones) {
            // Ordenar YA barajado: presentarlas en su orden correcto sería
            // regalar la respuesta.
            $opciones = $opciones->shuffle();
        }

        return [
            'id' => $this->id,
            'tipo' => $this->tipo->value,
            'forma' => $this->tipo->formaDeRespuesta(),
            'enunciado' => $this->enunciado,
            'imagen' => $this->imagen,
            'opciones' => $opciones->values()->all(),
            // Las categorías de un clasificar sí se muestran: son el destino,
            // no la respuesta.
            'categorias' => $this->tipo === TipoReactivo::Clasificar
                ? $this->opciones->pluck('pareja')->filter()->unique()->shuffle()->values()->all()
                : [],
            'huecos' => $this->tipo === TipoReactivo::Completar
                ? count($this->respuesta['huecos'] ?? [])
                : 0,
        ];
    }
}
