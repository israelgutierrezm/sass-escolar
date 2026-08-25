<?php

declare(strict_types=1);

namespace App\Reportes;

use App\Exceptions\AvisoParaElUsuario;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * Cómo se acota una fuente a los campus que el rol alcanza.
 *
 * ── Por qué es OBLIGATORIO declararlo ────────────────────────────────────
 * Cada tabla llega al campus por un camino distinto: una matrícula por su
 * oferta, un aspirante por su propia columna, un docente por una relación de
 * muchos a muchos, un empleado por su adscripción. **Un `whereIn('campus_id',
 * …)` genérico filtraría a CERO en cuatro de los cinco caminos** —la columna no
 * existe—, y una fuente que se olvidara del recorte no filtraría NADA y
 * enseñaría la escuela entera, que es el fallo silencioso peor de los dos.
 *
 * Al ser un método abstracto de la interfaz, olvidarlo es un error al construir
 * la clase, no una filtración en producción.
 *
 * ── `sinCampus` obliga a escribir el porqué ──────────────────────────────
 * Hay fuentes que de verdad no se acotan —un catálogo de la escuela—. Para esas
 * se declara explícitamente CON SU RAZÓN, y aun así sólo las ejecuta quien
 * tiene alcance global: a un rol acotado se le niega el reporte con esa razón
 * escrita, en vez de darle la escuela entera. Falla cerrado.
 */
final readonly class Recorte
{
    public const POR_OFERTA = 'oferta';

    public const POR_COLUMNA = 'columna';

    public const POR_RELACION = 'relacion';

    public const POR_ADSCRIPCION = 'adscripcion';

    public const SIN_CAMPUS = 'sin_campus';

    private function __construct(
        public string $modo,
        public array $args = [],
        public ?string $razon = null,
    ) {}

    /**
     * La fila llega al campus por su OFERTA: matrículas, adeudos, historial.
     *
     * @param  string|null  $relacion  hacia la matrícula; null si la consulta ya es de matrículas
     */
    public static function porOferta(?string $relacion = null): self
    {
        return new self(self::POR_OFERTA, ['relacion' => $relacion]);
    }

    /** La tabla tiene su propio `campus_id`: aspirantes, grupos, aulas. */
    public static function porColumnaPropia(string $columna = 'campus_id'): self
    {
        return new self(self::POR_COLUMNA, ['columna' => $columna]);
    }

    /** Llega por una relación de muchos a muchos: docentes. */
    public static function porRelacion(string $relacion = 'campus'): self
    {
        return new self(self::POR_RELACION, ['relacion' => $relacion]);
    }

    /** Personal: el campus sale de su adscripción vigente. */
    public static function porAdscripcion(string $relacion = 'adscripciones'): self
    {
        return new self(self::POR_ADSCRIPCION, ['relacion' => $relacion]);
    }

    /** No se acota, y se dice por qué. Sólo lo ejecuta quien ve toda la escuela. */
    public static function sinCampus(string $razon): self
    {
        if (trim($razon) === '') {
            throw new InvalidArgumentException('Una fuente sin recorte tiene que decir por qué.');
        }

        return new self(self::SIN_CAMPUS, [], $razon);
    }

    /**
     * Aplica el recorte.
     *
     * @param  array<int, int>|null  $campus  null = alcance global
     */
    public function aplicar(Builder $consulta, ?array $campus): Builder
    {
        // Alcance global: no hay nada que recortar.
        if ($campus === null) {
            return $consulta;
        }

        if ($this->modo === self::SIN_CAMPUS) {
            /*
             * A un rol acotado se le NIEGA, con la razón escrita en la fuente.
             * Devolverle la escuela entera sería exactamente la fuga que este
             * objeto existe para impedir.
             */
            throw new AvisoParaElUsuario(
                403,
                'Este reporte no se puede acotar por campus, así que sólo lo ejecuta quien ve toda la escuela. '.$this->razon,
            );
        }

        return match ($this->modo) {
            self::POR_OFERTA => $this->porLaOferta($consulta, $campus),
            self::POR_COLUMNA => $consulta->where(fn (Builder $q) => $q
                ->whereIn($this->args['columna'], $campus)
                // Las filas SIN campus se dejan pasar: un aspirante que todavía
                // no eligió a dónde entrar no es de nadie, y esconderlo de todo
                // el mundo lo convierte en un prospecto que nadie atiende.
                ->orWhereNull($this->args['columna'])),
            self::POR_RELACION => $consulta->where(fn (Builder $q) => $q
                ->whereHas($this->args['relacion'], fn (Builder $c) => $c->whereIn('campus.id', $campus))
                ->orWhereDoesntHave($this->args['relacion'])),
            self::POR_ADSCRIPCION => $consulta->whereHas(
                $this->args['relacion'],
                fn (Builder $a) => $a->whereIn('campus_id', $campus),
            ),
            default => $consulta,
        };
    }

    private function porLaOferta(Builder $consulta, array $campus): Builder
    {
        $porOferta = fn (Builder $q) => $q->whereHas('oferta', fn (Builder $o) => $o->whereIn('campus_id', $campus));

        return $this->args['relacion'] === null
            ? $consulta->where(fn (Builder $q) => $porOferta($q))
            : $consulta->whereHas($this->args['relacion'], fn (Builder $q) => $porOferta($q));
    }
}
