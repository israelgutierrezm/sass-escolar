<?php

declare(strict_types=1);

namespace App\Permanencia\Proveedores;

use App\Models\Admisiones\MatriculaOferta;
use App\Models\ControlEscolar\Inscripcion;
use App\Models\Lms\Actividad;
use App\Models\Lms\Entrega;
use App\Models\Permanencia\ReglaAlertaVersion;
use App\Permanencia\Medicion;
use InvalidArgumentException;
use App\Services\Asistencia\AsistenciaDelAlumno;
use Illuminate\Support\Facades\DB;

/**
 * Señales de participación: lo que pasa —o no pasa— en el curso.
 *
 * ── La trampa de este proveedor, y por qué se dice en la pantalla ─────────
 * Una escuela puede no usar el LMS. Medido en el demo el 2026-09-04: hay UN
 * curso vivo, cinco entregas y cero intentos de examen. En una escuela
 * presencial que da clase sin plataforma, «cero entregas» no significa que
 * nadie entregue: significa que aquí no se entrega nada. Encender estas reglas
 * ahí pondría a toda la matrícula en la cola el primer día.
 *
 * Por eso **todo se mide contra lo que EXISTE en el curso**: sin curso
 * publicado, sin actividades o sin nada ya vencido, la respuesta es `sin_datos`
 * y no un cero — que además es más fino que un interruptor de módulo: una
 * escuela puede usar el LMS en tres materias y en las demás no.
 *
 * ── Y lo que aún NO vence no cuenta ───────────────────────────────────────
 * Una actividad abierta no es un incumplimiento. Es el caso límite que el
 * pedido nombra y el que separa una alerta útil de una que llega la semana de
 * exámenes sobre todo el grupo.
 */
class ProveedorLms implements ProveedorDeSenales
{
    public function __construct(private readonly AsistenciaDelAlumno $inscripciones) {}

    public function clave(): string
    {
        return 'lms';
    }

    public function titulo(): string
    {
        return 'Participación en el curso';
    }

    public function calidad(): string
    {
        return 'Sólo significa algo donde la escuela usa la plataforma. En una materia sin curso '
            .'publicado o sin actividades vencidas no se mide nada —no sale cero—: «no entregó» y '
            .'«aquí no se entrega» son cosas distintas.';
    }

    /**
     * Ninguno, y NO es un olvido.
     *
     * Medido el 2026-09-04: `lms` figura en el catálogo de módulos y
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
     * Lo que de verdad decide si aquí se usa la plataforma es si hay cursos
     * publicados con actividades, y eso lo comprueba `medir()` materia por
     * materia devolviendo `sin_datos` — que además es más fino: una escuela
     * puede usar el LMS en tres materias y en las demás no.
     */
    public function modulo(): ?string
    {
        return null;
    }

    public function metricas(): array
    {
        return ['lms.actividades_vencidas_sin_entrega', 'lms.dias_sin_actividad'];
    }

    public function ultimaActualizacion(): ?string
    {
        return Entrega::query()->max('entregada_en');
    }

    public function medir(MatriculaOferta $matricula, string $metrica, ReglaAlertaVersion $version): array
    {
        if (! in_array($metrica, $this->metricas(), true)) {
            // Revienta: una métrica ajena es un error de configuración, no un
            // estado del alumno. El motor la aísla y la reporta con su nombre.
            throw new InvalidArgumentException(
                "El proveedor «{$this->clave()}» no sabe calcular «{$metrica}». "
                .'Revisa la métrica de esta regla: apunta a otro proveedor.',
            );
        }

        $inscripciones = $this->inscripciones->inscripcionesVivas($matricula->id, $version->regla->ciclo_id);

        if ($inscripciones->isEmpty()) {
            return [Medicion::sinDatos(['motivo' => 'sin materias inscritas en el ciclo'])];
        }

        /*
         * Los cursos de las materias del alumno, de UNA consulta. Uno por
         * `asignatura_grupo`: la plantilla del plan no cuenta —nadie entrega en
         * una plantilla— y por eso se exige que el curso tenga grupo.
         */
        $cursos = DB::table('cursos')
            ->whereNull('deleted_at')
            ->where('publicado', true)
            ->whereIn('asignatura_grupo_id', $inscripciones->pluck('asignatura_grupo_id')->filter())
            ->pluck('id', 'asignatura_grupo_id');

        return $inscripciones->map(fn (Inscripcion $i) => $metrica === 'lms.dias_sin_actividad'
            ? $this->diasSinActividad($i, $cursos[$i->asignatura_grupo_id] ?? null, $version)
            : $this->vencidasSinEntrega($i, $cursos[$i->asignatura_grupo_id] ?? null))->all();
    }

    private function vencidasSinEntrega(Inscripcion $inscripcion, ?int $cursoId): Medicion
    {
        if ($cursoId === null) {
            return Medicion::sinDatos([
                'inscripcion' => $inscripcion->id,
                'asignatura_grupo' => $inscripcion->asignatura_grupo_id,
                'motivo' => 'esta materia no tiene curso publicado',
                'fuente' => 'cursos',
            ], $inscripcion->asignatura_grupo_id);
        }

        /*
         * Las que YA cerraron. Sin `cierra_en` no se puede afirmar que venció
         * —una lectura sin fecha está abierta para siempre— y contarla como
         * incumplida reportaría al alumno por algo que nadie le puso plazo.
         *
         * Y se excluye la LECTURA: no se entrega, se marca. Contarla como
         * «vencida sin entrega» daría un número que no se puede corregir
         * entregando nada.
         */
        $vencidas = Actividad::query()
            ->where('curso_id', $cursoId)
            ->where('publicada', true)
            ->whereNotNull('cierra_en')
            ->where('cierra_en', '<', now())
            ->where('tipo', '!=', 'lectura')
            ->get(['id', 'titulo', 'cierra_en']);

        if ($vencidas->isEmpty()) {
            return Medicion::sinDatos([
                'inscripcion' => $inscripcion->id,
                'asignatura_grupo' => $inscripcion->asignatura_grupo_id,
                'curso' => $cursoId,
                'motivo' => 'todavía no vence ninguna actividad de esta materia',
                'fuente' => 'actividades',
            ], $inscripcion->asignatura_grupo_id);
        }

        $entregadas = Entrega::query()
            ->where('inscripcion_id', $inscripcion->id)
            ->whereIn('actividad_id', $vencidas->pluck('id'))
            // Una entrega en BORRADOR no es una entrega: el portafolio nace así
            // y se cierra aparte.
            ->whereIn('estado', [Entrega::ENTREGADA, Entrega::CALIFICADA])
            ->pluck('actividad_id');

        $faltantes = $vencidas->reject(fn ($a) => $entregadas->contains($a->id));

        return new Medicion(
            valor: (float) $faltantes->count(),
            cobertura: $vencidas->count(),
            evidencia: [
                'inscripcion' => $inscripcion->id,
                'asignatura_grupo' => $inscripcion->asignatura_grupo_id,
                'curso' => $cursoId,
                'actividades_vencidas' => $vencidas->count(),
                'sin_entrega' => $faltantes->count(),
                'cuales' => $faltantes->take(6)->map(fn ($a) => [
                    'titulo' => $a->titulo,
                    'cerro' => $a->cierra_en,
                ])->values()->all(),
                'fuente' => 'actividades + entregas',
                'nota' => 'Sólo lo ya vencido. Una actividad abierta no es un incumplimiento.',
            ],
            asignaturaGrupoId: $inscripcion->asignatura_grupo_id,
        );
    }

    private function diasSinActividad(Inscripcion $inscripcion, ?int $cursoId, ReglaAlertaVersion $version): Medicion
    {
        if ($cursoId === null) {
            return Medicion::sinDatos([
                'inscripcion' => $inscripcion->id,
                'motivo' => 'esta materia no tiene curso publicado',
                'fuente' => 'cursos',
            ], $inscripcion->asignatura_grupo_id);
        }

        $actividades = DB::table('actividades')
            ->whereNull('deleted_at')->where('curso_id', $cursoId)->where('publicada', true)
            ->pluck('id');

        if ($actividades->isEmpty()) {
            return Medicion::sinDatos([
                'inscripcion' => $inscripcion->id,
                'curso' => $cursoId,
                'motivo' => 'el curso no tiene ninguna actividad publicada',
                'fuente' => 'actividades',
            ], $inscripcion->asignatura_grupo_id);
        }

        /*
         * La última señal de vida, de las TRES que existen: entregar, abrir una
         * lección e intentar un examen. Con una sola —la entrega— un alumno que
         * lee todo y no ha tenido nada que entregar saldría «desconectado».
         */
        $ultima = collect([
            DB::table('entregas')->whereNull('deleted_at')
                ->where('inscripcion_id', $inscripcion->id)
                ->whereIn('actividad_id', $actividades)->max('entregada_en'),
            DB::table('actividad_vistas')->whereNull('deleted_at')
                ->where('inscripcion_id', $inscripcion->id)
                ->whereIn('actividad_id', $actividades)->max('vista_en'),
            DB::table('intentos')->whereNull('deleted_at')
                ->where('inscripcion_id', $inscripcion->id)->max('iniciado_en'),
        ])->filter()->max();

        if ($ultima === null) {
            /*
             * NUNCA ha tocado el curso. Es un dato, no una ausencia de dato: el
             * curso existe, tiene actividades y él no ha entrado. Se mide desde
             * que se inscribió, que es lo único honesto — decir «infinitos días»
             * no se puede comparar con un umbral.
             */
            $desde = $inscripcion->created_at?->toDateString() ?? now()->toDateString();
            $dias = (int) now()->startOfDay()->diffInDays($desde, absolute: true);

            return new Medicion(
                valor: (float) $dias,
                cobertura: $actividades->count(),
                evidencia: [
                    'inscripcion' => $inscripcion->id,
                    'curso' => $cursoId,
                    'ultima_actividad' => null,
                    'dias' => $dias,
                    'desde' => $desde,
                    'nota' => 'No ha abierto nada del curso desde que se inscribió.',
                    'fuente' => 'entregas + actividad_vistas + intentos',
                ],
                asignaturaGrupoId: $inscripcion->asignatura_grupo_id,
            );
        }

        $dias = (int) now()->startOfDay()->diffInDays(\Carbon\CarbonImmutable::parse($ultima)->startOfDay(), absolute: true);

        return new Medicion(
            valor: (float) $dias,
            cobertura: $actividades->count(),
            evidencia: [
                'inscripcion' => $inscripcion->id,
                'curso' => $cursoId,
                'ultima_actividad' => (string) $ultima,
                'dias' => $dias,
                'ventana' => $version->ventana_valor,
                'fuente' => 'entregas + actividad_vistas + intentos',
            ],
            asignaturaGrupoId: $inscripcion->asignatura_grupo_id,
        );
    }
}
