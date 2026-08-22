<?php

declare(strict_types=1);

namespace App\Models\Bolsa;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * empresas (TENANT) — un empleador registrado en la bolsa de trabajo.
 *
 * La empresa se APAGA con su situación, no se borra: «vetada» es una empresa
 * con la que la escuela no quiere volver a trabajar, pero cuyas colocaciones
 * históricas siguen contando para los reportes de acreditación. Borrarla se
 * llevaría esa historia.
 */
class Empresa extends Model
{
    use TieneAuditoria;

    protected $table = 'empresas';

    protected $fillable = [
        'razon_social',
        'rfc',
        'sector_id',
        'tamano_id',
        'sitio_web',
        'situacion_id',
        'notas',
    ];

    public function sector(): BelongsTo
    {
        return $this->belongsTo(SectorEconomico::class, 'sector_id');
    }

    public function tamano(): BelongsTo
    {
        return $this->belongsTo(TamanoEmpresa::class, 'tamano_id');
    }

    public function situacion(): BelongsTo
    {
        return $this->belongsTo(SituacionEmpresa::class, 'situacion_id');
    }

    public function contactos(): HasMany
    {
        return $this->hasMany(EmpresaContacto::class, 'empresa_id');
    }

    /** Con quién se habla, si alguien lo marcó. */
    public function contactoPrincipal(): HasMany
    {
        return $this->contactos()->where('es_principal', true);
    }

    /**
     * Las que pueden publicar vacantes.
     *
     * Se define por lo que NO es —vetada— y no exigiendo «activa»: una escuela
     * que renombre su catálogo o agregue «en convenio» seguiría publicando, y
     * una empresa con la situación en null no debería desaparecer en silencio.
     * Lo que hay que impedir es lo vetado, y eso sí se dice por su nombre.
     */
    public function scopePublicables(Builder $consulta): Builder
    {
        return $consulta->whereDoesntHave('situacion', fn (Builder $q) => $q->where('clave', 'vetada'));
    }
}
