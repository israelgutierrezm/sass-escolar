<?php

declare(strict_types=1);

namespace App\Models\Formularios;

use App\Models\Concerns\TieneAuditoria;
use App\Models\Identidad\Rol;
use App\Support\CatalogoPermisos;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * formulario_asignacion (TENANT) — a quién se le muestra un formulario.
 *
 * Un ROL, y opcionalmente acotado a un nivel de estudios, un programa académico o una
 * oferta. El ámbito no es un destinatario alternativo: es un recorte del
 * destinatario, y sólo tiene sentido para quien tiene programa académico —aspirantes y
 * alumnos—. Un formulario «para el programa académico de Derecho» no le llega a nadie
 * mientras no se diga a quién de ese programa académico.
 *
 * Que aspirante y alumno se recorten con el MISMO criterio es deliberado: el
 * aspirante se convierte en alumno y su expediente de formularios viaja con él;
 * si cada rol se acotara distinto, el expediente se partiría al cruzar.
 *
 * La referencia al ámbito es polimórfica y sin FK: nivel, programa académico y oferta
 * viven en tablas distintas.
 */
class FormularioAsignacion extends Model
{
    use TieneAuditoria;

    /** Los recortes posibles, del más amplio al más específico. */
    public const AMBITOS = ['nivel', 'programa_academico', 'oferta'];

    /**
     * Las facetas cuyo rol admite recorte académico.
     *
     * Un docente o un administrativo no tienen programa académico, así que acotarles un
     * formulario «a Derecho» no querría decir nada.
     */
    public const FACETAS_CON_AMBITO = [CatalogoPermisos::ASPIRANTE, CatalogoPermisos::ALUMNO];

    protected $table = 'formulario_asignacion';

    protected $fillable = [
        'formulario_id',
        'rol_id',
        'ambito_tipo',
        'ambito_id',
        'obligatorio',
    ];

    protected function casts(): array
    {
        return [
            'obligatorio' => 'boolean',
        ];
    }

    public function formulario(): BelongsTo
    {
        return $this->belongsTo(Formulario::class);
    }

    public function rol(): BelongsTo
    {
        return $this->belongsTo(Rol::class);
    }

    /** ¿A este rol se le puede recortar el formulario por programa académico? */
    public static function admiteAmbito(?Rol $rol): bool
    {
        return $rol !== null && in_array($rol->ambitoDePermisos(), self::FACETAS_CON_AMBITO, true);
    }

    /** Filtra las asignaciones acotadas a un ámbito concreto. */
    public function scopeParaAmbito(Builder $query, string $tipo, int $id): Builder
    {
        return $query->where('ambito_tipo', $tipo)->where('ambito_id', $id);
    }
}
