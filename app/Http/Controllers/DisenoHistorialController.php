<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\AvisoParaElUsuario;
use App\Historial\CatalogoColumnas;
use App\Historial\HistorialImprimible;
use App\Historial\HistorialPdf;
use App\Models\Academico\NivelEstudio;
use App\Models\ControlEscolar\DisenoHistorial;
use App\Models\ControlEscolar\FirmanteHistorial;
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
            'disenos' => DisenoHistorial::query()->with('firmantes')->get()->map(fn (DisenoHistorial $d) => $this->aPantalla($d))->values(),
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
     * Sube el sello de la escuela, o lo quita.
     *
     * Al disco PRIVADO: el sello y las firmas son justamente las dos piezas que
     * hacen falta para falsificar un historial, y ahi cualquiera se las
     * descargaria sin sesion.
     *
     * Las FIRMAS ya no pasan por aqui: cuelgan de cada firmante, no del diseno.
     */
    public function subir(Request $peticion, DisenoHistorial $diseno): RedirectResponse
    {
        $peticion->validate([
            'archivo' => ['nullable', 'image', 'mimes:png,jpg,jpeg', 'max:4096'],
        ]);

        $anterior = $diseno->sello_imagen;

        $ruta = $peticion->hasFile('archivo')
            ? $peticion->file('archivo')->store('historial', 'local')
            : null;

        $diseno->update(['sello_imagen' => $ruta]);

        if (filled($anterior)) {
            Storage::disk('local')->delete($anterior);
        }

        return back(303)->with('exito', $ruta === null ? 'Imagen retirada.' : 'Imagen cargada.');
    }

    /**
     * Guarda un firmante: alta, edicion y su imagen de firma, en una sola
     * puerta.
     *
     * Van juntas porque en la pantalla es un solo gesto --se escribe el nombre y
     * se elige la firma--, y separarlas obligaria a guardar el firmante antes de
     * poder ponerle su rubrica, que es el paso intermedio que nadie entiende.
     */
    public function guardarFirmante(Request $peticion, DisenoHistorial $diseno, ?FirmanteHistorial $firmante = null): RedirectResponse
    {
        AvisoParaElUsuario::si(
            $firmante !== null && $firmante->diseno_id !== $diseno->id,
            404,
            'Ese firmante no es de este diseno.',
        );

        $datos = $peticion->validate([
            'nombre' => ['required', 'string', 'max:120'],
            'cargo' => ['nullable', 'string', 'max:120'],
            'archivo' => ['nullable', 'image', 'mimes:png,jpg,jpeg', 'max:4096'],
            'quitar_firma' => ['nullable', 'boolean'],
        ]);

        /*
         * Tope de CUATRO.
         *
         * No es un numero redondo: las rubricas se reparten el ancho de la hoja
         * en una fila, y con cinco la linea de cada una queda mas corta que el
         * nombre que va debajo --medido sobre carta con los margenes por
         * omision--. Ofrecer un quinto seria ofrecer un documento ilegible.
         */
        AvisoParaElUsuario::si(
            $firmante === null && $diseno->firmantes()->count() >= 4,
            422,
            'Un historial admite hasta cuatro firmantes: con mas, las lineas de firma no caben en el ancho de la hoja.',
        );

        $anterior = $firmante?->firma_imagen;

        $ruta = match (true) {
            $peticion->hasFile('archivo') => $peticion->file('archivo')->store('historial', 'local'),
            (bool) ($datos['quitar_firma'] ?? false) => null,
            // Sin archivo nuevo ni orden de quitarla, se CONSERVA la que habia:
            // editar el cargo no puede borrar la rubrica.
            default => $anterior,
        };

        if ($firmante === null) {
            $diseno->firmantes()->create([
                'nombre' => $datos['nombre'],
                'cargo' => $datos['cargo'] ?? null,
                'firma_imagen' => $ruta,
                'orden' => (int) $diseno->firmantes()->max('orden') + 1,
            ]);
        } else {
            $firmante->update([
                'nombre' => $datos['nombre'],
                'cargo' => $datos['cargo'] ?? null,
                'firma_imagen' => $ruta,
            ]);
        }

        // El archivo viejo se borra DESPUES de guardar la ruta nueva: al reves,
        // un fallo al guardar dejaria el diseno apuntando a un archivo que ya no
        // esta y la firma desapareceria del documento.
        if (filled($anterior) && $anterior !== $ruta) {
            Storage::disk('local')->delete($anterior);
        }

        return back(303)->with('exito', 'Firmante guardado.');
    }

    public function eliminarFirmante(DisenoHistorial $diseno, FirmanteHistorial $firmante): RedirectResponse
    {
        AvisoParaElUsuario::si($firmante->diseno_id !== $diseno->id, 404, 'Ese firmante no es de este diseno.');

        $imagen = $firmante->firma_imagen;

        $firmante->delete();

        if (filled($imagen)) {
            Storage::disk('local')->delete($imagen);
        }

        return back(303)->with('exito', 'Firmante retirado.');
    }

    /** Mueve un firmante una posicion, para decidir quien va a la izquierda. */
    public function moverFirmante(Request $peticion, DisenoHistorial $diseno, FirmanteHistorial $firmante): RedirectResponse
    {
        AvisoParaElUsuario::si($firmante->diseno_id !== $diseno->id, 404, 'Ese firmante no es de este diseno.');

        $hacia = $peticion->validate(['hacia' => ['required', Rule::in(['izquierda', 'derecha'])]])['hacia'];

        $vecino = $diseno->firmantes()
            ->when($hacia === 'izquierda',
                fn ($q) => $q->where('orden', '<', $firmante->orden)->reorder()->orderByDesc('orden'),
                fn ($q) => $q->where('orden', '>', $firmante->orden)->reorder()->orderBy('orden'),
            )
            ->first();

        // En el extremo no pasa nada: no es un error, es que ya esta ahi.
        if ($vecino === null) {
            return back(303);
        }

        [$firmante->orden, $vecino->orden] = [$vecino->orden, $firmante->orden];
        $firmante->save();
        $vecino->save();

        return back(303)->with('exito', 'Orden actualizado.');
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
        abort_unless($campo === 'sello_imagen', 404);

        return $this->archivo($diseno->sello_imagen);
    }

    /** La rubrica de un firmante, para la pantalla. */
    public function imagenFirmante(DisenoHistorial $diseno, FirmanteHistorial $firmante): StreamedResponse
    {
        abort_if($firmante->diseno_id !== $diseno->id, 404);

        return $this->archivo($firmante->firma_imagen);
    }

    private function archivo(?string $ruta): StreamedResponse
    {
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
            'muestra_creditos', 'leyenda',
            'tamano_papel', 'orientacion', 'descarga_alumno', 'marca_agua_alumno', 'marca_agua_texto',
            'marca_agua_ventanilla', 'marca_agua_opacidad',
            'margen_superior', 'margen_inferior', 'margen_izquierdo', 'margen_derecho',
            'fuente', 'tamano_fuente', 'interlineado', 'salto_por_bloque', 'usa_color_acento',
        ]), [
            'tiene_sello' => filled($diseno->sello_imagen),
            'firmantes' => $diseno->firmantes->map(fn (FirmanteHistorial $f) => [
                'id' => $f->id,
                'nombre' => $f->nombre,
                'cargo' => $f->cargo,
                'tiene_firma' => filled($f->firma_imagen),
            ])->values(),
        ]);
    }
}
