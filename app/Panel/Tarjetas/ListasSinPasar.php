<?php

declare(strict_types=1);

namespace App\Panel\Tarjetas;

use App\Models\Asistencia\AsistenciaClase;
use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\ControlEscolar\Ciclo;
use App\Models\ControlEscolar\HorarioAsignaturaGrupo;
use App\Models\Identidad\Usuario;
use App\Panel\TarjetaPanel;

/**
 * Materias con clase HOY a las que todavía no se les pasa lista.
 *
 * ── El alcance sale de la ASIGNACIÓN, no del permiso ──────────────────────
 * El par de siempre en este proyecto: el permiso dice QUÉ se puede hacer y la
 * tabla de docentes dice SOBRE QUÉ. Aquí importa además por una razón concreta:
 * la única pantalla donde se pasa lista vive bajo `/docencia`, que exige
 * `ver-mis-materias` —permiso de la faceta docente—. Contar las listas de toda
 * la escuela le daría a control escolar una cifra con un botón que le responde
 * 403. Si algún día se le abre pantalla propia, el alcance se amplía aquí sin
 * tocar nada más.
 *
 * ── Sin horario cargado, la tarjeta no existe ─────────────────────────────
 * No hay respaldo, y es deliberado: sin bloque de horario no se sabe qué
 * materia tenía clase hoy, y contar «todas las materias» sería inventárselo.
 *
 * ── Se cuentan MATERIAS, no sesiones ──────────────────────────────────────
 * Una materia con doble pase de lista (teoría y práctica) se da por atendida en
 * cuanto exista cualquier renglón de hoy. Afinarlo exigiría saber qué bloque
 * del horario es teórico y cuál práctico, y la modalidad del horario guarda
 * otra cosa —presencial o en línea—. Subcuenta un caso raro; la alternativa
 * sería inventar un vínculo que los datos no tienen.
 */
class ListasSinPasar implements TarjetaPanel
{
    public function clave(): string
    {
        return 'listas-sin-pasar';
    }

    public function titulo(): string
    {
        return 'Listas sin pasar';
    }

    public function permiso(): ?string
    {
        return 'pasar-lista';
    }

    public function tipo(): string
    {
        return 'metrica';
    }

    public function ancho(): int
    {
        return 2;
    }

    public function icono(): string
    {
        return 'M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 0 0 2.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 0 0-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25Z';
    }

    public function datos(Usuario $usuario): ?array
    {
        $ciclo = Ciclo::enCurso();

        if ($usuario->persona_id === null || $ciclo === null) {
            return null;
        }

        $materias = AsignaturaGrupo::query()
            ->whereHas('grupo', fn ($g) => $g->where('ciclo_id', $ciclo->id))
            /*
             * El filtro va por `docentes.persona_id` y no por `personas.id`: la
             * relación cuelga de la tabla `docentes`, cuya llave ES la persona.
             * Confundirlas es el bug que hizo que el alcance del docente no se
             * aplicara nunca.
             */
            ->whereHas('docentes', fn ($d) => $d->where('docentes.persona_id', $usuario->persona_id))
            ->pluck('id');

        if ($materias->isEmpty()) {
            return null;
        }

        // `N` de PHP: 1 lunes … 7 domingo, que es como se guarda `dia_semana`.
        $conClaseHoy = HorarioAsignaturaGrupo::query()
            ->whereIn('asignatura_grupo_id', $materias)
            ->where('dia_semana', (int) date('N'))
            ->distinct()
            ->pluck('asignatura_grupo_id');

        if ($conClaseHoy->isEmpty()) {
            return null;
        }

        $faltan = $conClaseHoy->diff($this->yaPasadas($conClaseHoy->all()))->count();

        /*
         * Cola de trabajo: en cero no se dibuja. Y hay tres motivos distintos
         * para callarse —no imparte nada, hoy no le toca, o ya las pasó todas—,
         * los tres legítimos. Un «0 listas pendientes» diario sería mobiliario.
         */
        if ($faltan === 0) {
            return null;
        }

        return [
            'valor' => $faltan,
            'formato' => 'entero',
            'pie' => $faltan === 1
                ? 'materia con clase hoy y sin lista'
                : 'materias con clase hoy y sin lista',
            'alerta' => true,
            'enlace' => '/docencia',
        ];
    }

    /**
     * Cuáles ya tienen algún registro de hoy.
     *
     * Es la MISMA consulta con la que `/docencia` decide su «ya pasaste lista»,
     * a propósito: si el panel y la pantalla contestaran distinto, quien vea el
     * aviso entraría a una materia que ya está atendida.
     *
     * @param  array<int, int>  $materias
     */
    private function yaPasadas(array $materias)
    {
        return AsistenciaClase::query()
            ->join('inscripcion', 'inscripcion.id', '=', 'asistencia_clase.inscripcion_id')
            ->whereIn('inscripcion.asignatura_grupo_id', $materias)
            ->whereDate('asistencia_clase.fecha', now()->toDateString())
            ->distinct()
            ->pluck('inscripcion.asignatura_grupo_id');
    }
}
