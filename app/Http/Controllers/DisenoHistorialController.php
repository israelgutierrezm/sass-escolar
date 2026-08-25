<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Historial\CatalogoColumnas;
use App\Historial\HistorialImprimible;
use App\Historial\HistorialPdf;
use App\Models\Academico\NivelEstudio;
use App\Models\ControlEscolar\DisenoHistorial;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as Pantalla;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
            'bloquesPorFila' => CatalogoColumnas::BLOQUES_POR_FILA,
            'papeles' => CatalogoColumnas::PAPELES,
            'orientaciones' => CatalogoColumnas::ORIENTACIONES,
            'fuentes' => CatalogoColumnas::FUENTES,
            'niveles' => NivelEstudio::query()->activos()->orderBy('nombre')->get(['id', 'nombre']),
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
    public function imagen(DisenoHistorial $diseno, string $campo): StreamedResponse
    {
        abort_unless(in_array($campo, ['firma_imagen', 'sello_imagen'], true), 404);

        $ruta = $diseno->{$campo};

        abort_if(blank($ruta) || ! Storage::disk('local')->exists($ruta), 404);

        return Storage::disk('local')->response($ruta);
    }

    /**
     * La vista previa: el documento con datos inventados.
     *
     * ── Se dibuja sobre lo que hay EN EL FORMULARIO ───────────────────────
     * No sobre lo guardado, y sin exigir que la fila exista. Quien está
     * decidiendo si el historial va a una o a dos columnas necesita verlo ANTES
     * de guardarlo; obligar a guardar para mirar convierte cada prueba en un
     * cambio real sobre el documento oficial de la escuela. Por eso va por POST
     * y no por GET: lo que se dibuja son los campos del formulario.
     *
     * ── Es el PDF DE VERDAD, no una aproximación en HTML ──────────────────
     * Antes dibujaba `impresion.historial`, que es la vista del NAVEGADOR. Desde
     * que el documento se genera con mpdf eso dejó de servir para lo que una
     * vista previa existe: enseñaba una maqueta distinta de la que se imprime
     * —otra tipografía, otros cortes de página, sin folio ni membrete
     * repetido—, así que quien acomodaba columnas las acomodaba contra un
     * documento que nadie iba a recibir.
     */
    public function vistaPrevia(Request $peticion): Response
    {
        $guardado = DisenoHistorial::query()->find($peticion->integer('diseno_id'));

        $diseno = ($guardado ?? new DisenoHistorial(CatalogoColumnas::porOmision()))
            ->fill($this->validado($peticion));

        $bytes = app(HistorialPdf::class)->generar(
            app(HistorialImprimible::class)->armarEjemplo($diseno)
        );

        return new Response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="vista-previa.pdf"',
            // La vista previa cambia con cada tecla del formulario: si el
            // navegador la cachea, el diseñador vuelve a ser ciego.
            'Cache-Control' => 'no-store, max-age=0',
        ]);
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
            'bloques_por_fila' => ['required', 'integer', 'in:1,2'],
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
            'marca_agua_ventanilla' => ['required', 'boolean'],
            'marca_agua_texto' => ['required', 'string', 'max:80'],
            // Topes con sentido físico, no números redondos: por debajo de 1 la
            // marca no se ve y por encima de 60 tapa las calificaciones.
            'marca_agua_opacidad' => ['required', 'integer', 'min:1', 'max:60'],
            /*
             * Márgenes en milímetros. El máximo de 80 no es capricho: sobre
             * carta (216 mm de ancho), 80 arriba y 80 abajo dejan 119 mm de
             * alto útil, que es lo mínimo donde caben la tabla y su cabecera.
             * Más que eso produce un documento que no puede imprimir nada.
             */
            'margen_superior' => ['required', 'integer', 'min:5', 'max:80'],
            'margen_inferior' => ['required', 'integer', 'min:5', 'max:80'],
            'margen_izquierdo' => ['required', 'integer', 'min:5', 'max:50'],
            'margen_derecho' => ['required', 'integer', 'min:5', 'max:50'],
            'fuente' => ['required', Rule::in(array_keys(CatalogoColumnas::FUENTES))],
            // Por debajo de 6 pt no se lee en papel; por encima de 14 un
            // historial de una egresada se vuelve un tomo.
            'tamano_fuente' => ['required', 'numeric', 'min:6', 'max:14'],
            'interlineado' => ['required', 'numeric', 'min:1', 'max:2'],
            'salto_por_bloque' => ['required', 'boolean'],
            'usa_color_acento' => ['required', 'boolean'],
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
            'campos_alumno', 'columnas', 'agrupacion', 'bloques_por_fila', 'muestra_resumen', 'muestra_promedio',
            'muestra_creditos', 'leyenda', 'responsable_nombre', 'responsable_cargo',
            'tamano_papel', 'orientacion', 'descarga_alumno', 'marca_agua_alumno', 'marca_agua_texto',
            'marca_agua_ventanilla', 'marca_agua_opacidad',
            'margen_superior', 'margen_inferior', 'margen_izquierdo', 'margen_derecho',
            'fuente', 'tamano_fuente', 'interlineado', 'salto_por_bloque', 'usa_color_acento',
        ]), [
            'tiene_firma' => filled($diseno->firma_imagen),
            'tiene_sello' => filled($diseno->sello_imagen),
        ]);
    }
}
