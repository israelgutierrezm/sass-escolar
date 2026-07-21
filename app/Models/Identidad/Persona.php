<?php

declare(strict_types=1);

namespace App\Models\Identidad;

use App\Models\Concerns\TieneAuditoria;
use App\Models\Landlord\EntidadFederativa;
use App\Models\Landlord\Genero;
use App\Models\Landlord\Pais;
use App\Models\Landlord\Sexo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
}
