<?php

declare(strict_types=1);

namespace App\Models\Asistencia;

use App\Models\Academico\Campus;
use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * dispositivos_checador (TENANT) — punto de fichaje de un campus.
 */
class DispositivoChecador extends Model
{
    use TieneAuditoria;

    public const TIPO_QR = 'qr';
    public const TIPO_BIOMETRICO = 'biometrico';
    public const TIPO_GEOCERCA = 'geocerca';
    public const TIPO_MANUAL = 'manual';

    protected $table = 'dispositivos_checador';

    protected $fillable = [
        'campus_id',
        'tipo',
        'identificador',
        'geocerca_lat',
        'geocerca_lng',
        'geocerca_radio_m',
        'tolerancia_min',
    ];

    protected function casts(): array
    {
        return [
            'geocerca_lat' => 'float',
            'geocerca_lng' => 'float',
        ];
    }

    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    public function checadas(): HasMany
    {
        return $this->hasMany(Checada::class, 'dispositivo_id');
    }

    /**
     * ¿La coordenada cae dentro del radio de la geocerca?
     *
     * Distancia por fórmula del haversine (metros). Devuelve false si el
     * dispositivo no tiene geocerca configurada: sin centro ni radio no se
     * puede afirmar que el fichaje sea válido.
     */
    public function dentroDeGeocerca(float $lat, float $lng): bool
    {
        if ($this->geocerca_lat === null || $this->geocerca_lng === null || $this->geocerca_radio_m === null) {
            return false;
        }

        $radioTierra = 6_371_000; // metros
        $dLat = deg2rad($lat - $this->geocerca_lat);
        $dLng = deg2rad($lng - $this->geocerca_lng);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($this->geocerca_lat)) * cos(deg2rad($lat)) * sin($dLng / 2) ** 2;

        $distancia = 2 * $radioTierra * asin(min(1.0, sqrt($a)));

        return $distancia <= $this->geocerca_radio_m;
    }
}
