<?php

declare(strict_types=1);

namespace App\Models\Identidad;

use App\Models\Admisiones\MatriculaOferta;
use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * credenciales (TENANT) — la emisión de una credencial. Ver la migración.
 */
class Credencial extends Model
{
    use TieneAuditoria;

    protected $table = 'credenciales';

    protected $fillable = ['uuid', 'persona_id', 'rol_id', 'matricula_oferta_id', 'emitida_en'];

    protected function casts(): array
    {
        return ['emitida_en' => 'datetime'];
    }

    /**
     * El uuid se pone al crear, no lo escribe quien llama.
     *
     * Es lo que hace la dirección del QR inadivinable, así que dejarlo en manos
     * del que inserta significa que un día alguien lo olvide y se cree una
     * credencial con uuid vacío —que además chocaría con la siguiente—.
     */
    protected static function booted(): void
    {
        static::creating(function (self $credencial) {
            $credencial->uuid ??= (string) Str::uuid();
        });
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'persona_id');
    }

    public function matricula(): BelongsTo
    {
        return $this->belongsTo(MatriculaOferta::class, 'matricula_oferta_id');
    }

    public function rol(): BelongsTo
    {
        return $this->belongsTo(Rol::class, 'rol_id');
    }

    /** La clave por la que se busca en la ruta pública. */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
