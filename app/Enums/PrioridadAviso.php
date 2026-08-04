<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Cuánto insiste un aviso.
 *
 * ── Por qué son tres y no cinco ────────────────────────────────────────────
 * Cada nivel tiene que significar algo DISTINTO en la pantalla; si dos se ven
 * casi igual, quien publica no sabe cuál elegir y termina poniendo el más alto
 * «por si acaso». Con tres, la diferencia es evidente: uno se ignora, otro
 * estorba y el tercero no deja pasar.
 *
 * ── Y por qué el más alto se usa poco ──────────────────────────────────────
 * Un aviso que obliga a confirmar la lectura interrumpe a la persona en lo que
 * venía a hacer. Sirve para lo que de verdad no puede pasarse por alto —una
 * suspensión de clases, un cambio de sede de examen— y deja de servir en cuanto
 * se usa para el festival de primavera: a la tercera vez, todo el mundo confirma
 * sin leer y el mecanismo queda inutilizado para el día que importe.
 */
enum PrioridadAviso: string
{
    /** Aparece en su lugar y se ignora sin consecuencia. */
    case Informativo = 'informativo';

    /** Se muestra destacado y se puede cerrar, pero vuelve en cada sesión. */
    case Importante = 'importante';

    /** Bloquea hasta que la persona confirma que lo leyó. */
    case Critico = 'critico';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Informativo => 'Informativo',
            self::Importante => 'Importante',
            self::Critico => 'Crítico',
        };
    }

    /** Qué implica, en las palabras de quien va a elegirlo. */
    public function descripcion(): string
    {
        return match ($this) {
            self::Informativo => 'Aparece entre los avisos. Quien no lo abra, no lo lee.',
            self::Importante => 'Se muestra destacado al entrar. Se puede cerrar, pero reaparece en cada sesión hasta que se marque como leído.',
            self::Critico => 'Bloquea la pantalla hasta que la persona confirme que lo leyó. Queda constancia de quién confirmó y cuándo.',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Informativo => '#0891b2',
            self::Importante => '#d97706',
            self::Critico => '#dc2626',
        };
    }

    /** Sólo el crítico exige confirmación explícita. */
    public function exigeConfirmacion(): bool
    {
        return $this === self::Critico;
    }

    /** @return array<int, array<string, string>> */
    public static function paraSelector(): array
    {
        return array_map(fn (self $p) => [
            'valor' => $p->value,
            'texto' => $p->etiqueta(),
            'descripcion' => $p->descripcion(),
            'color' => $p->color(),
        ], self::cases());
    }
}
