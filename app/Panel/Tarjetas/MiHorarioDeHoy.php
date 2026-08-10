<?php

declare(strict_types=1);

namespace App\Panel\Tarjetas;

use App\Models\Admisiones\MatriculaOferta;
use App\Models\ControlEscolar\HorarioAsignaturaGrupo;
use App\Models\ControlEscolar\Inscripcion;
use App\Models\Identidad\Usuario;
use App\Panel\TarjetaPanel;

/**
 * A qué hora y dónde tiene clase HOY.
 *
 * ── Lo primero que se pregunta al entrar ───────────────────────────────────
 * Un alumno abre el sistema en la mañana con una pregunta muy concreta: qué le
 * toca hoy y a qué hora. Hasta ahora tenía que ir materia por materia a mirar
 * horarios, y el panel —que es la pantalla de inicio— no lo decía.
 *
 * ── Sólo hoy ───────────────────────────────────────────────────────────────
 * La semana completa es otra pantalla: aquí cabe lo del día, y meter cinco días
 * convertiría la tarjeta en una tabla que nadie lee de reojo. Lo que ya pasó se
 * queda —sirve para saber si uno faltó a algo— pero se marca, y lo que sigue va
 * destacado.
 */
class MiHorarioDeHoy implements TarjetaPanel
{
    public function clave(): string
    {
        return 'mi-horario';
    }

    public function titulo(): string
    {
        return 'Mis clases de hoy';
    }

    public function permiso(): ?string
    {
        return 'ver-mis-cursos';
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
        return 'M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z';
    }

    public function datos(Usuario $usuario): ?array
    {
        if ($usuario->persona_id === null) {
            return null;
        }

        $materias = Inscripcion::query()
            ->whereIn(
                'matricula_oferta_id',
                MatriculaOferta::query()
                    ->where('persona_id', $usuario->persona_id)
                    ->select('id'),
            )
            ->pluck('asignatura_grupo_id');

        if ($materias->isEmpty()) {
            return null;
        }

        // `N` de PHP: 1 es lunes, 7 domingo. Es como se guarda `dia_semana`.
        $hoy = (int) date('N');

        $clases = HorarioAsignaturaGrupo::query()
            ->whereIn('asignatura_grupo_id', $materias)
            ->where('dia_semana', $hoy)
            ->with([
                'asignaturaGrupo.planMateria.asignatura:id,nombre',
                'asignaturaGrupo.grupo:id,clave',
                'aula:id,nombre',
            ])
            ->orderBy('hora_inicio')
            ->get();

        // Sin clases hoy no se dibuja la tarjeta: un «no tienes clases» todos
        // los domingos ocupa el lugar de algo útil.
        if ($clases->isEmpty()) {
            return null;
        }

        $ahora = date('H:i');

        return [
            'renglones' => $clases->map(function (HorarioAsignaturaGrupo $h) use ($ahora) {
                $inicio = substr((string) $h->hora_inicio, 0, 5);
                $fin = substr((string) $h->hora_fin, 0, 5);

                $enCurso = $ahora >= $inicio && $ahora <= $fin;
                $paso = $ahora > $fin;

                return [
                    'etiqueta' => $h->asignaturaGrupo?->planMateria?->asignatura?->nombre ?? 'Materia',
                    'detalle' => trim(implode(' · ', array_filter([
                        $h->aula?->nombre,
                        $h->asignaturaGrupo?->grupo?->clave,
                        $enCurso ? 'ahora' : ($paso ? 'ya pasó' : null),
                    ]))),
                    'valor' => "{$inicio}–{$fin}",
                    'enlace' => '/mis-cursos/'.$h->asignatura_grupo_id,
                ];
            })->values()->all(),
        ];
    }
}
