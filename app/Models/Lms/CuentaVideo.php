<?php

declare(strict_types=1);

namespace App\Models\Lms;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * cuentas_videoconferencia (TENANT) — el pool de anfitriones.
 *
 * Qué es una fila aquí depende del proveedor, y por eso el modelo no lo decide:
 * en Zoom es una LICENCIA que sostiene una reunión a la vez —tantas como clases
 * simultáneas quiera la escuela—; en Meet es la identidad que organiza el evento
 * y no se agota. Lo declara `ProveedoresVideoCatalogo::unaReunionPorCuenta` y lo
 * lee el asignador.
 */
class CuentaVideo extends Model
{
    use TieneAuditoria;

    protected $table = 'cuentas_videoconferencia';

    protected $fillable = ['proveedor', 'etiqueta', 'identificador', 'activa'];

    protected function casts(): array
    {
        return ['activa' => 'boolean'];
    }

    public function sesiones(): HasMany
    {
        return $this->hasMany(Videoconferencia::class, 'cuenta_id');
    }

    public function scopeActivas(Builder $query): Builder
    {
        return $query->where('activa', true);
    }

    public function scopeDe(Builder $query, string $proveedor): Builder
    {
        return $query->where('proveedor', $proveedor);
    }

    /**
     * Si está libre en esa ventana.
     *
     * Dos reuniones se estorban cuando una empieza antes de que la otra termine
     * y termina después de que la otra empieza. Se comparan las dos condiciones
     * y no sólo el inicio: una clase de 9 a 11 y otra de 10 a 10:30 no comparten
     * hora de arranque y chocan igual.
     *
     * `$excepto` deja reprogramar una clase sin que choque contra sí misma.
     */
    public function libreEntre(string $inicio, string $fin, ?int $excepto = null): bool
    {
        return ! $this->sesiones()
            ->whereIn('estado', [Videoconferencia::PROGRAMADA, Videoconferencia::EN_CURSO])
            ->when($excepto !== null, fn ($q) => $q->whereKeyNot($excepto))
            ->where('inicio', '<', $fin)
            ->where('fin', '>', $inicio)
            ->exists();
    }
}
