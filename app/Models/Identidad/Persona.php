<?php

declare(strict_types=1);

namespace App\Models\Identidad;

use App\Models\Concerns\TieneAuditoria;
use App\Models\Landlord\EntidadFederativa;
use App\Models\Landlord\Genero;
use App\Models\Landlord\Pais;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\ControlEscolar\Docente;
use App\Models\Landlord\Sexo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * personas (TENANT) — identidad única. Persona ≠ rol: una persona puede ser
 * aspirante, alumno, egresado, docente y administrativo a la vez.
 *
 * Las relaciones a catálogos LANDLORD (sexo, genero, pais, entidad) cruzan de
 * la BD del tenant a la central. Resuelven porque los modelos destino usan el
 * trait CentralConnection; no hay FK real a nivel de base de datos.
 */
class Persona extends Model
{
    use TieneAuditoria;

    protected $table = 'personas';

    protected $fillable = [
        'curp',
        'rfc',
        'nombre',
        'primer_apellido',
        'segundo_apellido',
        'fecha_nacimiento',
        'sexo_id',
        'genero_id',
        'pais_nacimiento_id',
        'entidad_nacimiento_id',
        'email',
        'correo_institucional',
        'celular',
        'foto_url',
    ];

    protected function casts(): array
    {
        return [
            'fecha_nacimiento' => 'date',
        ];
    }

    public function sexo(): BelongsTo
    {
        return $this->belongsTo(Sexo::class);
    }

    public function genero(): BelongsTo
    {
        return $this->belongsTo(Genero::class);
    }

    public function paisNacimiento(): BelongsTo
    {
        return $this->belongsTo(Pais::class, 'pais_nacimiento_id');
    }

    public function entidadNacimiento(): BelongsTo
    {
        return $this->belongsTo(EntidadFederativa::class, 'entidad_nacimiento_id');
    }

    /** Asignaciones de rol (multi-rol simultáneo, con alcance por campus). */
    public function asignacionesRol(): HasMany
    {
        return $this->hasMany(PersonaRol::class, 'persona_id');
    }

    /** Roles que la persona puede ejercer ahora mismo. */
    public function rolesActivos(): BelongsToMany
    {
        return $this->belongsToMany(Rol::class, 'persona_rol', 'persona_id', 'rol_id')
            ->wherePivot('activo', true)
            ->withPivot(['campus_id', 'activo'])
            ->withTimestamps();
    }

    /** Credenciales de acceso; no toda persona tiene usuario. */
    public function usuario(): HasOne
    {
        return $this->hasOne(Usuario::class, 'persona_id');
    }

    /**
     * Sus matrículas: una persona puede cursar varias carreras a la vez o a lo
     * largo del tiempo, y cada una es un "alumno" distinto con su kárdex.
     */
    public function matriculas(): HasMany
    {
        return $this->hasMany(MatriculaOferta::class, 'persona_id');
    }

    /** Su registro docente, si da clase. PK compartida con personas. */
    public function docente(): HasOne
    {
        return $this->hasOne(Docente::class, 'persona_id');
    }

    /** Ruta autenticada de su foto; null si no tiene. Nunca la ruta del disco. */
    public function urlFoto(): ?string
    {
        return $this->foto_url === null ? null : "/personas/{$this->id}/foto";
    }

    public function nombreCompleto(): string
    {
        return trim("{$this->nombre} {$this->primer_apellido} {$this->segundo_apellido}");
    }
}
