<?php

declare(strict_types=1);

namespace App\Models\ProcesosFormativos;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * modalidades_proceso (TENANT-CONFIG) — presencial, mixta, remota.
 *
 * `es_a_distancia` lo lee la bitácora de horas: a quien trabaja en remoto no
 * tiene sentido pedirle dónde está, y sin la bandera habría que preguntarlo por
 * la clave justo en el sitio donde se decide qué dato personal se guarda.
 *
 * Aparte de `oferta.modalidad`, que dice cómo se cursa el PROGRAMA: un alumno
 * de una licenciatura escolarizada puede hacer sus prácticas en remoto.
 */
class ModalidadProceso extends Model
{
    use TieneAuditoria;

    protected $table = 'modalidades_proceso';

    protected $fillable = ['clave', 'nombre', 'es_a_distancia', 'orden', 'activo'];

    protected function casts(): array
    {
        return ['es_a_distancia' => 'boolean', 'orden' => 'integer', 'activo' => 'boolean'];
    }

    public function scopeActivos(Builder $c): Builder
    {
        return $c->where('activo', true)->orderBy('orden')->orderBy('nombre');
    }
}
