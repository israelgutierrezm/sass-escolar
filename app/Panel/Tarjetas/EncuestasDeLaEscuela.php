<?php

declare(strict_types=1);

namespace App\Panel\Tarjetas;

use App\Models\Encuestas\AplicacionEncuesta;
use App\Models\Identidad\Usuario;
use App\Panel\TarjetaPanel;
use App\Services\Encuestas\ResultadosDeEncuesta;

/**
 * Cómo van las encuestas abiertas.
 *
 * ── Qué contesta ───────────────────────────────────────────────────────────
 * Una sola pregunta, que es la que se hace quien lanzó una encuesta: ¿está
 * contestando la gente? Una evaluación docente con 12% de participación no da
 * un resultado malo, da un resultado que no significa nada, y eso hay que
 * saberlo mientras la encuesta sigue abierta —cuando todavía se puede insistir—
 * y no al cerrarla.
 *
 * ── Por qué no muestra promedios ───────────────────────────────────────────
 * Un promedio en el panel se lee de pasada, sin el contexto de cuánta gente
 * contestó ni del umbral de anonimato. El panel dice si hay que insistir; para
 * juzgar resultados está la pantalla de la encuesta, donde el número viene con
 * lo que hace falta para interpretarlo.
 */
class EncuestasDeLaEscuela implements TarjetaPanel
{
    public function __construct(private readonly ResultadosDeEncuesta $resultados) {}

    public function clave(): string
    {
        return 'encuestas';
    }

    public function titulo(): string
    {
        return 'Encuestas abiertas';
    }

    public function permiso(): ?string
    {
        return 'gestionar-encuestas';
    }

    public function tipo(): string
    {
        return 'encuestas';
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
        $abiertas = AplicacionEncuesta::query()
            ->abiertas()
            ->withCount(['participaciones', 'sujetos'])
            ->orderBy('cierra_en')
            ->limit(5)
            ->get();

        // Null y no una tarjeta vacía: sin encuestas abiertas no hay nada que
        // vigilar, y un recuadro que dice «nada» ocupa el sitio de uno que sí
        // tiene algo que decir.
        if ($abiertas->isEmpty()) {
            return null;
        }

        return [
            'encuestas' => $abiertas->map(function (AplicacionEncuesta $aplicacion) {
                $esperadas = $this->esperadas($aplicacion);
                $recibidas = $aplicacion->participaciones_count;

                return [
                    'id' => $aplicacion->id,
                    'titulo' => $aplicacion->titulo,
                    'obligatoria' => $aplicacion->obligatoria,
                    'cierra_en' => $aplicacion->cierra_en?->format('d M'),
                    // Los días que quedan: es lo que decide si todavía sirve
                    // insistir o ya sólo queda cerrarla.
                    'dias' => $aplicacion->cierra_en === null
                        ? null
                        : max(0, (int) now()->diffInDays($aplicacion->cierra_en, false)),
                    'respuestas' => $recibidas,
                    'esperadas' => $esperadas,
                    'porcentaje' => $esperadas === 0 ? null : (int) round($recibidas * 100 / $esperadas),
                ];
            })->all(),
        ];
    }

    /**
     * A cuántas respuestas se aspira.
     *
     * En la evaluación docente son los alumnos de cada materia evaluada —una
     * encuesta por cada uno—; en la general no hay forma barata de saberlo por
     * adelantado, así que se deja sin denominador antes que inventarlo.
     */
    private function esperadas(AplicacionEncuesta $aplicacion): int
    {
        if (! $aplicacion->esDocente()) {
            return 0;
        }

        return (int) $aplicacion->sujetos()
            ->join('inscripcion', 'inscripcion.asignatura_grupo_id', '=', 'aplicacion_sujetos.asignatura_grupo_id')
            ->count();
    }
}
