<?php

declare(strict_types=1);

namespace App\Actas;

use App\Models\Academico\Institucion;
use App\Models\ControlEscolar\Acta;
use App\Models\ControlEscolar\Historial;
use Illuminate\Support\Collection;

/**
 * Arma el acta de calificaciones para imprimirla.
 *
 * ── Se imprime lo ASENTADO, no lo calculado ───────────────────────────────
 * Los renglones salen de `historial`, que es lo que el acta escribió al
 * firmarse, y NO de recalcular las calificaciones por componente. Es la
 * diferencia entre un documento y una consulta: si mañana alguien corrige el
 * esquema de evaluación de esa materia —o se agrega un componente, o cambia un
 * porcentaje—, un acta ya firmada seguiría imprimiendo los mismos números que
 * el día que se firmó. Recalcular haría que el papel de hace un año dijera hoy
 * otra cosa, que es exactamente lo que un acta existe para impedir.
 *
 * ── Y por eso hace falta `withTrashed()` ──────────────────────────────────
 * Cuando se emite un acta de corrección, los renglones de la original se dan de
 * BAJA LÓGICA para que dejen de contar en el historial del alumno. La original
 * sigue existiendo como documento —«ambas actas se conservan»— así que
 * imprimirla con la relación normal devolvería un acta con folio, firma y CERO
 * alumnos. Se ve bien y está vacía, que es la peor manera de fallar.
 *
 * ── Un acta abierta no se imprime ─────────────────────────────────────────
 * No es una restricción de permisos sino de qué es la cosa: el folio se emite
 * al FIRMAR, así que un acta abierta lleva un `BORRADOR-…` que no es folio de
 * nada. Imprimirlo produciría un papel con aspecto de documento oficial y un
 * número inventado. Lo comprueba quien la pide, no esta clase.
 */
class ActaImprimible
{
    /**
     * @return array<string, mixed> las variables de `impresion.acta`
     */
    public function armar(Acta $acta): array
    {
        $acta->loadMissing([
            'asignaturaGrupo.planMateria.asignatura:id,clave,nombre,creditos',
            'asignaturaGrupo.planMateria.plan:id,nombre,clave,calificacion_minima,calificacion_maxima,calificacion_minima_aprobatoria',
            'asignaturaGrupo.grupo.ciclo:id,clave,nombre',
            'asignaturaGrupo.grupo.campus:id,clave,nombre,institucion_id',
            'asignaturaGrupo.docentes.persona:id,nombre,primer_apellido,segundo_apellido',
            'tipoEvaluacion:id,clave,nombre',
            'cerradaPor:id,nombre,primer_apellido,segundo_apellido',
            'origen:id,folio,cerrada_en',
        ]);

        $materia = $acta->asignaturaGrupo;
        $renglones = $this->renglones($acta);

        return [
            'acta' => $acta,
            'institucion' => $this->institucion($acta),
            'encabezado' => $this->encabezado($acta),
            'renglones' => $renglones,
            'resumen' => $this->resumen($renglones),
            'titular' => $materia?->docentes->firstWhere('pivot.tipo', 'titular')?->persona?->nombreCompleto(),
            'plan' => $materia?->planMateria?->plan,
            'notas' => $this->notas($acta, $renglones),
            'sustituida' => $this->sustituidaPor($acta),
        ];
    }

    /**
     * Los alumnos del acta, en el orden en que se leen: por apellido.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function renglones(Acta $acta): Collection
    {
        return Historial::query()
            // Ver el docblock de la clase: sin esto, un acta corregida imprime
            // vacía.
            ->withTrashed()
            ->where('acta_id', $acta->id)
            ->with([
                'matriculaOferta:id,matricula,persona_id',
                'matriculaOferta.persona:id,nombre,primer_apellido,segundo_apellido',
                'estatus:id,clave,nombre',
                'tipoEvaluacion:id,clave,nombre',
                'observacion:id,clave,nombre',
            ])
            ->get()
            ->map(fn (Historial $fila) => [
                'matricula' => $fila->matriculaOferta?->matricula ?? '—',
                'nombre' => $fila->matriculaOferta?->persona?->nombreCompleto() ?? 'Alumno dado de baja',
                'apellido' => $fila->matriculaOferta?->persona?->primer_apellido ?? '',
                'calificacion' => $fila->calificacion,
                'estatus' => $fila->estatus?->nombre,
                'aprobada' => $fila->estatus?->clave === 'aprobada',
                'tipo' => $fila->tipoEvaluacion?->nombre,
                'observacion' => $fila->observacion?->clave === 'sin_observacion'
                    ? null
                    : $fila->observacion?->nombre,
            ])
            ->sortBy([
                fn (array $a, array $b) => strcoll($a['apellido'], $b['apellido']),
                fn (array $a, array $b) => strcoll($a['nombre'], $b['nombre']),
            ])
            ->values();
    }

    /**
     * Lo que va arriba, en dos columnas de etiqueta y valor.
     *
     * Se arma aquí y no en la plantilla porque decidir QUÉ identifica a un acta
     * es del dominio: la terna materia + grupo + ciclo es lo que la vuelve
     * única, y el tipo de evaluación es lo que distingue la ordinaria del
     * extraordinario de la misma materia.
     *
     * @return array<int, array{etiqueta: string, valor: string}>
     */
    private function encabezado(Acta $acta): array
    {
        $materia = $acta->asignaturaGrupo;
        $asignatura = $materia?->planMateria?->asignatura;

        $campos = [
            ['Asignatura', trim(($asignatura?->clave ? $asignatura->clave.' · ' : '').($asignatura?->nombre ?? '—'))],
            ['Clave en el plan', $materia?->planMateria?->clave_en_plan],
            ['Plan de estudios', $materia?->planMateria?->plan?->nombre],
            ['Grupo', $materia?->grupo?->clave],
            ['Ciclo escolar', $materia?->grupo?->ciclo?->clave],
            ['Campus', $materia?->grupo?->campus?->nombre],
            ['Tipo de evaluación', $acta->tipoEvaluacion?->nombre],
            ['Fecha de cierre', $acta->cerrada_en?->format('d/m/Y H:i')],
        ];

        return collect($campos)
            ->filter(fn (array $campo) => filled($campo[1]))
            ->map(fn (array $campo) => ['etiqueta' => $campo[0], 'valor' => (string) $campo[1]])
            ->values()
            ->all();
    }

    /**
     * El conteo que se firma.
     *
     * Va en el documento porque es lo que se comprueba de un vistazo al
     * recibirlo en ventanilla: cuántos alumnos trae y cuántos pasaron. Contarlo
     * a mano sobre cuarenta renglones es justo donde se cuela un error.
     *
     * @param  Collection<int, array<string, mixed>>  $renglones
     * @return array<string, int>
     */
    private function resumen(Collection $renglones): array
    {
        $aprobados = $renglones->where('aprobada', true)->count();

        return [
            'total' => $renglones->count(),
            'aprobados' => $aprobados,
            'reprobados' => $renglones->count() - $aprobados,
        ];
    }

    /**
     * La observación del acta, dicha UNA vez.
     *
     * El asentador escribe la misma en todos los renglones —«extemporánea»,
     * «corrección»— porque es una propiedad del acta, no de cada alumno. En la
     * tabla sería una columna con cuarenta veces el mismo texto: se sube al
     * encabezado, donde de verdad significa algo. Se dejan todas por si alguna
     * vez difieren, en vez de suponer que nunca pasará.
     *
     * En un acta de corrección se calla la observación «Corrección de
     * calificación»: el renglón de arriba ya lo dice, y con más —a cuál
     * sustituye, cuándo se cerró aquélla y por qué motivo—. Repetirlo debajo
     * gasta una línea del documento para no añadir nada.
     *
     * @param  Collection<int, array<string, mixed>>  $renglones
     * @return array<int, string>
     */
    private function notas(Acta $acta, Collection $renglones): array
    {
        return $renglones
            ->pluck('observacion')
            ->filter()
            ->when(
                $acta->acta_origen_id !== null,
                fn (Collection $notas) => $notas->reject(fn (string $n) => str_starts_with($n, 'Corrección'))
            )
            ->unique()
            ->values()
            ->all();
    }

    /**
     * El acta de corrección que dejó a ésta sin efecto, si la hay.
     *
     * Es lo más importante que puede decir un acta impresa, porque sin eso las
     * dos se ven igual de válidas: quien tenga en la mano la vieja no tiene
     * forma de saber que las calificaciones que lee ya no son las que cuentan.
     * Sólo cuenta la corrección CERRADA — una abierta es un trabajo a medias que
     * todavía puede abandonarse.
     */
    private function sustituidaPor(Acta $acta): ?Acta
    {
        return Acta::query()
            ->where('acta_origen_id', $acta->id)
            ->where('situacion', Acta::CERRADA)
            ->orderByDesc('cerrada_en')
            ->first();
    }

    /** La institución del campus donde se abrió el grupo, para el membrete. */
    private function institucion(Acta $acta): ?Institucion
    {
        $institucionId = $acta->asignaturaGrupo?->grupo?->campus?->institucion_id;

        return $institucionId === null ? null : Institucion::find($institucionId);
    }
}
