<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Models\Lms\Rubrica;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Elegir con qué rúbrica se califica una actividad.
 *
 * Lo comparten los dos sitios donde se arma una actividad —el curso del docente
 * y la plantilla del plan—, y no son el mismo caso:
 *
 * ── En la PLANTILLA del plan, sólo las de la escuela ───────────────────────
 * La plantilla se copia a todos los grupos que abran esa materia, en todos los
 * campus y ciclos. Una rúbrica PROPIA de quien edita el plan terminaría
 * calificando en grupos que dan otras personas —que ni siquiera la pueden ver,
 * porque las propias son de su dueño—. Lo que es de la escuela se arma con lo
 * de la escuela.
 *
 * ── En el curso del DOCENTE, las de la escuela y las suyas ─────────────────
 * Ahí sí: es su materia, su grupo y su forma de calificar.
 *
 * La comprobación va en el servidor y no sólo en el desplegable: la pantalla
 * ofrece lo que toca, pero el POST llega igual con el id que sea.
 */
trait EligeRubrica
{
    /**
     * Las que se pueden ofrecer para amarrar, más la que ya esté puesta.
     *
     * `$yaPuesta` entra aunque esté apagada: apagar una rúbrica la retira del
     * catálogo para lo nuevo, no de las actividades donde ya está. Sin esto, la
     * pantalla mostraría el selector vacío y el primer guardado la desamarraría
     * sin que nadie lo pidiera.
     *
     * @return Collection<int, array<string, mixed>>
     */
    protected function rubricasDisponibles(?int $personaId, bool $soloDeLaEscuela, ?int $yaPuesta = null): Collection
    {
        return Rubrica::query()
            ->with('criterios.niveles')
            ->where(fn ($q) => $q
                ->where(fn ($vigentes) => $vigentes
                    ->activas()
                    ->when(
                        $soloDeLaEscuela,
                        fn ($p) => $p->where('ambito', Rubrica::PLATAFORMA),
                        fn ($p) => $p->visiblesPara($personaId),
                    ))
                ->when($yaPuesta !== null, fn ($q2) => $q2->orWhere('id', $yaPuesta)))
            ->orderBy('ambito')
            ->orderBy('nombre')
            ->get()
            ->map(fn (Rubrica $r) => [
                'id' => $r->id,
                'nombre' => $r->nombre,
                'ambito' => $r->ambito,
                'total' => $r->total(),
                'criterios' => $r->criterios->count(),
                'activa' => (bool) $r->activa,
            ]);
    }

    /**
     * Que la rúbrica elegida se pueda usar aquí. Revienta con mensaje si no.
     *
     * @param  int|null  $yaPuesta  la que la actividad ya tenía, que se conserva
     */
    protected function exigirRubricaUsable(?int $rubricaId, ?int $personaId, bool $soloDeLaEscuela, ?int $yaPuesta = null): void
    {
        if ($rubricaId === null) {
            return;
        }

        $rubrica = Rubrica::query()->with('criterios.niveles')->find($rubricaId);

        if ($rubrica === null) {
            throw ValidationException::withMessages(['rubrica_id' => 'Esa rúbrica no existe.']);
        }

        $suya = $soloDeLaEscuela
            ? $rubrica->esDePlataforma()
            : ($rubrica->esDePlataforma() || ((int) $rubrica->persona_id === $personaId && $personaId !== null));

        if (! $suya) {
            throw ValidationException::withMessages([
                'rubrica_id' => $soloDeLaEscuela
                    ? 'En la plantilla del plan sólo se pueden usar rúbricas de la escuela: es la que se copia a todos los grupos.'
                    : 'Esa rúbrica no es tuya ni de la escuela.',
            ]);
        }

        // Apagada sólo se acepta si es la que ya estaba: cambiar A una apagada
        // sería sacarla del catálogo y usarla el mismo día.
        if (! $rubrica->activa && $rubricaId !== $yaPuesta) {
            throw ValidationException::withMessages([
                'rubrica_id' => 'Esa rúbrica está apagada: enciéndela en el catálogo si la quieres volver a usar.',
            ]);
        }

        /*
         * Y que sirva para calificar. Hoy no debería poder pasar —el catálogo
         * rechaza las que suman cero— pero es la comprobación que separa «una
         * rúbrica mal armada» de «treinta alumnos con cero y nadie sabe por
         * qué», así que se hace en el sitio donde importa.
         */
        if (! $rubrica->calificable()) {
            throw ValidationException::withMessages([
                'rubrica_id' => 'Esa rúbrica suma cero puntos: no se puede calificar con ella.',
            ]);
        }
    }
}
