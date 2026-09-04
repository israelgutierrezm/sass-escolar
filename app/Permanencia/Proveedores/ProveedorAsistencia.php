<?php

declare(strict_types=1);

namespace App\Permanencia\Proveedores;

use App\Models\Admisiones\MatriculaOferta;
use App\Models\Asistencia\AsistenciaClase;
use App\Models\ControlEscolar\Inscripcion;
use App\Models\Permanencia\ReglaAlertaVersion;
use App\Permanencia\Medicion;
use App\Services\Asistencia\AsistenciaDelAlumno;
use InvalidArgumentException;

/**
 * Señales de asistencia. Por MATERIA, siempre.
 *
 * ── Por qué por materia y no por alumno ────────────────────────────────────
 * El derecho a examen se pierde materia por materia, así que «el alumno tiene
 * 78 % de asistencia» no es una pregunta que se pueda contestar de una forma
 * útil: promediando seis materias, la que va en 40 % desaparece detrás de cinco
 * en 90 %. Cada inscripción produce su medición y el motor levanta su alerta.
 *
 * ── Todo lo caro se hace de una vez ────────────────────────────────────────
 * Los conteos de las seis materias salen de UNA consulta agrupada. Preguntar
 * materia por materia sería la N+1 de siempre, y aquí se multiplica por el
 * número de alumnos de la escuela en cada corrida de madrugada.
 *
 * Las faltas CONSECUTIVAS sí van una por una, y no es un descuido: hay que
 * recorrer las sesiones en orden hasta la primera que no sea falta, y eso no se
 * agrupa. A cambio sólo se pregunta por las inscripciones que la ventana deja
 * con datos.
 */
class ProveedorAsistencia implements ProveedorDeSenales
{
    public function __construct(private readonly AsistenciaDelAlumno $asistencia) {}

    public function clave(): string
    {
        return 'asistencia';
    }

    public function titulo(): string
    {
        return 'Asistencia';
    }

    public function calidad(): string
    {
        return 'Se calcula sobre las sesiones que los docentes REGISTRARON, no sobre el calendario. '
            .'Un 100 % sobre tres sesiones significa que fue a esas tres, no que no ha faltado — por eso '
            .'estas reglas necesitan cobertura mínima más que ninguna otra.';
    }

    /**
     * Ninguno, y NO es un olvido.
     *
     * Medido el 2026-09-04: `asistencia` figura en el catálogo de módulos y
     * está APAGADO en el demo —como `finanzas` y `control_escolar`—, porque los
     * módulos núcleo no tienen fila en `modulos_activos` y `ModulosDeLaEscuela`
     * falla cerrado. Ninguna ruta lo gatea con `modulo:`, así que en la práctica
     * es núcleo aunque exista la fila del catálogo.
     *
     * Declararlo aquí silenciaba este proveedor entero **sin un solo error**:
     * las reglas se quedaban sin evaluar y la corrida decía «0 reglas». Es la
     * trampa que este proyecto ya tenía anotada para las tarjetas del panel, por
     * otra puerta.
     *
     * Lo que de verdad decide si aquí se puede medir asistencia es si hay
     * sesiones registradas, y eso lo comprueba `medir()` inscripción por
     * inscripción devolviendo `sin_datos`.
     */
    public function modulo(): ?string
    {
        return null;
    }

    public function metricas(): array
    {
        return ['asistencia.faltas_consecutivas', 'asistencia.porcentaje'];
    }

    public function ultimaActualizacion(): ?string
    {
        return AsistenciaClase::query()->max('fecha');
    }

    public function medir(MatriculaOferta $matricula, string $metrica, ReglaAlertaVersion $version): array
    {
        $this->exigirQueSeaSuya($metrica);

        $inscripciones = $this->asistencia->inscripcionesVivas(
            $matricula->id,
            $version->regla->ciclo_id,
        );

        if ($inscripciones->isEmpty()) {
            /*
             * Sin materias vivas no hay nada que medir, y eso NO es un cero:
             * es un alumno que todavía no se inscribe, o que se dio de baja de
             * todo. Levantarle una alerta de asistencia sería reportarlo por no
             * ir a clases que no tiene.
             */
            return [Medicion::sinDatos(['motivo' => 'sin materias inscritas en el ciclo'])];
        }

        [$desde, $hasta] = $this->ventana($version);

        $ids = $inscripciones->pluck('id')->all();
        $conteos = $this->asistencia->conteosDe($ids, $desde, $hasta);

        return $inscripciones->map(function (Inscripcion $i) use ($conteos, $metrica, $desde, $hasta) {
            $c = $conteos[$i->id] ?? ['sesiones' => 0, 'presentes' => 0, 'faltas' => 0,
                'justificadas' => 0, 'retardos' => 0];

            $base = [
                'inscripcion' => $i->id,
                'asignatura_grupo' => $i->asignatura_grupo_id,
                'periodo' => trim(($desde ?? 'inicio').'/'.($hasta ?? 'hoy')),
                'sesiones_registradas' => $c['sesiones'],
                'presentes' => $c['presentes'],
                'faltas' => $c['faltas'],
                'justificadas' => $c['justificadas'],
                'retardos' => $c['retardos'],
                'fuente' => 'asistencia_clase',
            ];

            if ($c['sesiones'] === 0) {
                return Medicion::sinDatos(
                    $base + ['motivo' => 'todavía no le han pasado lista en esta materia'],
                    $i->asignatura_grupo_id,
                );
            }

            if ($metrica === 'asistencia.faltas_consecutivas') {
                $seguidas = $this->asistencia->faltasConsecutivas($i->id, $desde);

                return new Medicion(
                    valor: (float) $seguidas,
                    cobertura: $c['sesiones'],
                    evidencia: $base + [
                        'faltas_seguidas' => $seguidas,
                        'nota' => 'La racha se corta con cualquier sesión que no sea falta, incluida una '
                            .'justificada: para eso se justifica.',
                    ],
                    asignaturaGrupoId: $i->asignatura_grupo_id,
                );
            }

            $porcentaje = $this->asistencia->porcentaje($c);

            return new Medicion(
                valor: $porcentaje,
                cobertura: $c['sesiones'],
                evidencia: $base + [
                    'porcentaje' => $porcentaje,
                    'definicion' => 'Todo lo que no es falta cuenta como asistencia, sobre las sesiones '
                        .'registradas.',
                ],
                asignaturaGrupoId: $i->asignatura_grupo_id,
            );
        })->all();
    }


    /**
     * Una métrica que no es suya REVIENTA, no se mide como otra cosa.
     *
     * Sin esta guarda, `medir()` caía en la rama del porcentaje para cualquier
     * clave que no fuera la de las faltas: una regla mal configurada —la métrica
     * de un proveedor apuntando a otro— habría medido el porcentaje de
     * asistencia y levantado alertas comparándolo contra un umbral de promedio.
     * **Sin un solo error**, y con alertas que parecen buenas.
     *
     * Lo cazó la suite al construir la regla rota a propósito. Y reventar es lo
     * correcto porque el motor aísla cada regla: el fallo se reporta con el
     * nombre de la regla y las demás se siguen evaluando.
     */
    private function exigirQueSeaSuya(string $metrica): void
    {
        if (! in_array($metrica, $this->metricas(), true)) {
            throw new InvalidArgumentException(
                "El proveedor «{$this->clave()}» no sabe calcular «{$metrica}». "
                .'Revisa la métrica de esta regla: apunta a otro proveedor.',
            );
        }
    }

    /**
     * De cuándo a cuándo se mide.
     *
     * `ciclo` y `desde_inicio` no acotan por fecha: la inscripción ya está
     * acotada al ciclo, y acotar además por las fechas del ciclo dejaría fuera
     * una sesión capturada un día antes de que empezara —lo que pasa cuando el
     * calendario se mueve—. `ultimos_dias` sí acota, y es la ventana con la que
     * se detecta un cambio reciente.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function ventana(ReglaAlertaVersion $version): array
    {
        if ($version->ventana_tipo !== 'ultimos_dias' || $version->ventana_valor === null) {
            return [null, null];
        }

        return [now()->subDays($version->ventana_valor)->toDateString(), now()->toDateString()];
    }
}
