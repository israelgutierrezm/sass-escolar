<?php

declare(strict_types=1);

namespace App\Services\Banco;

use App\Exceptions\AvisoParaElUsuario;

/**
 * Cómo se lee el archivo del banco de una cuenta.
 *
 * ── Configurable, no cableado ──────────────────────────────────────────────
 * Cada banco exporta a su manera y no hay dos iguales. Un `match ($banco)` en
 * el código significaría que la escuela que abre cuenta en otro banco no puede
 * conciliar hasta que alguien programe. Aquí la forma del archivo es un dato de
 * la cuenta.
 *
 * ── Las columnas se identifican por su NOMBRE, no por su posición ──────────
 * Si mañana el banco agrega una columna al principio, los índices se recorren
 * TODOS y el importador leería los importes de otra columna sin fallar: la
 * conciliación saldría con cifras inventadas y nada se quejaría. Por el nombre,
 * lo que pasa es que no encuentra la columna y lo dice.
 *
 * ── Cargo y abono en columnas separadas es lo NORMAL ───────────────────────
 * Casi ningún estado de cuenta mexicano trae una sola columna con signo, así
 * que soportar sólo eso habría dejado fuera a la mayoría. Se admiten las dos
 * formas y se guarda siempre con signo.
 */
class MapeoEstadoCuenta
{
    private const OMISION = [
        'delimitador' => ',',
        'renglon_encabezado' => 1,
        'formato_fecha' => 'd/m/Y',
        'columna_fecha' => 'Fecha',
        'columna_descripcion' => 'Descripción',
        'columna_referencia' => null,
        // O bien `columna_monto` (una sola, con signo), o bien el par
        // cargo/abono. Nunca las dos cosas.
        'columna_monto' => null,
        'columna_cargo' => 'Cargo',
        'columna_abono' => 'Abono',
    ];

    /** @param array<string, mixed> $datos */
    private function __construct(private readonly array $datos) {}

    /** @param array<string, mixed>|null $guardado */
    public static function desde(?array $guardado): self
    {
        return new self(array_merge(self::OMISION, array_filter(
            $guardado ?? [],
            fn ($v, $k) => array_key_exists($k, self::OMISION),
            ARRAY_FILTER_USE_BOTH,
        )));
    }

    /** @return array<string, mixed> */
    public static function porOmision(): array
    {
        return self::OMISION;
    }

    /**
     * Valida lo que la escuela capturó, ANTES de guardarlo.
     *
     * Un mapeo incoherente no revienta al guardarse: revienta meses después, en
     * mitad de una importación, y quien lo captura ya no está.
     *
     * @param  array<string, mixed>  $datos
     */
    public static function validar(array $datos): void
    {
        $mapeo = self::desde($datos);

        AvisoParaElUsuario::si(
            $mapeo->columnaMonto() === null && ($mapeo->columnaCargo() === null || $mapeo->columnaAbono() === null),
            422,
            'Dinos de qué columna sale el importe: o una sola con signo, o el par cargo y abono.',
        );

        AvisoParaElUsuario::si(
            $mapeo->columnaMonto() !== null && ($mapeo->columnaCargo() !== null || $mapeo->columnaAbono() !== null),
            422,
            'Elige una forma sola: una columna con signo, O el par cargo y abono. Con las dos no se sabría cuál manda.',
        );

        AvisoParaElUsuario::si(
            trim((string) $mapeo->columnaFecha()) === '' || trim((string) $mapeo->columnaDescripcion()) === '',
            422,
            'Hacen falta la columna de la fecha y la del concepto.',
        );

        AvisoParaElUsuario::si(
            $mapeo->renglonEncabezado() < 1,
            422,
            'El renglón del encabezado se cuenta desde 1.',
        );
    }

    public function delimitador(): string
    {
        $d = (string) $this->datos['delimitador'];

        // Un delimitador de más de un carácter no lo entiende `fgetcsv` y
        // dejaría el archivo entero en una sola columna; «tab» se escribe así
        // porque en un formulario no se puede teclear un tabulador.
        return match ($d) {
            'tab', '\\t' => "\t",
            '' => ',',
            default => mb_substr($d, 0, 1),
        };
    }

    public function renglonEncabezado(): int
    {
        return (int) $this->datos['renglon_encabezado'];
    }

    public function formatoFecha(): string
    {
        return (string) $this->datos['formato_fecha'];
    }

    public function columnaFecha(): ?string
    {
        return $this->texto('columna_fecha');
    }

    public function columnaDescripcion(): ?string
    {
        return $this->texto('columna_descripcion');
    }

    public function columnaReferencia(): ?string
    {
        return $this->texto('columna_referencia');
    }

    public function columnaMonto(): ?string
    {
        return $this->texto('columna_monto');
    }

    public function columnaCargo(): ?string
    {
        return $this->texto('columna_cargo');
    }

    public function columnaAbono(): ?string
    {
        return $this->texto('columna_abono');
    }

    /** @return array<string, mixed> */
    public function comoArreglo(): array
    {
        return $this->datos;
    }

    private function texto(string $clave): ?string
    {
        $v = $this->datos[$clave] ?? null;

        return is_string($v) && trim($v) !== '' ? trim($v) : null;
    }
}
