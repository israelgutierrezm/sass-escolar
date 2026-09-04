<?php

declare(strict_types=1);

namespace App\Permanencia\Proveedores;

use App\Models\Admisiones\MatriculaOferta;
use App\Models\ControlEscolar\Historial;
use App\Models\Permanencia\ReglaAlertaVersion;
use App\Permanencia\Medicion;
use App\Services\HistorialDelAlumno;
use InvalidArgumentException;

/**
 * Señales académicas. Sobre lo ASENTADO, nunca sobre la captura en curso.
 *
 * ── Por qué no se lee `calificaciones_componente` ──────────────────────────
 * Porque mediría al DOCENTE y no al alumno. La captura parcial va llenándose a
 * lo largo del parcial, y `NULL no es cero` —decisión vieja de este proyecto—:
 * una regla de «va reprobando» leída ahí dispara sobre el alumno cuyo docente
 * captura tarde y calla sobre el que captura pronto. Lo que se lee es
 * `historial`, que lo escribe el cierre de un acta con folio.
 *
 * El precio es que la señal llega TARDE —al cierre del parcial— y hay que
 * decirlo: para lo temprano están la asistencia y las entregas, que se mueven
 * cada semana.
 *
 * ── Y el promedio NO se calcula aquí ───────────────────────────────────────
 * Se le pregunta a `HistorialDelAlumno`, que es la única definición del
 * promedio en el sistema: mejor intento por materia, con la precisión del plan
 * y por matrícula. Recalcularlo daría una cuarta verdad sobre el mismo número,
 * y este proyecto ya tuvo tres.
 */
class ProveedorAcademico implements ProveedorDeSenales
{
    public function __construct(private readonly HistorialDelAlumno $historial) {}

    public function clave(): string
    {
        return 'academico';
    }

    public function titulo(): string
    {
        return 'Académico';
    }

    public function calidad(): string
    {
        return 'Se lee del historial ASENTADO: lo que un docente todavía está capturando no cuenta. '
            .'Eso hace la señal fiable y también TARDÍA —llega al cierre del parcial—, así que no '
            .'sustituye a la asistencia ni a las entregas para detectar algo a tiempo.';
    }

    public function modulo(): ?string
    {
        // Núcleo: no tiene interruptor, y ponerle uno lo apagaría de golpe
        // porque los módulos núcleo no tienen fila en `modulos_activos`.
        return null;
    }

    public function metricas(): array
    {
        return ['academico.promedio', 'academico.reprobadas_ciclo', 'academico.avance_creditos'];
    }

    public function ultimaActualizacion(): ?string
    {
        return Historial::query()->max('created_at');
    }

    public function medir(MatriculaOferta $matricula, string $metrica, ReglaAlertaVersion $version): array
    {
        $resumen = $this->historial->resumen($matricula);

        return match ($metrica) {
            'academico.promedio' => [$this->promedio($matricula, $resumen)],
            'academico.reprobadas_ciclo' => [$this->reprobadas($matricula, $version)],
            'academico.avance_creditos' => [$this->avance($matricula, $resumen)],
            /*
             * REVIENTA, no devuelve `sin_datos`.
             *
             * `sin_datos` significa «no hay con qué contestar», que es un estado
             * legítimo del alumno; una métrica que este proveedor no conoce es un
             * error de CONFIGURACIÓN, y tragárselo dejaría la regla sin levantar
             * nada para siempre y sin que nadie supiera por qué. El motor aísla
             * cada regla, así que esto se reporta con su nombre y las demás
             * siguen.
             */
            default => throw new InvalidArgumentException(
                "El proveedor «{$this->clave()}» no sabe calcular «{$metrica}». "
                .'Revisa la métrica de esta regla: apunta a otro proveedor.',
            ),
        };
    }

    /** @param array<string, mixed> $resumen */
    private function promedio(MatriculaOferta $matricula, array $resumen): Medicion
    {
        $cursadas = (int) ($resumen['materias_cursadas'] ?? 0);

        if ($cursadas === 0 || $resumen['promedio'] === null) {
            /*
             * Un alumno de primer ingreso sin nada asentado no tiene promedio, y
             * no es un cero: es alguien de quien todavía no se puede decir nada.
             * Con cero se le levantaría la alerta más grave del módulo el día que
             * se inscribe.
             */
            return Medicion::sinDatos([
                'matricula' => $matricula->matricula,
                'motivo' => 'todavía no tiene ninguna materia asentada',
                'fuente' => 'historial',
            ]);
        }

        return new Medicion(
            valor: (float) $resumen['promedio'],
            cobertura: $cursadas,
            evidencia: [
                'matricula' => $matricula->matricula,
                'promedio' => $resumen['promedio'],
                'materias_asentadas' => $cursadas,
                'aprobadas' => $resumen['aprobadas'] ?? null,
                'fuente' => 'HistorialDelAlumno::promedio (mejor intento por materia)',
            ],
        );
    }

    /**
     * Materias no aprobadas del ciclo, sobre ACTAS CERRADAS.
     *
     * El ciclo lo pone la regla si lo acota; si no, el de las inscripciones más
     * recientes. Nunca «el ciclo abierto»: dos ciclos simultáneos existen
     * —un semestral y un intensivo de verano— y elegir uno a ojo mediría el
     * equivocado.
     */
    private function reprobadas(MatriculaOferta $matricula, ReglaAlertaVersion $version): Medicion
    {
        $ciclo = $version->regla->ciclo_id;

        $renglones = Historial::query()
            ->where('matricula_oferta_id', $matricula->id)
            ->when($ciclo !== null, fn ($q) => $q->where('ciclo_id', $ciclo))
            ->with('estatus:id,clave')
            ->get();

        /*
         * Lo RESUELTO: lo que sigue «en curso» no es un intento fallido, es un
         * intento que no ha terminado. Contarlo diría que va reprobando quien
         * todavía no ha cerrado el parcial.
         *
         * ── Y NO se filtra por `acta_folio`, aunque la primera versión lo hacía ──
         * Parecía más estricto —«sólo lo que salió de un acta»— y dejaba ciega la
         * señal en la mitad de las escuelas: medido el 2026-09-04, los 1016
         * renglones del demo tienen `acta_folio` en NULL porque vienen de una
         * carga migrada, que es como llega cualquier escuela que cambia de
         * sistema. Su historial es historia escolar igual de válida.
         *
         * Lo que aquel filtro quería excluir —revalidaciones, equivalencias— ya
         * queda fuera por el ESTATUS: ninguna de ésas se asienta como reprobada.
         */
        $resueltos = $renglones->reject(fn (Historial $h) => $h->estatus?->clave === 'en_curso');

        if ($resueltos->isEmpty()) {
            return Medicion::sinDatos([
                'matricula' => $matricula->matricula,
                'ciclo' => $ciclo,
                'materias_en_curso' => $renglones->count(),
                'motivo' => 'todavía no tiene ninguna materia resuelta en el periodo',
                'fuente' => 'historial',
            ]);
        }

        $renglones = $resueltos;

        $reprobadas = $renglones->filter(fn (Historial $h) => $h->estatus?->clave === 'reprobada');

        return new Medicion(
            valor: (float) $reprobadas->count(),
            cobertura: $renglones->count(),
            evidencia: [
                'matricula' => $matricula->matricula,
                'ciclo' => $ciclo,
                'materias_resueltas' => $renglones->count(),
                'no_aprobadas' => $reprobadas->count(),
                'materias' => $reprobadas->take(6)->map(fn (Historial $h) => [
                    'plan_materia' => $h->plan_materia_id,
                    'calificacion' => $h->calificacion,
                    'acta' => $h->acta_folio,
                ])->values()->all(),
                'fuente' => 'historial (materias ya resueltas, sin las que siguen en curso)',
            ],
        );
    }

    /** @param array<string, mixed> $resumen */
    private function avance(MatriculaOferta $matricula, array $resumen): Medicion
    {
        $total = (int) ($resumen['creditos_del_plan'] ?? 0);

        if ($total === 0) {
            /*
             * Sin créditos totales en el plan no hay denominador. Y es un caso
             * real: `planes_estudio.total_creditos` es capturable y hay planes
             * viejos sin él. Dividir daría una división por cero o un avance
             * inventado.
             */
            return Medicion::sinDatos([
                'matricula' => $matricula->matricula,
                'motivo' => 'su plan no tiene capturado el total de créditos',
                'fuente' => 'planes_estudio.total_creditos',
            ]);
        }

        $llevados = (float) ($resumen['creditos'] ?? 0);
        $avance = round($llevados * 100 / $total, 1);

        return new Medicion(
            valor: $avance,
            cobertura: (int) ($resumen['materias_cursadas'] ?? 0),
            evidencia: [
                'matricula' => $matricula->matricula,
                'creditos_aprobados' => $llevados,
                'creditos_del_plan' => $total,
                'avance' => $avance,
                'fuente' => 'HistorialDelAlumno::resumen',
            ],
        );
    }
}
