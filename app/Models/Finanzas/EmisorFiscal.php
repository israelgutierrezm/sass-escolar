<?php

declare(strict_types=1);

namespace App\Models\Finanzas;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * emisores_fiscales (TENANT) — las razones sociales con las que factura la
 * escuela.
 *
 * Una escuela puede tener varias personas morales: bachillerato con una,
 * licenciatura con otra, posgrado con otra. Cada una timbra con SU certificado
 * de sello digital, porque el CSD es de la persona moral y no de la
 * instalación.
 *
 * Qué factura cada una vive en `emisor_asignaciones`, no aquí: una misma razón
 * social puede cubrir un nivel completo y además una carrera suelta.
 */
class EmisorFiscal extends Model
{
    use TieneAuditoria;

    protected $table = 'emisores_fiscales';

    protected $attributes = [
        'activo' => true,
    ];

    protected $fillable = [
        'rfc',
        'razon_social',
        'regimen_fiscal',
        'cp',
        'certificado_ruta',
        'llave_ruta',
        'llave_password',
        'pac_usuario',
        'pac_password',
        'activo',
    ];

    /**
     * Las credenciales nunca se guardan en claro. `encrypted` las cifra con la
     * APP_KEY al escribir y las descifra al leer, así que un volcado de la base
     * —o un respaldo que acabe donde no debe— no entrega la llave con la que se
     * timbra a nombre de la escuela.
     */
    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
            'llave_password' => 'encrypted',
            'pac_usuario' => 'encrypted',
            'pac_password' => 'encrypted',
        ];
    }

    /** Nunca se serializan hacia el front: son secretos, no datos de pantalla. */
    protected $hidden = [
        'llave_password',
        'pac_usuario',
        'pac_password',
    ];

    public function asignaciones(): HasMany
    {
        return $this->hasMany(EmisorAsignacion::class, 'emisor_id');
    }

    public function facturas(): HasMany
    {
        return $this->hasMany(Factura::class, 'emisor_id');
    }

    /**
     * Si tiene con qué timbrar. Un emisor sin certificado se puede dar de alta
     * —la escuela captura primero los datos y sube los archivos después— pero
     * la pantalla debe poder avisar que todavía no factura.
     */
    public function puedeTimbrar(): bool
    {
        return $this->activo
            && $this->certificado_ruta !== null
            && $this->llave_ruta !== null;
    }

    public function scopeActivos(Builder $query): Builder
    {
        return $query->where('activo', true);
    }
}
