<?php

declare(strict_types=1);

namespace App\Models\Plataforma;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * avisos_adjuntos (TENANT) — el PDF o el enlace que acompaña a un aviso.
 *
 * Los dos tipos viven juntos porque para quien recibe el aviso son lo mismo:
 * algo más que consultar, con su nombre, en la misma lista. Ver la migración
 * para el razonamiento completo.
 */
class AvisoAdjunto extends Model
{
    use TieneAuditoria;

    public const ARCHIVO = 'archivo';

    public const ENLACE = 'enlace';

    protected $table = 'avisos_adjuntos';

    protected $fillable = [
        'aviso_id',
        'tipo',
        'titulo',
        'ruta',
        'url',
        'uuid',
        'nombre_original',
        'mime',
        'tamano',
        'orden',
    ];

    protected function casts(): array
    {
        return ['tamano' => 'integer', 'orden' => 'integer'];
    }

    protected static function booted(): void
    {
        // El uuid nace con la fila: es la dirección por la que se sirve el
        // archivo y no puede depender de que quien lo cree se acuerde.
        static::creating(function (self $adjunto) {
            if ($adjunto->tipo === self::ARCHIVO && $adjunto->uuid === null) {
                $adjunto->uuid = (string) Str::uuid();
            }
        });
    }

    public function aviso(): BelongsTo
    {
        return $this->belongsTo(Aviso::class, 'aviso_id');
    }

    public function esArchivo(): bool
    {
        return $this->tipo === self::ARCHIVO;
    }

    /** A dónde apunta en la pantalla: el disco de la escuela o el sitio de fuera. */
    public function direccion(): string
    {
        return $this->esArchivo()
            ? route('tenant.misavisos.adjunto', ['uuid' => $this->uuid])
            : (string) $this->url;
    }

    /** «2.4 MB». Null para un enlace, que no ocupa nada nuestro. */
    public function pesoLegible(): ?string
    {
        if ($this->tamano === null) {
            return null;
        }

        $unidades = ['B', 'KB', 'MB', 'GB'];
        $valor = (float) $this->tamano;
        $i = 0;

        while ($valor >= 1024 && $i < count($unidades) - 1) {
            $valor /= 1024;
            $i++;
        }

        return round($valor, $valor < 10 && $i > 0 ? 1 : 0).' '.$unidades[$i];
    }
}
