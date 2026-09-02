<?php

declare(strict_types=1);

namespace App\Models\Finanzas;

use App\Models\Academico\Campus;
use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * cajas (TENANT-CONFIG) — un mostrador donde se recibe dinero.
 *
 * Cuelga del CAMPUS porque una caja es un lugar físico y el efectivo no viaja
 * entre planteles: cuadrar «la caja de la escuela» juntando el cajón del campus
 * norte con el del centro no significa nada, y esconde justo el faltante que se
 * busca.
 *
 * Se APAGA, no se borra: sus sesiones cerradas son los cortes, y borrarla se
 * los llevaría.
 */
class Caja extends Model
{
    use TieneAuditoria;

    protected $table = 'cajas';

    protected $fillable = ['clave', 'nombre', 'campus_id', 'activa'];

    protected function casts(): array
    {
        return ['activa' => 'boolean'];
    }

    /**
     * Las encendidas.
     *
     * Scope propio y no el del trait `SePuedeApagar`: ese filtra por `activo` y
     * aquí la columna es `activa`, como en `cuentas_bancarias`. Con el trait
     * puesto la consulta revienta con «Unknown column», y lo hace SÓLO desde la
     * pantalla —la suite no lo veía porque no llamaba al scope—.
     */
    public function scopeActivas($consulta)
    {
        return $consulta->where('activa', true);
    }

    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class, 'campus_id');
    }

    public function sesiones(): HasMany
    {
        return $this->hasMany(SesionCaja::class, 'caja_id');
    }

    /** La sesión abierta, si la hay. Como mucho una: lo sostiene la base. */
    public function sesionAbierta(): ?SesionCaja
    {
        return $this->sesiones()->whereNull('cerrada_en')->first();
    }
}
