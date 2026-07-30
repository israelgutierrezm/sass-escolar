<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Identidad\TutorAlumno;
use App\Services\Suplantador;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Directorio de padres y tutores: la vista de administración de las personas
 * vinculadas como padre/tutor a uno o más alumnos.
 *
 * El VÍNCULO en sí (agregar, quitar, permisos) se administra desde el
 * expediente de cada alumno —ahí está el contexto—; aquí se ve el panorama:
 * quién es tutor de quién, y se puede «Ver como» esa persona para dar soporte.
 */
class TutorController extends Controller
{
    public function index(Request $request): Response
    {
        $suplantador = app(Suplantador::class);

        $tutores = TutorAlumno::query()
            ->with([
                'tutor:id,nombre,primer_apellido,segundo_apellido,curp,email',
                'alumno:id,nombre,primer_apellido,segundo_apellido',
            ])
            ->get()
            ->groupBy('tutor_persona_id')
            ->map(function ($vinculos) use ($request, $suplantador) {
                $persona = $vinculos->first()->tutor;

                return [
                    'persona_id' => $persona?->id,
                    'nombre' => $this->nombre($persona),
                    'curp' => $persona?->curp,
                    'email' => $persona?->email,
                    'total_alumnos' => $vinculos->count(),
                    'alumnos' => $vinculos->map(fn (TutorAlumno $v) => [
                        'nombre' => $this->nombre($v->alumno),
                        'parentesco' => $v->parentesco,
                    ])->values(),
                    // «Ver como»: solo si esa persona tiene cuenta con la que entrar.
                    'suplantable' => $suplantador->datosPara($request, $persona),
                ];
            })
            ->sortBy('nombre')
            ->values();

        return Inertia::render('Padres/Index', [
            'tutores' => $tutores,
        ]);
    }

    private function nombre(?\App\Models\Identidad\Persona $p): string
    {
        return trim(implode(' ', array_filter([$p?->nombre, $p?->primer_apellido, $p?->segundo_apellido])));
    }
}
