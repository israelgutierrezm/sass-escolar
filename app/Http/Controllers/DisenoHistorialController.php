<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Historial\CatalogoColumnas;
use App\Models\ControlEscolar\DisenoHistorial;
use App\Models\Landlord\NivelEstudio;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as Pantalla;

/**
 * Cómo se imprime el historial académico de la escuela.
 *
 * ── Sin rol que elegir, a diferencia de la credencial ─────────────────────
 * El historial es de los alumnos y de nadie más: no hay un historial de docente
 * ni de administrativo. Lo único que varía es el NIVEL de estudios —un
 * bachillerato imprime semestres y una licenciatura créditos—, así que la
 * variante se resuelve igual que allá: la del nivel si existe, y si no la
 * general.
 */
class DisenoHistorialController extends Controller
{
    public function index(): Pantalla
    {
        return Inertia::render('Escolar/Configuraciones/Historial', [
            'disenos' => DisenoHistorial::query()->get()->map(fn (DisenoHistorial $d) => $this->aPantalla($d))->values(),
            'columnas' => CatalogoColumnas::columnas(),
            'datos' => CatalogoColumnas::datosDelAlumno(),
            'agrupaciones' => CatalogoColumnas::AGRUPACIONES,
            'papeles' => CatalogoColumnas::PAPELES,
            'orientaciones' => CatalogoColumnas::ORIENTACIONES,
            'niveles' => NivelEstudio::query()->orderBy('nombre')->get(['id', 'nombre']),
            'omision' => CatalogoColumnas::porOmision(),
        ]);
    }

    public function guardar(Request $peticion): RedirectResponse
    {
        $datos = $this->validado($peticion);

        DisenoHistorial::query()
            ->firstOrNew(['nivel_estudios_id' => $datos['nivel_estudios_id']])
            ->fill($datos)
            ->save();

        return back(303)->with('exito', 'Diseño guardado.');
    }

    /** Retira la variante de un nivel; el general no se borra. */
    public function eliminar(DisenoHistorial $diseno): RedirectResponse
    {
        abort_if($diseno->nivel_estudios_id === null, 422, 'El diseño general no se elimina.');

        $diseno->delete();

        return back(303)->with('exito', 'Variante eliminada. Ese nivel vuelve a usar el diseño general.');
    }

    /**
     * Sube la firma o el sello, o los quita.
     *
     * Al disco PRIVADO. La firma de quien encabeza servicios escolares y el
     * sello de la escuela son justamente las dos piezas que hacen falta para
     * falsificar un historial: en `public/` cualquiera se las descarga sin
     * sesión.
     */
    public function subir(Request $peticion, DisenoHistorial $diseno): RedirectResponse
    {
        $datos = $peticion->validate([
            'campo' => ['required', Rule::in(['firma_imagen', 'sello_imagen'])],
            'archivo' => ['nullable', 'image', 'mimes:png,jpg,jpeg', 'max:4096'],
        ]);

        $anterior = $diseno->{$datos['campo']};

        $ruta = $peticion->hasFile('archivo')
            ? $peticion->file('archivo')->store('historial', 'local')
            : null;

        $diseno->update([$datos['campo'] => $ruta]);

        if (filled($anterior)) {
            Storage::disk('local')->delete($anterior);
        }

        return back(303)->with('exito', $ruta === null ? 'Imagen retirada.' : 'Imagen cargada.');
    }

    /**
     * La firma o el sello, para la pantalla y para el documento impreso.
     *
     * Sólo exige sesión, no `gestionar-historial`: quien mira un historial
     * impreso —el alumno con el suyo, un docente con el de su tutorado— tiene
     * que ver la firma, y no por eso puede rediseñar el documento.
     */
    public function imagen(DisenoHistorial $diseno, string $campo): Response
    {
        abort_unless(in_array($campo, ['firma_imagen', 'sello_imagen'], true), 404);

        $ruta = $diseno->{$campo};

        abort_if(blank($ruta) || ! Storage::disk('local')->exists($ruta), 404);

        return Storage::disk('local')->response($ruta);
    }

    /** @return array<string, mixed> */
    private function validado(Request $peticion): array
    {
        $datos = $peticion->validate([
            'nivel_estudios_id' => ['nullable', 'integer'],
            'titulo' => ['required', 'string', 'max:120'],
            'subtitulo' => ['nullable', 'string', 'max:160'],
            'muestra_logo' => ['required', 'boolean'],
            'muestra_nombre_escuela' => ['required', 'boolean'],
            'campos_alumno' => ['nullable', 'array'],
            'columnas' => ['nullable', 'array'],
            'agrupacion' => ['required', Rule::in(array_keys(CatalogoColumnas::AGRUPACIONES))],
            'muestra_resumen' => ['required', 'boolean'],
            'muestra_promedio' => ['required', 'boolean'],
            'muestra_creditos' => ['required', 'boolean'],
            'leyenda' => ['nullable', 'string', 'max:600'],
            'responsable_nombre' => ['nullable', 'string', 'max:120'],
            'responsable_cargo' => ['nullable', 'string', 'max:120'],
            'tamano_papel' => ['required', Rule::in(CatalogoColumnas::PAPELES)],
            'orientacion' => ['required', Rule::in(CatalogoColumnas::ORIENTACIONES)],
            'descarga_alumno' => ['required', 'boolean'],
            'marca_agua_alumno' => ['required', 'boolean'],
            'marca_agua_texto' => ['required', 'string', 'max:80'],
        ]);

        /*
         * Se filtran contra el catálogo y se conserva el ORDEN que mandó la
         * pantalla.
         *
         * Lo primero porque esto es un JSON que cualquiera con el permiso puede
         * escribir a mano, y una clave inventada quedaría guardada para siempre
         * como una cabecera que nadie rellena. Lo segundo porque el orden ES
         * parte del diseño: mover «Créditos» antes de «Ciclo» es lo que se está
         * decidiendo aquí.
         */
        $datos['columnas'] = array_values(array_filter(
            $datos['columnas'] ?? [],
            fn ($c) => is_string($c) && CatalogoColumnas::existeColumna($c),
        ));

        $datos['campos_alumno'] = array_values(array_filter(
            $datos['campos_alumno'] ?? [],
            fn ($c) => is_string($c) && CatalogoColumnas::existeDato($c),
        ));

        return $datos;
    }

    /** @return array<string, mixed> */
    private function aPantalla(DisenoHistorial $diseno): array
    {
        return array_merge($diseno->only([
            'id', 'nivel_estudios_id', 'titulo', 'subtitulo', 'muestra_logo', 'muestra_nombre_escuela',
            'campos_alumno', 'columnas', 'agrupacion', 'muestra_resumen', 'muestra_promedio',
            'muestra_creditos', 'leyenda', 'responsable_nombre', 'responsable_cargo',
            'tamano_papel', 'orientacion', 'descarga_alumno', 'marca_agua_alumno', 'marca_agua_texto',
        ]), [
            'tiene_firma' => filled($diseno->firma_imagen),
            'tiene_sello' => filled($diseno->sello_imagen),
        ]);
    }
}
