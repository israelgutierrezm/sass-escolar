<?php

declare(strict_types=1);

namespace App\Http\Controllers\Disciplina;

use App\Http\Controllers\Controller;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Disciplina\Incidencia;
use App\Models\Disciplina\TipoIncidencia;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Las incidencias de conducta, desde control escolar.
 *
 * `reportada_por` se toma de la SESIÓN, no de la petición: quien la levanta es
 * quien está capturando, y dejar que el navegador diga «la reportó fulano»
 * permitiría atribuirla a otro.
 */
class IncidenciaController extends Controller
{
    public function index(Request $peticion): Response
    {
        $filtros = [
            'busqueda' => trim((string) $peticion->query('busqueda', '')),
            'tipo_id' => $peticion->query('tipo_id'),
        ];

        $incidencias = Incidencia::query()
            ->with([
                'matricula:id,matricula,persona_id',
                'matricula.persona:id,nombre,primer_apellido,segundo_apellido',
                'tipo:id,nombre,nivel',
                'reporta:id,nombre,primer_apellido,segundo_apellido',
            ])
            ->when($filtros['busqueda'] !== '', function ($q) use ($filtros) {
                $termino = "%{$filtros['busqueda']}%";

                $q->whereHas('matricula', function ($m) use ($termino) {
                    $m->where('matricula', 'like', $termino)
                        ->orWhereHas('persona', fn ($p) => $p
                            ->whereRaw("TRIM(CONCAT_WS(' ', nombre, primer_apellido, segundo_apellido)) LIKE ?", [$termino]));
                });
            })
            ->when($filtros['tipo_id'], fn ($q, $v) => $q->where('tipo_incidencia_id', $v))
            ->orderByDesc('fecha')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Escolar/Incidencias', [
            'incidencias' => $incidencias->through(fn (Incidencia $i) => $this->aFila($i)),
            'filtros' => $filtros,
            'tipos' => TipoIncidencia::query()->activos()->get(['id', 'nombre', 'nivel']),
        ]);
    }

    public function guardar(Request $peticion, ?Incidencia $incidencia = null): RedirectResponse
    {
        $datos = $peticion->validate([
            'matricula_oferta_id' => ['required', Rule::exists('matricula_oferta', 'id')->whereNull('deleted_at')],
            'tipo_incidencia_id' => ['required', Rule::exists('tipos_incidencia', 'id')],
            'fecha' => ['required', 'date'],
            'descripcion' => ['required', 'string', 'max:2000'],
        ], [], [
            'matricula_oferta_id' => 'alumno',
            'tipo_incidencia_id' => 'tipo de incidencia',
        ]);

        if ($incidencia === null) {
            $datos['reportada_por'] = Auth::user()?->persona_id;
            Incidencia::create($datos);

            return back(303)->with('exito', 'Incidencia registrada.');
        }

        // Al editar NO se reescribe quién la reportó: sigue siendo quien la vio.
        $incidencia->update($datos);

        return back(303)->with('exito', 'Incidencia actualizada.');
    }

    public function eliminar(Incidencia $incidencia): RedirectResponse
    {
        // Borrado LÓGICO (TieneAuditoria): una incidencia retirada es parte del
        // historial de conducta, y si una sanción la citó, `incidencia_sancion`
        // la conserva.
        $incidencia->delete();

        return back(303)->with('exito', 'Incidencia retirada.');
    }

    /** @return array<string, mixed> */
    private function aFila(Incidencia $i): array
    {
        return [
            'id' => $i->id,
            'matricula_oferta_id' => $i->matricula_oferta_id,
            'matricula' => $i->matricula?->matricula,
            'alumno' => $i->matricula?->persona?->nombreCompleto(),
            'tipo_id' => $i->tipo_incidencia_id,
            'tipo' => $i->tipo?->nombre,
            'nivel' => $i->tipo?->nivel,
            'fecha' => $i->fecha?->format('Y-m-d'),
            'descripcion' => $i->descripcion,
            'reporta' => $i->reporta?->nombreCompleto(),
        ];
    }
}
