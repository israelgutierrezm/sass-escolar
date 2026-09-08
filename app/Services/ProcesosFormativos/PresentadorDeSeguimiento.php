<?php

declare(strict_types=1);

namespace App\Services\ProcesosFormativos;

use App\Models\Lms\Rubrica;
use App\Models\ProcesosFormativos\EvaluacionProceso;
use App\Models\ProcesosFormativos\ExpedienteProceso;

/**
 * Cómo se ven las horas, los informes y las evaluaciones de un expediente.
 *
 * Es puro mapeo a la forma que espera la pantalla, pero vive en UN sitio a
 * propósito: lo pintan DOS oficios —el coordinador en `/procesos/expedientes` y
 * el supervisor externo en `/supervision`—, y con el mapeo copiado, el día que
 * uno gane una columna el otro se queda atrás. Es lo mismo que ya le pasó a la
 * bitácora de postulaciones antes de centralizarla, y lo que este proyecto
 * evita escribiendo la forma una sola vez.
 *
 * La suma de horas sale del {@see RegistradorDeHoras} y no de la colección
 * cargada: es la ÚNICA definición de «cuántas horas lleva», y repetirla aquí
 * daría una segunda respuesta el día que una de las dos filtre distinto.
 */
class PresentadorDeSeguimiento
{
    public function __construct(private readonly RegistradorDeHoras $horas) {}

    public function horas(ExpedienteProceso $expediente): array
    {
        return [
            'aprobadas' => $this->horas->horasAprobadas($expediente),
            'faltan' => $this->horas->horasQueFaltan($expediente),
            // CUÁNTAS le piden, que es lo que dibuja la barra de avance: es lo
            // primero que se mira al abrir un expediente.
            'requeridas' => $expediente->reglaVersion?->horasMinimas(),
            'max_dia' => $expediente->reglaVersion?->max_horas_dia,
            'max_semana' => $expediente->reglaVersion?->max_horas_semana,
            'por_revisar' => $expediente->horas->where('estado', 'capturada')->count(),
            'admite' => $expediente->admiteHoras(),
            'jornadas' => $expediente->horas->sortByDesc('fecha')->values()->map(fn ($h) => [
                'id' => $h->id,
                'fecha' => $h->fecha?->toDateString(),
                'inicio' => substr((string) $h->hora_inicio, 0, 5),
                'fin' => substr((string) $h->hora_fin, 0, 5),
                'descanso' => $h->minutos_descanso,
                'horas' => $h->horas(),
                'actividad' => $h->actividad,
                'modalidad' => $h->modalidad?->nombre,
                'estado' => $h->estado,
                'motivo_rechazo' => $h->motivo_rechazo,
                'tiene_evidencia' => $h->evidencia_ruta !== null,
                'capturada_por' => $h->capturadaPor?->persona?->nombreCompleto(),
            ]),
        ];
    }

    public function informes(ExpedienteProceso $expediente): array
    {
        return $expediente->informes->map(fn ($i) => [
            'id' => $i->id,
            'tipo' => $i->tipo?->nombre,
            'numero' => $i->numero,
            'es_final' => $i->esFinal(),
            'fecha_limite' => $i->fecha_limite?->toDateString(),
            'entregado_en' => $i->entregado_en?->format('d/m/Y H:i'),
            'estado' => $i->estado,
            'estado_texto' => $i->etiquetaEstado(),
            'tarde' => $i->llegoTarde(),
            'vencido' => $i->estaVencido(),
            'retroalimentacion' => $i->retroalimentacion,
            'nombre_original' => $i->nombre_original,
        ])->values()->all();
    }

    public function evaluaciones(ExpedienteProceso $expediente): array
    {
        return $expediente->evaluaciones->map(fn ($ev) => [
            'id' => $ev->id,
            'origen' => $ev->origen,
            'origen_texto' => $ev->etiquetaOrigen(),
            'rubrica' => $ev->rubrica?->nombre,
            'puntaje' => $ev->puntaje === null ? null : (float) $ev->puntaje,
            'total' => $ev->total(),
            'respuestas' => $ev->respuestas,
            'comentarios' => $ev->comentarios,
            'firmada_en' => $ev->firmada_en?->format('d/m/Y H:i'),
        ])->values()->all();
    }

    /**
     * Las rúbricas con las que se puede evaluar: SÓLO las de la escuela. Una
     * propia de un docente es su borrador, y evaluar con ella dejaría la
     * evaluación colgando de algo que su dueño puede borrar.
     */
    public function rubricasParaEvaluar(): array
    {
        return Rubrica::query()
            ->where('ambito', Rubrica::PLATAFORMA)
            ->where('activa', true)
            ->with('criterios.niveles')
            ->orderBy('nombre')
            ->get()
            ->filter(fn (Rubrica $r) => $r->calificable())
            ->map(fn (Rubrica $r) => [
                'id' => $r->id,
                'nombre' => $r->nombre,
                'total' => $r->total(),
                'criterios' => $r->criterios->map(fn ($c) => [
                    'id' => $c->id,
                    'titulo' => $c->titulo,
                    'niveles' => $c->niveles->map(fn ($n) => [
                        'id' => $n->id,
                        'titulo' => $n->titulo,
                        'puntos' => (float) $n->puntos,
                    ])->values(),
                ])->values(),
            ])
            ->values()
            ->all();
    }

    public function origenesEvaluacion(): array
    {
        return collect(EvaluacionProceso::ORIGENES)
            ->map(fn ($texto, $valor) => ['valor' => $valor, 'texto' => $texto])
            ->values()
            ->all();
    }
}
