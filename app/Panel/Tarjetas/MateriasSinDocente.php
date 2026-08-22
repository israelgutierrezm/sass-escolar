<?php

declare(strict_types=1);

namespace App\Panel\Tarjetas;

use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\ControlEscolar\Ciclo;
use App\Models\Identidad\Usuario;
use App\Panel\TarjetaPanel;

/**
 * Materias abiertas del ciclo que todavía no tienen quién las firme.
 *
 * ── Por qué ésta y no «materias sin docente» ──────────────────────────────
 * Se busca el TITULAR, no «algún docente». El titular es quien firma el acta,
 * así que una materia con adjunto y sin titular sigue sin poder cerrar el
 * periodo — y ése es el problema que la tarjeta viene a destapar, no la falta
 * de personal en general.
 *
 * ── El renglón lleva al GRUPO, no a la materia ────────────────────────────
 * Porque el detalle del grupo ya calcula cuáles de sus materias están sin
 * docente y las PRESELECCIONA en la asignación en lote. Se cae directo en el
 * gesto que resuelve la cola, en vez de en una pantalla desde la que todavía
 * hay que navegar.
 *
 * ── El ciclo vigente se pregunta, no se deduce ────────────────────────────
 * `Ciclo::enCurso()` es el mismo resolutor que usan captura, docencia,
 * horarios e inscripciones. Busca por FECHA y no por situación a propósito: el
 * demo tiene veinte ciclos «cerrados» y uno «abierto» que ya terminó.
 */
class MateriasSinDocente implements TarjetaPanel
{
    /** Es una cola con enlaces, no un reporte: pasadas de aquí, se resume. */
    private const A_LA_VISTA = 6;

    public function clave(): string
    {
        return 'materias-sin-docente';
    }

    public function titulo(): string
    {
        return 'Materias sin titular';
    }

    public function permiso(): ?string
    {
        return 'abrir-grupos';
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
        return 'M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z';
    }

    public function datos(Usuario $usuario): ?array
    {
        /*
         * Todos los renglones entran a `/escolar`, y ese prefijo entero exige
         * `ver-grupos`. Un rol con `abrir-grupos` y sin `ver-grupos` vería una
         * cola cuyos enlaces le responden 403, que es peor que no verla.
         *
         * El permiso declarado sigue siendo `abrir-grupos` porque es el que deja
         * ASIGNAR: es lo que convierte la cola en algo que esa persona puede
         * resolver, y no sólo mirar.
         */
        if (! $usuario->can('ver-grupos')) {
            return null;
        }

        $ciclo = Ciclo::enCurso();

        if ($ciclo === null) {
            return null;
        }

        $campus = $usuario->campusVisibles();

        $materias = AsignaturaGrupo::query()
            ->whereHas('grupo', fn ($grupo) => $grupo
                ->where('ciclo_id', $ciclo->id)
                // null = alcance global; un arreglo lo acota. null ≠ arreglo vacío.
                ->when($campus !== null, fn ($q) => $q->whereIn('campus_id', $campus))
                // Un grupo cancelado o cerrado no reclama titular.
                ->whereDoesntHave('situacion', fn ($s) => $s->whereIn('clave', ['cancelado', 'cerrado'])))
            /*
             * «Abierta» se define EXCLUYENDO «cerrada», no exigiendo «activa».
             * Una materia con la situación en null sigue necesitando titular, y
             * con el catálogo a medio sembrar la cola se vaciaría en silencio.
             */
            ->whereDoesntHave('situacion', fn ($s) => $s->where('clave', 'cerrada'))
            ->whereDoesntHave('docentes', fn ($d) => $d->where('docente_asignatura_grupo.tipo', 'titular'))
            ->with([
                'planMateria.asignatura:id,nombre',
                'grupo:id,clave,nombre,campus_id',
                'grupo.campus:id,nombre',
            ])
            ->get()
            /*
             * Ordenar con un callback que devuelve ARREGLO compara elemento a
             * elemento. Pegando las dos claves en una cadena («P3|Álgebra») el
             * orden se rompe en cuanto una clave es prefijo de otra.
             */
            ->sortBy(fn (AsignaturaGrupo $m) => [
                $m->grupo?->clave ?? '',
                $m->planMateria?->asignatura?->nombre ?? '',
            ])
            ->values();

        // Cola de trabajo: sin nada pendiente, no se dibuja. «0 materias sin
        // titular» es la normalidad, y anunciarla a diario enseña a no mirarla.
        if ($materias->isEmpty()) {
            return null;
        }

        return [
            'renglones' => $materias->take(self::A_LA_VISTA)->map(fn (AsignaturaGrupo $m) => [
                'etiqueta' => $m->planMateria?->asignatura?->nombre ?? 'Materia',
                'detalle' => $this->donde($m, $campus === null),
                'valor' => 'sin titular',
                'pie' => null,
                'progreso' => null,
                'alerta' => true,
                'enlace' => "/escolar/grupos/{$m->grupo_id}",
            ])->values()->all(),
            'pie' => $this->pie($materias->count(), $ciclo->clave),
            'enlace' => "/escolar/grupos?ciclo_id={$ciclo->id}",
        ];
    }

    /**
     * El grupo y, sólo con alcance global, el campus.
     *
     * A quien está acotado a un campus, repetírselo en cada renglón es ruido:
     * ya sabe dónde trabaja.
     */
    private function donde(AsignaturaGrupo $materia, bool $global): ?string
    {
        $piezas = array_filter([
            $materia->grupo?->clave,
            $global ? $materia->grupo?->campus?->nombre : null,
        ]);

        return $piezas === [] ? null : implode(' · ', $piezas);
    }

    private function pie(int $total, string $ciclo): string
    {
        $sobran = $total - self::A_LA_VISTA;

        return $sobran > 0
            ? "y {$sobran} más sin titular en {$ciclo}"
            : "sin titular en {$ciclo}";
    }
}
