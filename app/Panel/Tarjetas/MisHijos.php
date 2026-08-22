<?php

declare(strict_types=1);

namespace App\Panel\Tarjetas;

use App\Models\Admisiones\MatriculaOferta;
use App\Models\Identidad\Persona;
use App\Models\Identidad\Usuario;
use App\Panel\TarjetaPanel;
use App\Services\EstadoDelAlumno;
use Illuminate\Support\Collection;

/**
 * Cómo van los hijos, en el panel del padre o de la madre.
 *
 * ── Qué se enseña y por qué eso ───────────────────────────────────────────
 * Lo mismo que ya calcula `/mis-hijos` con {@see EstadoDelAlumno}: promedio,
 * materias reprobadas y saldo. No se recalcula ninguna de las tres — el día que
 * el panel y la pantalla dieran promedios distintos, nadie sabría cuál creer.
 *
 * ── Lo que se puede ver lo dice el VÍNCULO, no un permiso ─────────────────
 * `tutores_alumno` guarda `puede_ver_academico` y `puede_ver_finanzas` por
 * pareja, y el servicio NO calcula lo que no se autoriza: el dato ni siquiera
 * existe, en vez de existir y ocultarse en la vista. Un padre con acceso sólo
 * académico ve el promedio de su hijo y ni una palabra de dinero.
 *
 * ── Se muestran TODOS, aunque no haya nada pendiente ──────────────────────
 * No es una cola de trabajo: es la situación de su familia, y «va bien y no
 * debe nada» es exactamente lo que un padre entra a confirmar. Se devuelve null
 * sólo cuando no hay vínculos, para que un administrativo que se conceda el
 * permiso no vea una tarjeta vacía.
 */
class MisHijos implements TarjetaPanel
{
    public function __construct(private readonly EstadoDelAlumno $estado) {}

    public function clave(): string
    {
        return 'mis-hijos';
    }

    public function titulo(): string
    {
        return 'Mis hijos';
    }

    public function permiso(): ?string
    {
        return 'ver-mis-hijos';
    }

    public function tipo(): string
    {
        return 'lista';
    }

    public function ancho(): int
    {
        return 2;
    }

    public function icono(): string
    {
        return 'M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z';
    }

    public function datos(Usuario $usuario): ?array
    {
        if ($usuario->persona_id === null || $usuario->persona === null) {
            return null;
        }

        // La relación ya excluye los vínculos dados de baja y trae el pivote con
        // lo que este padre puede ver; no hay nada que filtrar a mano.
        $hijos = $usuario->persona->hijos()->get();

        if ($hijos->isEmpty()) {
            return null;
        }

        // Lo que estudian TODOS, en una sola consulta: una por hijo sería N+1 en
        // una tarjeta que se pinta en cada carga del panel.
        $matriculas = MatriculaOferta::query()
            ->whereIn('persona_id', $hijos->pluck('id'))
            ->with('oferta.carrera:id,nombre')
            ->get()
            ->groupBy('persona_id');

        return [
            'renglones' => $hijos
                ->map(fn (Persona $hijo) => $this->renglon($hijo, $matriculas->get($hijo->id) ?? collect()))
                ->values()
                ->all(),
            'pie' => null,
            'enlace' => '/mis-hijos',
        ];
    }

    /**
     * @param  Collection<int, MatriculaOferta>  $matriculas
     * @return array<string, mixed>
     */
    private function renglon(Persona $hijo, $matriculas): array
    {
        $vinculo = $hijo->pivot;

        $estado = $this->estado->de(
            $hijo,
            (bool) $vinculo->puede_ver_academico,
            (bool) $vinculo->puede_ver_finanzas,
        );

        $carreras = $matriculas
            ->map(fn (MatriculaOferta $m) => $m->oferta?->carrera?->nombre)
            ->filter()
            ->unique()
            ->values();

        $debe = ($estado['saldo'] ?? 0) > 0;

        return [
            'etiqueta' => $hijo->nombreCompleto(),
            /*
             * La carrera identifica mejor que el parentesco —el nombre ya dice
             * quién es—, y el parentesco sólo entra cuando todavía no estudia
             * nada, que es cuando no hay carrera que poner.
             */
            'detalle' => $carreras->isNotEmpty()
                ? ($carreras->count() === 1 ? $carreras->first() : $carreras->count().' programas')
                : (filled($vinculo->parentesco) ? $vinculo->parentesco : null),
            'valor' => $this->valor($matriculas->isEmpty(), $debe, $estado),
            'pie' => $this->pie($estado, $debe, $matriculas->isNotEmpty()),
            'alerta' => (bool) $estado['vencido'] || ($estado['reprobadas'] ?? 0) > 0,
            'progreso' => null,
            'enlace' => "/mis-hijos/{$hijo->id}",
        ];
    }

    /**
     * Lo que va a la derecha: lo más urgente que haya de ese hijo.
     *
     * Un adeudo manda sobre el promedio porque tiene fecha; el promedio manda
     * sobre nada. Y sin matrícula se dice «Aún sin inscripción» en vez de «Sin
     * calificaciones»: son cosas distintas y la segunda hace pensar que la
     * escuela no ha capturado, cuando lo que falta es inscribirlo.
     *
     * @param  array<string, mixed>  $estado
     */
    private function valor(bool $sinMatricula, bool $debe, array $estado): string
    {
        return match (true) {
            $sinMatricula => 'Aún sin inscripción',
            $debe => '$'.number_format((float) $estado['saldo'], 2),
            $estado['promedio'] !== null => 'Promedio '.$estado['promedio'],
            default => 'Sin calificaciones',
        };
    }

    /** @param  array<string, mixed>  $estado */
    private function pie(array $estado, bool $debe, bool $inscrito): ?string
    {
        $piezas = [];

        // El promedio baja al pie sólo cuando el adeudo le quitó el sitio de
        // arriba: si no, se estaría diciendo dos veces.
        if ($debe && $estado['promedio'] !== null) {
            $piezas[] = 'promedio '.$estado['promedio'];
        }

        if (($estado['reprobadas'] ?? 0) > 0) {
            $piezas[] = $estado['reprobadas'] === 1 ? '1 reprobada' : $estado['reprobadas'].' reprobadas';
        }

        // «Sin adeudo» sólo si de verdad se miró la cartera: con el vínculo sin
        // acceso financiero, `saldo` viene en null y callar es lo correcto.
        if ($estado['saldo'] !== null && ! $debe && $inscrito) {
            $piezas[] = 'sin adeudo';
        }

        if ($estado['vencido']) {
            $piezas[] = 'vencido';
        }

        return $piezas === [] ? null : implode(' · ', $piezas);
    }
}
