<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Admisiones\MatriculaOferta;
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
 *
 * ── El promedio ya NO se calcula aquí ──────────────────────────────────────
 * Lo resuelve {@see HistorialDelAlumno}, POR MATRÍCULA. Este servicio lo
 * promediaba por PERSONA, mezclando los programas académicos de quien estudia dos, y sobre
 * todos los renglones en vez del mejor intento por materia. Medido contra el
 * demo: de las 15 personas con dos matrículas, LAS 15 salían con un promedio
 * distinto del que enseña su propio historial —Sofía García Pérez con 8.54 aquí
 * y 8.59 / 8.50 allá—, o sea que el padre leía un número que no era el promedio
 * de ninguna de las dos programas académicos de su hija. Ver `docs/decisiones.md`,
 * 2026-08-26.
 */
class EstadoDelAlumno
{
    public function __construct(
        private readonly EstadoCuenta $estadoCuenta,
        private readonly HistorialDelAlumno $historial,
    ) {}

    /**
     * @return array{
     *     promedio: float|null,
     *     promedio_de: string|null,
     *     programas: array<int, array<string, mixed>>,
     *     reprobadas: int|null,
     *     saldo: float|null,
     *     vencido: bool
     * }
     */
    public function de(Persona $alumno, bool $academico = true, bool $finanzas = true): array
    {
        $estado = [
            'promedio' => null,
            'promedio_de' => null,
            'programas' => [],
            'reprobadas' => null,
            'saldo' => null,
            'vencido' => false,
        ];

        $matriculas = $alumno->matriculas()->with('oferta.plan', 'oferta.programaAcademico')->get();

        if ($matriculas->isEmpty()) {
            return $estado;
        }

        if ($academico) {
            $estado = [...$estado, ...$this->academicoDe($matriculas)];
        }

        if ($finanzas) {
            $saldo = 0.0;
            $vencido = false;

            /*
             * Todas sus matrículas juntas: si cursa dos programas académicos y debe en una,
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
     * Lo académico, PROGRAMA POR PROGRAMA.
     *
     * ── Por qué hay una cifra suelta además del desglose ──────────────────
     * Las dos pantallas que llaman aquí ordenan por el promedio y lo resumen en
     * una línea: con una lista no podrían hacer ninguna de las dos cosas. La
     * cifra suelta es el promedio **más bajo** de sus programas, que es
     * literalmente lo que este servicio existe para contestar —«a cuál hay que
     * atender»—, y viaja con `promedio_de` para que se pueda nombrar el programa académico
     * en cuanto hay más de una. Sin ese nombre, «Promedio 8.29» se leería como
     * si fuera el único que tiene.
     *
     * `reprobadas` sí se suma entre programas: es una cuenta de la persona.
     *
     * @param  Collection<int, MatriculaOferta>  $matriculas
     * @return array<string, mixed>
     */
    private function academicoDe($matriculas): array
    {
        $programas = $matriculas
            ->map(function (MatriculaOferta $m) {
                // El MISMO resumen que ve el alumno en `/mi-historial` y la
                // ventanilla en el expediente. No se recalcula nada: el día que
                // dieran números distintos, nadie sabría cuál creer.
                $resumen = $this->historial->resumen($m);

                return [
                    'matricula' => $m->matricula,
                    'programa_academico' => $m->oferta?->programaAcademico?->nombre,
                    'promedio' => $resumen['promedio'],
                    'reprobadas' => $resumen['reprobadas'],
                ];
            })
            ->values();

        $conPromedio = $programas->filter(fn (array $p) => $p['promedio'] !== null);

        // El más bajo, y de cuál es. Con un solo programa —el caso de casi
        // todos— esto da exactamente lo mismo que antes.
        $peor = $conPromedio->sortBy('promedio')->first();

        return [
            'programas' => $programas->all(),
            'promedio' => $peor['promedio'] ?? null,
            'promedio_de' => $conPromedio->count() > 1 ? ($peor['programa_academico'] ?? null) : null,
            'reprobadas' => (int) $programas->sum('reprobadas'),
        ];
    }
}
