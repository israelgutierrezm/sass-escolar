<?php

declare(strict_types=1);

namespace App\Models\Encuestas;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * encuestas (TENANT) — el cuestionario: las preguntas y nada más.
 *
 * Cuándo se aplica, a quién y con qué reglas es de `AplicacionEncuesta`. Esa
 * separación es la que permite tener una plantilla de evaluación docente y
 * lanzarla cada semestre sin volver a capturarla.
 */
class Encuesta extends Model
{
    use TieneAuditoria;

    protected $table = 'encuestas';

    protected $fillable = ['titulo', 'descripcion', 'es_plantilla', 'activa'];

    protected function casts(): array
    {
        return ['es_plantilla' => 'boolean', 'activa' => 'boolean'];
    }

    public function preguntas(): HasMany
    {
        return $this->hasMany(Pregunta::class, 'encuesta_id')->orderBy('orden');
    }

    public function aplicaciones(): HasMany
    {
        return $this->hasMany(AplicacionEncuesta::class, 'encuesta_id');
    }

    public function scopePlantillas(Builder $q): Builder
    {
        return $q->where('es_plantilla', true)->where('activa', true);
    }

    /**
     * Una copia del cuestionario con sus preguntas y opciones.
     *
     * Aplicar una plantilla COPIA en vez de apuntar: si la plantilla se edita
     * en marzo, la encuesta que trescientos alumnos contestaron en febrero no
     * puede cambiar debajo —los resultados quedarían atribuidos a preguntas que
     * nadie vio—.
     */
    public function duplicar(?string $titulo = null, bool $comoPlantilla = false): self
    {
        $copia = self::create([
            'titulo' => $titulo ?? $this->titulo,
            'descripcion' => $this->descripcion,
            'es_plantilla' => $comoPlantilla,
            'activa' => true,
        ]);

        foreach ($this->preguntas()->with('opciones')->get() as $pregunta) {
            $nueva = $copia->preguntas()->create($pregunta->only([
                'texto', 'ayuda', 'tipo', 'requerida', 'config', 'orden',
            ]));

            foreach ($pregunta->opciones as $opcion) {
                $nueva->opciones()->create($opcion->only(['texto', 'valor', 'orden']));
            }
        }

        return $copia;
    }
}
