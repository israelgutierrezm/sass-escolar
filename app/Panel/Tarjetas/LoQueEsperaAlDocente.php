<?php

declare(strict_types=1);

namespace App\Panel\Tarjetas;

use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\Identidad\Usuario;
use App\Models\Lms\Conversacion;
use App\Models\Lms\Curso;
use App\Models\Lms\Entrega;
use App\Models\Lms\Mensaje;
use App\Panel\TarjetaPanel;

/**
 * Lo que espera al docente, sumando TODAS sus materias.
 *
 * Entregas por calificar y mensajes sin leer viven dentro de cada materia, así
 * que averiguar si hay trabajo obligaba a abrir las seis para descubrir que solo
 * dos tenían algo. Esta tarjeta responde esa pregunta al entrar, y lleva directo
 * a la materia que lo reclama.
 *
 * Solo se listan las materias CON trabajo pendiente: un panel que enumera seis
 * renglones en cero no informa, estorba. Si no hay nada, la tarjeta no aparece
 * —devolver null es la manera que tiene el registro de decir «hoy no aplica»—.
 */
class LoQueEsperaAlDocente implements TarjetaPanel
{
    public function clave(): string
    {
        return 'lo-que-espera-docente';
    }

    public function titulo(): string
    {
        return 'Te espera';
    }

    public function permiso(): ?string
    {
        return 'ver-mis-materias';
    }

    public function tipo(): string
    {
        return 'lista';
    }

    public function ancho(): int
    {
        return 2;
    }

    public function icono(): string
    {
        return 'M11.35 3.836c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h1.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m8.9-4.414c.376.023.75.05 1.124.08 1.131.094 1.976 1.057 1.976 2.192V16.5A2.25 2.25 0 0 1 18 18.75h-2.25m-7.5-10.5H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V18.75m-7.5-10.5h6.375c.621 0 1.125.504 1.125 1.125v9.375m-8.25-3 1.5 1.5 3-3.75';
    }

    public function datos(Usuario $usuario): ?array
    {
        $personaId = $usuario->persona_id;

        if ($personaId === null) {
            return null;
        }

        $materias = AsignaturaGrupo::query()
            ->with(['planMateria.asignatura:id,nombre', 'grupo:id,clave'])
            ->whereHas('docentes', fn ($q) => $q->where('docentes.persona_id', $personaId))
            ->get();

        if ($materias->isEmpty()) {
            return null;
        }

        $porRevisar = $this->entregasPorRevisar($materias->pluck('id'));
        $sinLeer = $this->mensajesSinLeer($materias->pluck('id'), $personaId);

        $renglones = $materias
            ->map(function (AsignaturaGrupo $ag) use ($porRevisar, $sinLeer) {
                $entregas = $porRevisar[$ag->id] ?? 0;
                $mensajes = $sinLeer[$ag->id] ?? 0;

                if ($entregas === 0 && $mensajes === 0) {
                    return null;
                }

                // Se dice qué es lo que espera, no solo cuánto: «3» obligaría a
                // entrar para saber si son entregas o mensajes.
                $partes = [];

                if ($entregas > 0) {
                    $partes[] = $entregas === 1 ? '1 entrega' : "{$entregas} entregas";
                }

                if ($mensajes > 0) {
                    $partes[] = $mensajes === 1 ? '1 mensaje' : "{$mensajes} mensajes";
                }

                return [
                    'etiqueta' => $ag->planMateria?->asignatura?->nombre ?? 'Materia',
                    'detalle' => $ag->grupo?->clave,
                    'valor' => implode(' · ', $partes),
                    // Al chat si solo hay mensajes; si hay entregas, al libro de
                    // calificaciones, que es donde se resuelven.
                    'enlace' => $entregas > 0
                        ? "/docencia/materias/{$ag->id}"
                        : "/materias/{$ag->id}/chat",
                ];
            })
            ->filter()
            ->values();

        // Nada pendiente: la tarjeta se calla en vez de ocupar espacio para
        // decir que no hay nada.
        if ($renglones->isEmpty()) {
            return null;
        }

        return ['renglones' => $renglones->all()];
    }

    /**
     * Entregas ya hechas y sin calificar, por materia.
     *
     * En UNA consulta para todas: con seis materias y treinta alumnos, contar de
     * a una son seis recorridos completos cada vez que alguien abre el panel.
     *
     * @param  \Illuminate\Support\Collection<int, int>  $materiaIds
     * @return array<int, int>
     */
    private function entregasPorRevisar($materiaIds): array
    {
        $cursos = Curso::query()
            ->whereIn('asignatura_grupo_id', $materiaIds)
            ->pluck('asignatura_grupo_id', 'id');

        if ($cursos->isEmpty()) {
            return [];
        }

        return Entrega::query()
            ->selectRaw('actividades.curso_id, count(*) as total')
            ->join('actividades', 'actividades.id', '=', 'entregas.actividad_id')
            ->whereIn('actividades.curso_id', $cursos->keys())
            ->whereNull('entregas.deleted_at')
            ->whereNull('actividades.deleted_at')
            ->whereNotNull('entregas.entregada_en')
            ->whereNull('entregas.calificacion')
            ->groupBy('actividades.curso_id')
            ->pluck('total', 'curso_id')
            // De curso a materia: la tarjeta habla de materias, no de cursos.
            ->mapWithKeys(fn ($total, $cursoId) => [(int) $cursos->get($cursoId) => (int) $total])
            ->all();
    }

    /**
     * Mensajes sin leer, por materia.
     *
     * Cuenta las conversaciones del docente en esas materias —el canal del grupo
     * y sus directas— con lo que llegó después de su última lectura. Lo propio no
     * cuenta: uno no se debe respuesta a sí mismo.
     *
     * @param  \Illuminate\Support\Collection<int, int>  $materiaIds
     * @return array<int, int>
     */
    private function mensajesSinLeer($materiaIds, int $personaId): array
    {
        $conversaciones = Conversacion::query()
            ->whereIn('asignatura_grupo_id', $materiaIds)
            ->where(fn ($q) => $q->where('tipo', Conversacion::GRUPO)
                ->orWhere('persona_a_id', $personaId)
                ->orWhere('persona_b_id', $personaId))
            ->pluck('asignatura_grupo_id', 'id');

        if ($conversaciones->isEmpty()) {
            return [];
        }

        $conteos = Mensaje::query()
            ->selectRaw('mensajes.conversacion_id, count(*) as total')
            ->leftJoin('conversacion_lecturas as l', function ($join) use ($personaId) {
                $join->on('l.conversacion_id', '=', 'mensajes.conversacion_id')
                    ->where('l.persona_id', '=', $personaId);
            })
            ->whereIn('mensajes.conversacion_id', $conversaciones->keys())
            ->where('mensajes.persona_id', '!=', $personaId)
            ->whereNull('mensajes.deleted_at')
            ->where(fn ($q) => $q->whereNull('l.ultimo_mensaje_id')
                ->orWhereColumn('mensajes.id', '>', 'l.ultimo_mensaje_id'))
            ->groupBy('mensajes.conversacion_id')
            ->pluck('total', 'conversacion_id');

        $porMateria = [];

        foreach ($conteos as $conversacionId => $total) {
            $materiaId = (int) $conversaciones->get($conversacionId);
            $porMateria[$materiaId] = ($porMateria[$materiaId] ?? 0) + (int) $total;
        }

        return $porMateria;
    }
}
