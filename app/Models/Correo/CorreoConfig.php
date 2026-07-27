<?php

declare(strict_types=1);

namespace App\Models\Correo;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;

/**
 * correo_config (TENANT) — la configuración de correo (SMTP/Gmail) de la escuela.
 *
 * La contraseña de aplicación va CIFRADA y nunca sale completa al frontend
 * (solo se indica si está o no configurada). Ver la migración.
 */
class CorreoConfig extends Model
{
    use TieneAuditoria;

    protected $table = 'correo_config';

    protected $attributes = [
        'activo' => false,
        'host' => 'smtp.gmail.com',
        'puerto' => 587,
        'cifrado' => 'tls',
    ];

    protected $fillable = [
        'activo',
        'host',
        'puerto',
        'cifrado',
        'usuario',
        'password',
        'remitente_correo',
        'remitente_nombre',
        'prueba_estado',
        'prueba_mensaje',
        'prueba_en',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
            'password' => 'encrypted',
            'prueba_en' => 'datetime',
        ];
    }

    public static function actual(): self
    {
        return static::query()->firstOrCreate([]);
    }

    /** ¿Está lista para enviar? (activa y con usuario y contraseña). */
    public function utilizable(): bool
    {
        return $this->activo && filled($this->usuario) && filled($this->password);
    }
}
