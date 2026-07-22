<?php

declare(strict_types=1);

namespace App\Configuracion;

/**
 * Un ajuste configurable de la escuela.
 *
 * Se declara en `CatalogoAjustes` con su tipo, su valor por omisión y —lo más
 * importante— QUÉ CAMBIA cuando lo mueves. Un número suelto en una pantalla de
 * configuración no dice nada; lo que hace útil a un ajuste es que explique la
 * consecuencia antes de que alguien la descubra en producción.
 */
final readonly class Ajuste
{
    public const BOOLEANO = 'booleano';

    public const ENTERO = 'entero';

    public const TEXTO = 'texto';

    public const SELECCION = 'seleccion';

    /**
     * @param  array<string, string>  $opciones  solo para SELECCION
     * @param  string|null  $consecuencia  qué pasa al cambiarlo, en una frase
     */
    public function __construct(
        public string $clave,
        public string $grupo,
        public string $etiqueta,
        public string $descripcion,
        public string $tipo,
        public string|int|bool|null $porDefecto,
        public array $opciones = [],
        public ?int $min = null,
        public ?int $max = null,
        public ?string $consecuencia = null,
    ) {}

    /** Convierte el valor guardado (siempre texto) al tipo declarado. */
    public function convertir(?string $valor): string|int|bool|null
    {
        if ($valor === null) {
            return $this->porDefecto;
        }

        return match ($this->tipo) {
            self::BOOLEANO => in_array(strtolower($valor), ['1', 'true', 'si', 'sí'], true),
            self::ENTERO => (int) $valor,
            default => $valor,
        };
    }

    /** Cómo se guarda en la tabla, que es de texto. */
    public function serializar(mixed $valor): string
    {
        return match ($this->tipo) {
            self::BOOLEANO => $valor ? '1' : '0',
            self::ENTERO => (string) (int) $valor,
            default => (string) $valor,
        };
    }
}
