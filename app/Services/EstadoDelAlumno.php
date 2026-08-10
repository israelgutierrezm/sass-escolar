<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Academico\PlanEstudio;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\ControlEscolar\Historial;
use App\Models\Identidad\Persona;
use Illuminate\Support\Collection;

/**
 * Cómo va un alumno, en cuatro cifras.
 *
 * ── Para qué ───────────────────────────────────────────────────────────────
 * Lo consultan dos pantallas que hacen la misma pregunta desde lugares
 * distintos: «Mis hijos» del padre y «Mis tutorados» del tutor educativo. En
 * ambos casos hay una lista de alumnos y alguien que necesita saber, sin abrir
 * cada ficha, a cuál hay que atender.
 *
 * ── Los permisos entran como argumento ─────────────────────────────────────
 * El servicio no decide quién puede ver qué —eso depende del vínculo, y un
 * padre y un tutor no se rigen por lo mismo—, pero sí respeta la decisión: lo
 * que no se autoriza NO SE CALCULA, así que tampoco puede filtrarse por
 * descuido a la vista. Que el dato no exista es más seguro que ocultarlo.
 */
class EstadoDelAlumno
{
    public function __construct(private readonly EstadoCuenta $estadoCuenta) {}

    /**
     * @return array{promedio: float|null, reprobadas: int|null, saldo: float|null, vencido: bool}
     */
    public function de(Persona $alumno, bool $academico = true, bool $finanzas = true): array
    {
        $estado = [
            'promedio' => null,
            'reprobadas' => null,
            'saldo' => null,
            'vencido' => false,
        ];

        $matriculas = $alumno->matriculas()->with('oferta.plan')->get();

        if ($matriculas->isEmpty()) {
            return $estado;
        }

        if ($academico) {
            $historial = Historial::query()
                ->with('estatus:id,clave')
                ->whereIn('matricula_oferta_id', $matriculas->pluck('id'))
                ->get();

            $conCalif = $historial->filter(fn (Historial $h) => $h->calificacion !== null);

            $estado['promedio'] = $conCalif->isEmpty()
                ? null
                : PlanEstudio::redondearCon(
                    $this->planQueManda($matriculas),
                    (float) $conCalif->avg(fn (Historial $h) => (float) $h->calificacion),
                );

            /*
             * Reprobadas, no «no aprobadas»: una materia en curso todavía no
             * tiene veredicto, y contarla como mala pintaría en rojo a un
             * alumno que va al corriente.
             */
            $estado['reprobadas'] = $historial
                ->filter(fn (Historial $h) => $h->estatus?->clave === 'reprobada')
                ->count();
        }

        if ($finanzas) {
            $saldo = 0.0;
            $vencido = false;

            /*
             * Todas sus matrículas juntas: si cursa dos carreras y debe en una,
             * debe. Partirlo obligaría a leer dos cifras para responder una
             * sola pregunta.
             */
            foreach ($matriculas as $m) {
                $cuenta = $this->estadoCuenta->para($m);
                $saldo += (float) ($cuenta['resumen']['saldo'] ?? 0);

                // Deber no es lo mismo que deber TARDE: lo segundo es lo que
                // hace falta atender hoy.
                foreach ($cuenta['adeudos'] ?? [] as $a) {
                    if (($a['vencido'] ?? false) === true && (float) ($a['saldo'] ?? 0) > 0) {
                        $vencido = true;
                    }
                }
            }

            $estado['saldo'] = round($saldo, 2);
            $estado['vencido'] = $vencido;
        }

        return $estado;
    }

    /**
     * Con qué plan se redondea un promedio que mezcla carreras.
     *
     * Aquí el promedio junta TODAS las matrículas —si cursa dos carreras, es
     * uno solo—, y cada plan puede calificar distinto: uno con enteros y otro
     * con dos decimales. No hay una respuesta correcta, así que no se elige una
     * a escondidas: sólo se usa el plan cuando todas las matrículas comparten
     * el mismo. Con planes distintos se cae al comportamiento de siempre —dos
     * decimales, medio arriba— en vez de aplicarle a una carrera la regla de la
     * otra.
     *
     * @param  Collection<int, MatriculaOferta>  $matriculas
     */
    private function planQueManda($matriculas): ?PlanEstudio
    {
        $planes = $matriculas
            ->map(fn ($m) => $m->oferta?->plan)
            ->filter()
            ->unique('id');

        return $planes->count() === 1 ? $planes->first() : null;
    }
}
