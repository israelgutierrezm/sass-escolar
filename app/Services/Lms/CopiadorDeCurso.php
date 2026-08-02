<?php

declare(strict_types=1);

namespace App\Services\Lms;

use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\Lms\Actividad;
use App\Models\Lms\Curso;
use App\Models\Lms\Examen;
use App\Models\Lms\Reactivo;
use Illuminate\Support\Facades\DB;

/**
 * Copia la plantilla de una materia al grupo que la abre.
 *
 * ── Por qué copia y no apunta ──────────────────────────────────────────────
 * Decisión del usuario: al abrir el grupo se COPIA. Apuntar a la plantilla
 * habría sido menos código y habría hecho que corregir una falta de ortografía
 * en el plan cambiara un examen que un grupo ya está contestando. Con la copia,
 * cada grupo tiene lo suyo: el docente puede ajustar sin tocar a nadie más, y lo
 * que se edita en el plan alcanza a los grupos que se abran después.
 *
 * `plantilla_origen_id` deja dicho de dónde salió, que es lo que permite
 * distinguir un curso armado por la escuela de uno que el docente hizo a mano.
 *
 * ── Lo que NO se copia ─────────────────────────────────────────────────────
 * Las fechas. Una fecha de la plantilla es la de otro ciclo, y traerla haría que
 * un grupo de agosto abriera con entregas vencidas en marzo. Se copia la
 * estructura; el calendario lo pone quien abre el grupo.
 */
class CopiadorDeCurso
{
    /**
     * Copia la plantilla del plan a esta materia impartida, si hay plantilla y
     * el grupo todavía no tiene curso.
     *
     * Devuelve el curso nuevo, o null si no había nada que copiar. Es silencioso
     * a propósito: abrir una materia sin plantilla es el caso normal, no un
     * error.
     */
    public function alAbrirMateria(AsignaturaGrupo $asignaturaGrupo): ?Curso
    {
        $plantilla = Curso::query()
            ->where('plan_materia_id', $asignaturaGrupo->plan_materia_id)
            ->where('publicado', true)
            ->first();

        if ($plantilla === null) {
            return null;
        }

        $yaTiene = Curso::query()
            ->where('asignatura_grupo_id', $asignaturaGrupo->id)
            ->exists();

        if ($yaTiene) {
            return null;
        }

        return $this->copiar($plantilla, $asignaturaGrupo);
    }

    /** Clona la plantilla completa sobre una materia impartida. */
    public function copiar(Curso $plantilla, AsignaturaGrupo $asignaturaGrupo): Curso
    {
        return DB::transaction(function () use ($plantilla, $asignaturaGrupo) {
            $curso = Curso::create([
                'asignatura_grupo_id' => $asignaturaGrupo->id,
                'plantilla_origen_id' => $plantilla->id,
                'titulo' => $plantilla->titulo,
                'presentacion' => $plantilla->presentacion,
                'docente_puede_agregar' => $plantilla->docente_puede_agregar,
                'docente_puede_ponderar' => $plantilla->docente_puede_ponderar,
                'publicado' => true,
            ]);

            // El banco entero, no solo lo que usa algún examen: el docente puede
            // querer armar otro examen con lo que la escuela ya redactó.
            $reactivos = $this->copiarBanco($plantilla, $curso);

            foreach ($plantilla->actividades as $actividad) {
                $this->copiarActividad($actividad, $curso, $reactivos);
            }

            return $curso;
        });
    }

    /**
     * Copia el banco de reactivos con sus opciones.
     *
     * @return array<int, int> id del reactivo en la plantilla => id en la copia
     */
    private function copiarBanco(Curso $plantilla, Curso $curso): array
    {
        $mapa = [];

        foreach ($plantilla->reactivos()->with('opciones')->get() as $original) {
            $copia = Reactivo::create([
                'curso_id' => $curso->id,
                'tipo' => $original->tipo,
                'enunciado' => $original->enunciado,
                'imagen' => $original->imagen,
                'puntos' => $original->puntos,
                'retro_correcta' => $original->retro_correcta,
                'retro_incorrecta' => $original->retro_incorrecta,
                'respuesta' => $original->respuesta,
            ]);

            foreach ($original->opciones as $opcion) {
                $copia->opciones()->create([
                    'texto' => $opcion->texto,
                    'correcta' => $opcion->correcta,
                    'pareja' => $opcion->pareja,
                    'orden' => $opcion->orden,
                ]);
            }

            $mapa[$original->id] = $copia->id;
        }

        return $mapa;
    }

    /**
     * @param  array<int, int>  $reactivos
     */
    private function copiarActividad(Actividad $original, Curso $curso, array $reactivos): void
    {
        $copia = Actividad::create([
            'curso_id' => $curso->id,
            'tipo' => $original->tipo,
            'titulo' => $original->titulo,
            'instrucciones' => $original->instrucciones,
            'esquema_evaluacion_id' => $original->esquema_evaluacion_id,
            'puntos' => $original->puntos,
            'permite_tarde' => $original->permite_tarde,
            'orden' => $original->orden,
            'publicada' => $original->publicada,
            'config' => $original->config,
            // Sin fechas: son las del ciclo en que se armó la plantilla.
        ]);

        $examen = $original->examen;

        if ($examen === null) {
            return;
        }

        $nuevo = Examen::create([
            'actividad_id' => $copia->id,
            'intentos_permitidos' => $examen->intentos_permitidos,
            'minutos_limite' => $examen->minutos_limite,
            'reactivos_a_presentar' => $examen->reactivos_a_presentar,
            'barajar_reactivos' => $examen->barajar_reactivos,
            'barajar_opciones' => $examen->barajar_opciones,
            'una_por_pagina' => $examen->una_por_pagina,
            'intento_que_cuenta' => $examen->intento_que_cuenta,
            'mostrar_resultado' => $examen->mostrar_resultado,
        ]);

        /*
         * El armado se rehace apuntando a los reactivos COPIADOS. Copiar el
         * pivote tal cual dejaría el examen del grupo armado con las preguntas
         * de la plantilla: editar una en el plan cambiaría el examen que un
         * grupo está contestando, que es justo lo que copiar venía a evitar.
         */
        $armado = [];

        foreach ($examen->reactivos as $reactivo) {
            $nuevoId = $reactivos[$reactivo->id] ?? null;

            if ($nuevoId === null) {
                continue;
            }

            $armado[$nuevoId] = [
                'puntos' => $reactivo->pivot->puntos,
                'orden' => $reactivo->pivot->orden,
            ];
        }

        $nuevo->reactivos()->sync($armado);
    }
}
