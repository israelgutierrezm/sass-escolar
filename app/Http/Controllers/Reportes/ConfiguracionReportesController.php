<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reportes;

use App\Exceptions\AvisoParaElUsuario;
use App\Http\Controllers\Controller;
use App\Models\Reportes\AreaReporte;
use App\Models\Reportes\UbicacionReporte;
use App\Reportes\DefinicionReporte;
use App\Reportes\RegistroReportes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Configuración de Reportes: las áreas y dónde vive cada reporte.
 *
 * ── Un área es una CARPETA ───────────────────────────────────────────────
 * Mover un reporte de área NO cambia quién puede verlo: eso lo decide el
 * permiso de su fuente. Se dice con palabras en la pantalla, porque es
 * exactamente lo que alguien podría suponer al arrastrar un reporte de finanzas
 * a un área llamada «Dirección».
 */
class ConfiguracionReportesController extends Controller
{
    public function __construct(private readonly RegistroReportes $registro) {}

    public function index(Request $peticion): Response
    {
        $ubicaciones = UbicacionReporte::query()->get()->keyBy('reporte');

        /*
         * Cuantos reportes vive de verdad en cada area.
         *
         * Contar solo `ubicaciones_reporte` era contar los MOVIDOS: un reporte
         * que nunca se movio no tiene fila y vive en su area por omision. Con
         * esa cuenta, un area recien sembrada figuraba con cero y se ofrecia el
         * boton de eliminar --y borrarla dejaba sus reportes sin sitio--.
         */
        // Los ids por clave, UNA vez: se resolvia con una consulta dentro del
        // map, o sea una por reporte.
        $idsPorClave = AreaReporte::query()->pluck('id', 'clave')->all();

        $porArea = [];

        foreach ($this->registro->todos() as $reporte) {
            $clave = $ubicaciones->get($reporte->clave())?->area?->clave ?? $reporte->areaSugerida();
            $porArea[$clave] = ($porArea[$clave] ?? 0) + 1;
        }

        return Inertia::render('Reportes/Configuracion', [
            'areas' => AreaReporte::query()
                ->orderBy('orden')
                ->get(['id', 'clave', 'nombre', 'descripcion', 'orden', 'activo'])
                ->map(fn (AreaReporte $a) => array_merge($a->only([
                    'id', 'clave', 'nombre', 'descripcion', 'orden', 'activo',
                ]), [
                    // Sólo se borra un área VACÍA: con reportes dentro, borrarla
                    // los dejaría sin sitio. Para retirarla del índice se apaga.
                    'cuantos' => $porArea[$a->clave] ?? 0,
                ]))
                ->values(),

            /*
             * TODOS los reportes registrados, no sólo los que esta persona
             * puede ejecutar: quien acomoda el índice tiene que poder mover
             * también los que él no usa.
             */
            'reportes' => array_values(array_map(function (DefinicionReporte $r) use ($ubicaciones, $idsPorClave) {
                $u = $ubicaciones->get($r->clave());

                return [
                    'clave' => $r->clave(),
                    'titulo' => $r->titulo(),
                    'tituloPropio' => $u?->nombre,
                    'descripcion' => $r->descripcion(),
                    'areaClave' => $u?->area?->clave ?? $r->areaSugerida(),
                    'areaId' => $u?->area_id ?? $idsPorClave[$r->areaSugerida()] ?? null,
                    'activo' => $u?->activo ?? true,
                    'movido' => $u !== null,
                ];
            }, $this->registro->todos())),
        ]);
    }

    public function guardarArea(Request $peticion, ?AreaReporte $area = null): RedirectResponse
    {
        $datos = $peticion->validate([
            'nombre' => ['required', 'string', 'max:80'],
            'descripcion' => ['nullable', 'string', 'max:255'],
        ]);

        if ($area === null) {
            // La CLAVE de un área nueva se genera: es de código y no se edita,
            // igual que en los roles protegidos. Lo que la escuela escribe es el
            // nombre.
            $datos['clave'] = $this->claveLibre($datos['nombre']);
            $datos['orden'] = (int) AreaReporte::query()->max('orden') + 1;

            AreaReporte::query()->create($datos);

            return back(303)->with('exito', 'Área creada.');
        }

        // La clave NO se toca aunque venga en la petición: hay reportes que
        // declaran nacer en ella.
        $area->update($datos);

        return back(303)->with('exito', 'Área actualizada.');
    }

    /** Enciende o apaga un área. Apagada, esconde lo que tiene dentro. */
    public function alternarArea(Request $peticion, AreaReporte $area): RedirectResponse
    {
        $area->update(['activo' => $peticion->boolean('activo')]);

        return back(303)->with(
            'exito',
            $area->activo
                ? 'Área encendida.'
                : 'Área apagada: sus reportes dejan de aparecer en el índice, pero no se borró ninguno.',
        );
    }

    public function eliminarArea(AreaReporte $area): RedirectResponse
    {
        AvisoParaElUsuario::si(
            $area->ubicaciones()->exists(),
            422,
            'Esa área tiene reportes dentro. Muévelos a otra o apágala para retirarla del índice.',
        );

        $area->delete();

        return back(303)->with('exito', 'Área eliminada.');
    }

    /** Mueve un reporte de área, lo renombra o lo apaga. */
    public function ubicarReporte(Request $peticion, string $clave): RedirectResponse
    {
        // Se comprueba contra el REGISTRO: un reporte inventado no puede quedar
        // guardado como una ubicación que nadie va a encontrar.
        $this->registro->definicion($clave);

        $datos = $peticion->validate([
            'area_id' => ['required', Rule::exists('areas_reporte', 'id')->whereNull('deleted_at')],
            'nombre' => ['nullable', 'string', 'max:120'],
            'activo' => ['required', 'boolean'],
        ]);

        /*
         * `updateOrCreate` por la CLAVE del reporte: es lo que de verdad
         * garantiza una sola ubicación viva. El índice único de la tabla no
         * basta, porque MySQL trata cada `deleted_at` en null como distinto.
         */
        UbicacionReporte::query()->updateOrCreate(
            ['reporte' => $clave],
            [
                'area_id' => $datos['area_id'],
                // Vacío se guarda como NULL, que significa «el título que
                // declara la clase» y no «sin nombre»: así un reporte renombrado
                // en el código se sigue actualizando solo.
                'nombre' => blank($datos['nombre']) ? null : $datos['nombre'],
                'activo' => $datos['activo'],
            ],
        );

        return back(303)->with('exito', 'Reporte ubicado.');
    }

    /** Una clave sin acentos ni espacios, libre. */
    private function claveLibre(string $nombre): string
    {
        $base = trim(str()->slug($nombre)) ?: 'area';
        $clave = $base;
        $i = 2;

        while (AreaReporte::query()->where('clave', $clave)->exists()) {
            $clave = $base.'-'.$i++;
        }

        return mb_substr($clave, 0, 50);
    }
}
