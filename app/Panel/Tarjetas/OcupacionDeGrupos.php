<?php

declare(strict_types=1);

namespace App\Panel\Tarjetas;

use App\Models\ControlEscolar\Ciclo;
use App\Models\ControlEscolar\Grupo;
use App\Models\Identidad\Usuario;
use App\Panel\TarjetaPanel;

/**
 * Qué tan llenos van los grupos del ciclo.
 *
 * Contesta las dos preguntas que control escolar se hace a media inscripción:
 * dónde ya no cabe nadie y dónde falta gente. Por eso el cero SÍ se muestra —un
 * grupo en 0/30 es el caso más accionable de todos— y lo único que oculta la
 * tarjeta es no tener ningún grupo con cupo que medir.
 *
 * ── El porcentaje va en `valor`, y no es un descuido ──────────────────────
 * El Vue dibuja la barra RELATIVA al mayor de la serie y escribe `valor` crudo
 * a la derecha; `porcentaje` sólo lo lee hoy la tarjeta de encuestas. Poniendo
 * las cabezas en `valor`, un grupo 25/30 y otro 25/100 saldrían con la MISMA
 * barra — justo lo contrario de lo que se viene a ver. Con la ocupación en
 * `valor`, la barra y la cifra dicen lo mismo. Es lo que ya hace «Continuar
 * donde me quedé», la otra tarjeta de barras del panel.
 *
 * `porcentaje` se manda igual, con el mismo número: cumple el contrato
 * declarado sin costar nada y evita volver aquí el día que el Vue lo lea.
 *
 * ── Cuántos alumnos tiene un grupo lo decide el MODELO ────────────────────
 * `Grupo::scopeConAlumnos` — matrículas distintas, sin las bajas. Copiarlo aquí
 * sería el camino directo a que el panel diga 3 y la pantalla de grupos 17.
 */
class OcupacionDeGrupos implements TarjetaPanel
{
    /** Más de ocho barras dejan de compararse de un vistazo. */
    private const A_LA_VISTA = 8;

    public function clave(): string
    {
        return 'ocupacion-de-grupos';
    }

    public function titulo(): string
    {
        return 'Ocupación de los grupos';
    }

    public function permiso(): ?string
    {
        return 'ver-grupos';
    }

    public function tipo(): string
    {
        return 'barras';
    }

    public function ancho(): int
    {
        return 2;
    }

    public function icono(): string
    {
        return 'M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z';
    }

    public function datos(Usuario $usuario): ?array
    {
        $ciclo = Ciclo::enCurso();

        if ($ciclo === null) {
            return null;
        }

        $campus = $usuario->campusVisibles();

        $grupos = Grupo::query()
            ->where('ciclo_id', $ciclo->id)
            ->when($campus !== null, fn ($q) => $q->whereIn('campus_id', $campus))
            ->conAlumnos()
            ->get(['id', 'clave', 'nombre', 'cupo'])
            /*
             * Sin denominador no hay ocupación que dibujar. `cupo` es NOT NULL,
             * pero una columna NOT NULL admite el cero perfectamente.
             */
            ->filter(fn (Grupo $g) => (int) $g->cupo > 0)
            ->map(fn (Grupo $g) => [
                'grupo' => $g,
                'inscritos' => (int) ($g->alumnos_count ?? 0),
                // Acotado a 100: un grupo sobrecupo es un problema aparte, y una
                // barra al 130 % rompería la escala de las demás.
                'ocupacion' => min(100, (int) round(((int) ($g->alumnos_count ?? 0)) * 100 / (int) $g->cupo)),
            ])
            ->sortByDesc('ocupacion')
            ->values();

        if ($grupos->isEmpty()) {
            return null;
        }

        return [
            'series' => $grupos->take(self::A_LA_VISTA)->map(fn (array $g) => [
                // Los conteos crudos van aquí porque `barras` no tiene detalle:
                // así se ven las dos cifras, cuántos y qué tan lleno.
                'etiqueta' => ($g['grupo']->clave ?? $g['grupo']->nombre).' · '.$g['inscritos'].'/'.$g['grupo']->cupo,
                'valor' => $g['ocupacion'],
                'porcentaje' => $g['ocupacion'],
            ])->values()->all(),
            'pie' => $this->pie($grupos->count(), $ciclo->clave),
            'enlace' => "/escolar/grupos?ciclo_id={$ciclo->id}",
        ];
    }

    /** Empieza por «%» porque la cifra se pinta desnuda: un «83» suelto no dice de qué. */
    private function pie(int $total, string $ciclo): string
    {
        $sobran = $total - self::A_LA_VISTA;

        return $sobran > 0
            ? "% del cupo ocupado en {$ciclo}; los {$sobran} restantes van más vacíos"
            : "% del cupo ocupado en {$ciclo}";
    }
}
