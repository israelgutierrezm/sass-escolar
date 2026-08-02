<?php

declare(strict_types=1);

namespace App\Services\Plataforma;

use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\Finanzas\Adeudo;
use App\Models\Identidad\Usuario;
use App\Models\Lms\Actividad;
use App\Models\Lms\Curso;
use App\Models\Lms\Entrega;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cuánto hay esperando detrás de cada acceso directo.
 *
 * ── Para qué ───────────────────────────────────────────────────────────────
 * «Aspirantes» es navegación —eso ya lo hace el menú—. «Aspirantes · 12 sin
 * contactar» es una razón para entrar, o para no hacerlo. Ese número es lo
 * único que distingue un atajo útil de un segundo menú.
 *
 * ── Se paga sólo lo que se mira ────────────────────────────────────────────
 * Cada contador se calcula únicamente si la persona tiene el permiso del acceso
 * al que acompaña, y son consultas de conteo sobre columnas indexadas. Quien no
 * entra a finanzas no paga la consulta de la cartera.
 *
 * ── El cero no se dice ─────────────────────────────────────────────────────
 * Un «0 por calificar» ocupa el mismo lugar que un dato útil y entrena a la
 * gente a ignorar la cifra. Sin nada que reportar, se devuelve null y el acceso
 * queda como un atajo limpio.
 */
class PendientesDeAcceso
{
    /** @var array<string, array<string, mixed>|null> */
    private array $cache = [];

    /**
     * @return array{cantidad: int, texto: string, urgente: bool}|null
     */
    public function de(string $clave, Usuario $usuario): ?array
    {
        return $this->cache[$clave] ??= match ($clave) {
            'porCalificar' => $this->porCalificar($usuario),
            'porEntregar' => $this->porEntregar($usuario),
            'aspirantesSinEtapa' => $this->aspirantesNuevos(),
            'adeudosVencidos' => $this->adeudosVencidos(),
            default => null,
        };
    }

    /**
     * Entregas esperando revisión en las materias del docente.
     *
     * @return array{cantidad: int, texto: string, urgente: bool}|null
     */
    private function porCalificar(Usuario $usuario): ?array
    {
        if ($usuario->persona_id === null) {
            return null;
        }

        $materias = AsignaturaGrupo::query()
            ->whereHas('docentes', fn ($q) => $q->where('docentes.persona_id', $usuario->persona_id))
            ->pluck('id');

        if ($materias->isEmpty()) {
            return null;
        }

        $cursos = Curso::query()->whereIn('asignatura_grupo_id', $materias)->pluck('id');

        if ($cursos->isEmpty()) {
            return null;
        }

        $cantidad = Entrega::query()
            ->whereIn('actividad_id', Actividad::query()->whereIn('curso_id', $cursos)->select('id'))
            ->whereNotNull('entregada_en')
            ->whereNull('calificacion')
            ->count();

        return $this->cifra($cantidad, 'por calificar', 'por calificar');
    }

    /**
     * Lo que al alumno le falta entregar y todavía puede.
     *
     * @return array{cantidad: int, texto: string, urgente: bool}|null
     */
    private function porEntregar(Usuario $usuario): ?array
    {
        if ($usuario->persona_id === null) {
            return null;
        }

        $inscripciones = DB::table('inscripcion')
            ->join('matricula_oferta', 'matricula_oferta.id', '=', 'inscripcion.matricula_oferta_id')
            ->where('matricula_oferta.persona_id', $usuario->persona_id)
            ->whereNull('inscripcion.deleted_at')
            ->pluck('inscripcion.id', 'inscripcion.asignatura_grupo_id');

        if ($inscripciones->isEmpty()) {
            return null;
        }

        $cursos = Curso::query()
            ->whereIn('asignatura_grupo_id', $inscripciones->keys())
            ->pluck('id');

        if ($cursos->isEmpty()) {
            return null;
        }

        // Sólo lo que sigue abierto: recordar lo que ya no se puede entregar no
        // le sirve a nadie.
        $actividades = Actividad::query()
            ->visibles()
            ->whereIn('curso_id', $cursos)
            ->where(fn ($q) => $q->whereNull('cierra_en')->orWhere('cierra_en', '>=', now()))
            ->get(['id', 'tipo'])
            ->filter(fn (Actividad $a) => $a->tipo->seEntrega());

        if ($actividades->isEmpty()) {
            return null;
        }

        $entregadas = Entrega::query()
            ->whereIn('inscripcion_id', $inscripciones->values())
            ->whereIn('actividad_id', $actividades->pluck('id'))
            ->whereNotNull('entregada_en')
            ->count();

        return $this->cifra(
            max(0, $actividades->count() - $entregadas),
            'por entregar',
            'por entregar',
        );
    }

    /**
     * Prospectos que nadie ha movido de la primera etapa del embudo.
     *
     * @return array{cantidad: int, texto: string, urgente: bool}|null
     */
    private function aspirantesNuevos(): ?array
    {
        if (! Schema::hasTable('aspirantes')) {
            return null;
        }

        $primera = DB::table('etapas_crm')->orderBy('orden')->value('id');

        if ($primera === null) {
            return null;
        }

        $cantidad = DB::table('aspirantes')
            ->whereNull('deleted_at')
            ->where('etapa_crm_id', $primera)
            ->count();

        return $this->cifra($cantidad, 'sin contactar', 'sin contactar');
    }

    /**
     * Adeudos con fecha vencida.
     *
     * @return array{cantidad: int, texto: string, urgente: bool}|null
     */
    private function adeudosVencidos(): ?array
    {
        if (! Schema::hasTable('adeudos')) {
            return null;
        }

        /*
         * Por el MODELO y su scope, no con SQL a mano: la columna se llama
         * `estatus` (no `estado`) y qué cuenta como «por cobrar» ya está
         * decidido en `Adeudo::porCobrar()`. Escribirlo aquí otra vez sería
         * tener dos definiciones de lo mismo, y la de aquí se quedaría vieja.
         */
        $cantidad = Adeudo::query()
            ->porCobrar()
            ->whereDate('fecha_vencimiento', '<', now())
            ->count();

        // Un adeudo vencido sí es urgente: es dinero que la escuela ya debería
        // haber cobrado.
        return $this->cifra($cantidad, 'vencidos', 'vencidos', true);
    }

    /**
     * @return array{cantidad: int, texto: string, urgente: bool}|null
     */
    private function cifra(int $cantidad, string $singular, string $plural, bool $urgente = false): ?array
    {
        if ($cantidad === 0) {
            return null;
        }

        return [
            'cantidad' => $cantidad,
            'texto' => $cantidad === 1 ? $singular : $plural,
            'urgente' => $urgente,
        ];
    }
}
