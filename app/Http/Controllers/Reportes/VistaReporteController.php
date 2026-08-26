<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reportes;

use App\Exceptions\AvisoParaElUsuario;
use App\Http\Controllers\Controller;
use App\Models\Reportes\ReporteFavorito;
use App\Models\Reportes\VistaReporte;
use App\Reportes\RegistroReportes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Vistas guardadas de un reporte, y favoritos.
 *
 * ── La regla que lo sostiene todo ────────────────────────────────────────
 * Una vista guarda COLUMNAS y FILTROS, jamás filas. Al ejecutarla, el motor
 * rehace el pipeline completo —permiso, faceta, módulo y alcance por campus—
 * con los de quien la abre. Por eso una vista se puede compartir sin compartir
 * datos: el coordinador del campus norte que abre la vista de dirección
 * general ve el norte.
 *
 * Aquí no hay nada que autorice el ACCESO: eso lo sigue decidiendo el permiso
 * de la fuente. Lo único que se comprueba aquí es de quién es la vista.
 */
class VistaReporteController extends Controller
{
    public function __construct(private readonly RegistroReportes $registro) {}

    public function guardar(Request $peticion, string $clave): RedirectResponse
    {
        // Contra el REGISTRO: una vista de un reporte inventado quedaría
        // guardada para siempre sin que nadie pueda abrirla.
        $this->registro->definicion($clave);

        $usuario = $peticion->user();

        $datos = $peticion->validate([
            'nombre' => ['required', 'string', 'max:120'],
            'descripcion' => ['nullable', 'string', 'max:255'],
            'columnas' => ['nullable', 'array'],
            'columnas.*' => ['string'],
            'filtros' => ['nullable', 'array'],
            'orden_por' => ['nullable', 'string', 'max:60'],
            'orden_dir' => ['nullable', Rule::in(['asc', 'desc'])],
            'predeterminada' => ['nullable', 'boolean'],
            // De la escuela = sin dueño. Sólo quien organiza puede hacerlo: una
            // vista que ve todo el mundo no la puede crear cualquiera.
            'de_la_escuela' => ['nullable', 'boolean'],
            'rol_id' => ['nullable', Rule::exists('roles', 'id')],
        ]);

        $deLaEscuela = (bool) ($datos['de_la_escuela'] ?? false);

        AvisoParaElUsuario::si(
            $deLaEscuela && ! $usuario->can('gestionar-areas-reporte'),
            403,
            'Para guardar una vista que vea toda la escuela hace falta el permiso de organizar los reportes.',
        );

        /*
         * Compartir a un ROL es lo mismo que compartir a la escuela, en pequeño.
         *
         * Sin esto, cualquiera con `ver-reportes` podía plantarle una vista al
         * rol que quisiera —a dirección general, por ejemplo— y encima ser el
         * único que la puede quitar, porque el dueño sigue siendo él. Un
         * elemento en la pantalla de otro que ese otro no puede retirar.
         */
        AvisoParaElUsuario::si(
            filled($datos['rol_id'] ?? null) && ! $usuario->can('gestionar-areas-reporte'),
            403,
            'Para compartirle una vista a un rol hace falta el permiso de organizar los reportes.',
        );

        DB::connection('tenant')->transaction(function () use ($datos, $clave, $usuario, $deLaEscuela) {
            $vista = VistaReporte::query()->create([
                'reporte' => $clave,
                'nombre' => $datos['nombre'],
                'descripcion' => $datos['descripcion'] ?? null,
                // Se guarda lo que se pidió TAL CUAL. El saneado contra el
                // catálogo se hace al EJECUTAR, no aquí: una columna retirada
                // del código el año que viene no debe impedir abrir la vista,
                // sólo desaparecer de ella.
                'columnas' => $datos['columnas'] ?? null,
                'filtros' => $datos['filtros'] ?? null,
                'orden_por' => $datos['orden_por'] ?? null,
                'orden_dir' => $datos['orden_dir'] ?? 'asc',
                'persona_id' => $deLaEscuela ? null : $usuario->persona_id,
                'rol_id' => $datos['rol_id'] ?? null,
                'predeterminada' => (bool) ($datos['predeterminada'] ?? false),
            ]);

            if ($vista->predeterminada) {
                $this->dejarUnaSolaPredeterminada($vista, $usuario->persona_id);
            }
        });

        return back(303)->with('exito', 'Vista guardada.');
    }

    public function eliminar(Request $peticion, VistaReporte $vista): RedirectResponse
    {
        AvisoParaElUsuario::si(
            ! $vista->laPuedeEditar($peticion->user()),
            403,
            'Esa vista no es tuya.',
        );

        $vista->delete();

        return back(303)->with('exito', 'Vista eliminada.');
    }

    /** Marca o desmarca un reporte como favorito. */
    public function favorito(Request $peticion, string $clave): RedirectResponse
    {
        $this->registro->definicion($clave);

        $personaId = $peticion->user()->persona_id;

        $existente = ReporteFavorito::query()
            ->where('persona_id', $personaId)
            ->where('reporte', $clave)
            ->first();

        if ($existente !== null) {
            $existente->forceDelete();

            return back(303);
        }

        /*
         * `firstOrCreate` y no `create`: el doble clic impaciente manda dos
         * peticiones a la vez y con `create` la segunda revienta contra el
         * índice único, devolviéndole un error de base a quien sólo quería
         * marcar un favorito.
         */
        ReporteFavorito::query()->firstOrCreate(['persona_id' => $personaId, 'reporte' => $clave]);

        return back(303);
    }

    /**
     * Una sola predeterminada por persona y reporte.
     *
     * Con dos, la que se abriera sería la que saliera primero —o sea, al azar—,
     * y quien la configuró creería que el sistema le ignora.
     */
    private function dejarUnaSolaPredeterminada(VistaReporte $vista, ?int $personaId): void
    {
        VistaReporte::query()
            ->where('reporte', $vista->reporte)
            ->where('persona_id', $personaId)
            ->whereKeyNot($vista->id)
            ->update(['predeterminada' => false]);
    }
}
