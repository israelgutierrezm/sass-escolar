<?php

declare(strict_types=1);

namespace App\Models\Emision;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;

/**
 * titulacion_ws_config (TENANT) — la configuración única del web service de
 * Títulos Electrónicos de la SEP para la escuela.
 *
 * Guarda los dos juegos de credenciales (pruebas y producción) y la `etapa_activa`
 * que decide cuál está vigente. Las contraseñas van CIFRADAS (`encrypted`): en la
 * base no se leen en claro y al frontend solo se mandan enmascaradas.
 */
class TitulacionWsConfig extends Model
{
    use TieneAuditoria;

    protected $table = 'titulacion_ws_config';

    public const ETAPA_PRUEBAS = 'pruebas';

    public const ETAPA_PRODUCCION = 'produccion';

    /** @var array<string, string> */
    protected $attributes = [
        'etapa_activa' => 'pruebas',
    ];

    protected $fillable = [
        'etapa_activa',
        'usuario_pruebas',
        'password_pruebas',
        'usuario_produccion',
        'password_produccion',
        'conexion_estado',
        'conexion_mensaje',
        'conexion_probada_en',
    ];

    protected function casts(): array
    {
        return [
            // Cifrado en reposo: la contraseña nunca queda en claro en la BD.
            'password_pruebas' => 'encrypted',
            'password_produccion' => 'encrypted',
            'conexion_probada_en' => 'datetime',
        ];
    }

    /** La única fila de configuración; se crea vacía si no existe. */
    public static function actual(): self
    {
        return static::query()->firstOrCreate([]);
    }

    /** ¿La etapa vigente es producción? */
    public function esProduccion(): bool
    {
        return $this->etapa_activa === self::ETAPA_PRODUCCION;
    }

    /** ¿Hay usuario y contraseña capturados para una etapa dada? */
    public function tieneCredenciales(string $etapa): bool
    {
        return $etapa === self::ETAPA_PRODUCCION
            ? filled($this->usuario_produccion) && filled($this->password_produccion)
            : filled($this->usuario_pruebas) && filled($this->password_pruebas);
    }

    /**
     * Credenciales (en claro, solo para el cliente SOAP) de una etapa; por
     * defecto la activa.
     *
     * @return array{usuario: ?string, password: ?string}
     */
    public function credenciales(?string $etapa = null): array
    {
        $etapa ??= $this->etapa_activa;

        return $etapa === self::ETAPA_PRODUCCION
            ? ['usuario' => $this->usuario_produccion, 'password' => $this->password_produccion]
            : ['usuario' => $this->usuario_pruebas, 'password' => $this->password_pruebas];
    }

    /**
     * Versión enmascarada de una contraseña: solo indica que existe, sin
     * exponerla. Nunca se manda la contraseña completa al frontend.
     */
    public static function enmascarar(?string $secreto): ?string
    {
        return filled($secreto) ? '••••••••' : null;
    }
}
