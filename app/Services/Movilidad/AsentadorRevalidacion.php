<?php

declare(strict_types=1);

namespace App\Services\Movilidad;

use App\Models\ControlEscolar\Historial;
use App\Models\Movilidad\DictamenRevalidacion;
use App\Models\Movilidad\Estancia;
use App\Models\Movilidad\Revalidacion;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Dictaminar una revalidación y ASENTARLA en el historial académico.
 *
 * Es el gesto más delicado del sistema: escribe una calificación en el
 * expediente oficial de alguien, y de ahí sale su certificado ante la SEP.
 *
 * ── No se inventa una columna «origen»: se usan los catálogos OFICIALES ───
 * `tipos_evaluacion.revalidacion` y el `observaciones_asignatura` de la SEP con
 * «REVALIDACIÓN DE ESTUDIOS» ya existían, y ese segundo valor es el que viaja en
 * el XML del certificado. Una bandera propia habría dejado el dato fuera del
 * documento oficial y habría creado una segunda forma de decir lo mismo.
 *
 * ── Sin acta, y a propósito ───────────────────────────────────────────────
 * `acta_id` y `acta_folio` quedan en NULL porque una revalidación no sale de un
 * acta: sale de un dictamen. Inventarle un folio de acta la haría indistinguible
 * de una materia cursada aquí.
 *
 * ── Sólo al SALIENTE, y con la estancia CONCLUIDA ─────────────────────────
 * A un entrante no se le escribe historial académico nuestro —no tiene—. Y
 * mientras la estancia siga en curso las calificaciones de allá no están
 * cerradas: asentar una a medias metería en el expediente un número que todavía
 * puede cambiar.
 *
 * ── Y no se revalida lo que ya está APROBADO ──────────────────────────────
 * `HistorialDelAlumno` toma el mejor intento por materia para los totales, así
 * que un segundo asiento de algo ya aprobado le regalaría los créditos dos
 * veces. Sobre una materia REPROBADA sí se puede: ahí la revalidación es un
 * intento legítimo, igual que un recursamiento.
 */
class AsentadorRevalidacion
{
    /** Claves de los catálogos oficiales que ya existían. */
    private const TIPO_EVALUACION = 'revalidacion';

    private const OBSERVACION_SEP = 'revalidacion_estudios';

    private const ESTATUS_APROBADA = 'aprobada';

    /**
     * Aplica el dictamen. Si el dictamen ASIENTA, escribe el renglón.
     *
     * @throws RuntimeException si no se puede asentar
     */
    public function dictaminar(Revalidacion $revalidacion, DictamenRevalidacion $dictamen): Revalidacion
    {
        if ($revalidacion->estaAsentada()) {
            throw new RuntimeException(
                'Esa revalidación ya está asentada en el historial. Para corregirla hay que revocarla.'
            );
        }

        if (! $dictamen->asienta) {
            // Se guarda el dictamen y no se escribe nada. Rechazar también es
            // una decisión que hay que poder registrar.
            $revalidacion->update([
                'dictamen_id' => $dictamen->id,
                'dictaminada_en' => now(),
            ]);

            return $revalidacion->refresh();
        }

        $motivo = $this->motivoParaNoAsentar($revalidacion);

        if ($motivo !== null) {
            throw new RuntimeException($motivo);
        }

        $matricula = $revalidacion->estancia?->postulacion?->matricula;

        return DB::transaction(function () use ($revalidacion, $dictamen, $matricula) {
            $renglon = Historial::create([
                'matricula_oferta_id' => $matricula->id,
                'plan_materia_id' => $revalidacion->plan_materia_id,
                'ciclo_id' => $revalidacion->ciclo_id,

                // Sin grupo: no la cursó aquí.
                'asignatura_grupo_id' => null,

                'tipo_evaluacion_id' => $this->idDe('tipos_evaluacion', self::TIPO_EVALUACION),
                'estatus_id' => $this->idDe('estatus_historial', self::ESTATUS_APROBADA),
                'calificacion' => $revalidacion->calificacion_equivalente,

                // Sin acta: sale de un dictamen, no de un cierre de materia.
                'acta_id' => null,
                'acta_folio' => null,

                // La observación OFICIAL de la SEP. Es lo que viaja al
                // certificado y lo que hace innecesaria una columna propia.
                'observacion_asignatura_id' => $this->idDe('observaciones_asignatura', self::OBSERVACION_SEP),
            ]);

            $revalidacion->update([
                'dictamen_id' => $dictamen->id,
                'dictaminada_en' => now(),
                'historial_id' => $renglon->id,
            ]);

            return $revalidacion->refresh();
        });
    }

    /**
     * Deshace un asiento hecho por error.
     *
     * ── Se da de BAJA LÓGICA, no se borra ────────────────────────────────
     * Es la misma decisión que el acta de corrección: el renglón se conserva con
     * su auditoría —quién lo puso y quién lo quitó— porque es historia escolar.
     * Y la revalidación vuelve a quedar sin dictaminar, para poder rehacerla con
     * la calificación correcta.
     */
    public function revocar(Revalidacion $revalidacion, DictamenRevalidacion $pendiente): Revalidacion
    {
        if (! $revalidacion->estaAsentada()) {
            throw new RuntimeException('Esa revalidación no está asentada.');
        }

        return DB::transaction(function () use ($revalidacion, $pendiente) {
            $revalidacion->historial?->delete();

            $revalidacion->update([
                'historial_id' => null,
                'dictamen_id' => $pendiente->id,
                'dictaminada_en' => null,
            ]);

            return $revalidacion->refresh();
        });
    }

    /**
     * Por qué NO se puede asentar, o null si se puede.
     *
     * Devuelve el motivo en vez de un booleano para que la pantalla lo enseñe:
     * «no se puede» sin decir por qué obliga a adivinar.
     */
    public function motivoParaNoAsentar(Revalidacion $revalidacion): ?string
    {
        $estancia = $revalidacion->estancia;
        $postulacion = $estancia?->postulacion;

        if ($postulacion === null || ! $postulacion->esSaliente()) {
            return 'Sólo se le revalidan materias a un alumno saliente: un entrante no tiene historial académico aquí.';
        }

        if (! $estancia->estaConcluida()) {
            return 'La estancia todavía no está concluida, así que las calificaciones de allá no están cerradas.';
        }

        $matricula = $postulacion->matricula;

        if ($matricula === null) {
            return 'Esa postulación perdió su matrícula: no hay historial al que asentar.';
        }

        /*
         * Que la materia sea DE SU PLAN. Sin esto, un dedazo en el buscador
         * asentaría en su expediente una materia de otra carrera, y el
         * certificado la llevaría.
         */
        $delPlan = DB::connection('tenant')->table('plan_materias')
            ->where('id', $revalidacion->plan_materia_id)
            // La columna se llama `plan_id`, no `plan_estudio_id`: el nombre
            // se pregunta, no se adivina.
            ->where('plan_id', $matricula->oferta?->plan_id)
            ->exists();

        if (! $delPlan) {
            return 'Esa materia no es del plan de estudios de esa persona.';
        }

        // Ya aprobada: un segundo asiento le regalaría los créditos dos veces.
        $yaAprobada = Historial::query()
            ->where('matricula_oferta_id', $matricula->id)
            ->where('plan_materia_id', $revalidacion->plan_materia_id)
            ->whereHas('estatus', fn ($q) => $q->where('clave', self::ESTATUS_APROBADA))
            ->exists();

        if ($yaAprobada) {
            return 'Esa persona ya tiene esa materia aprobada en su historial: revalidarla le daría los créditos dos veces.';
        }

        return null;
    }

    /** Las materias del plan que todavía se le pueden revalidar. */
    public function materiasRevalidables(Estancia $estancia): array
    {
        $matricula = $estancia->postulacion?->matricula;

        if ($matricula === null) {
            return [];
        }

        $aprobadas = Historial::query()
            ->where('matricula_oferta_id', $matricula->id)
            ->whereHas('estatus', fn ($q) => $q->where('clave', self::ESTATUS_APROBADA))
            ->pluck('plan_materia_id');

        return DB::connection('tenant')->table('plan_materias as pm')
            ->join('asignaturas as a', 'a.id', '=', 'pm.asignatura_id')
            ->where('pm.plan_id', $matricula->oferta?->plan_id)
            ->whereNull('pm.deleted_at')
            ->whereNotIn('pm.id', $aprobadas)
            ->orderBy('pm.periodo')
            ->orderBy('a.nombre')
            ->get(['pm.id', 'a.nombre', 'pm.periodo'])
            ->map(fn ($m) => [
                'id' => $m->id,
                'nombre' => $m->nombre,
                'periodo' => $m->periodo,
            ])
            ->all();
    }

    /** El id de una fila de catálogo por su clave, con un error legible. */
    private function idDe(string $tabla, string $clave): int
    {
        $id = DB::connection('tenant')->table($tabla)->where('clave', $clave)->value('id');

        if ($id === null) {
            throw new RuntimeException(
                "Falta la fila «{$clave}» en el catálogo `{$tabla}`: sin ella el asiento diría otra cosa."
            );
        }

        return (int) $id;
    }
}
