<?php

declare(strict_types=1);

namespace App\Models\Permanencia;

use App\Models\Identidad\Persona;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * accesos_caso (TENANT) — quién abrió este caso.
 *
 * Calcado de `AccesoBitacoraTutoria`, y por la misma razón: lo que hay dentro de
 * un caso son conversaciones sobre la vida de alguien. El permiso decide quién
 * PUEDE entrar; esto deja rastro de quién entró, que es lo que sirve el día que
 * el contenido circula por la escuela y hay que averiguar por dónde salió.
 *
 * ── Se registra la CONSULTA, no el contenido ───────────────────────────────
 * Cuántas intervenciones se mostraron y cuántas quedaron reservadas. Una
 * auditoría que copie lo vigilado multiplica el problema que intenta resolver.
 *
 * ── Y se ENSEÑA a quien mira ───────────────────────────────────────────────
 * Escondida en una tabla que sólo consulta un administrador es un trámite
 * forense; a la vista, es lo que de verdad disuade — saber que la consulta queda
 * firmada y que los demás la van a ver.
 */
class AccesoCaso extends Model
{
    public $timestamps = false;

    protected $table = 'accesos_caso';

    protected $fillable = [
        'caso_id', 'persona_id', 'intervenciones_vistas', 'reservadas_ocultas', 'ip', 'creado_en',
    ];

    protected function casts(): array
    {
        return ['creado_en' => 'datetime'];
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'persona_id');
    }
}
