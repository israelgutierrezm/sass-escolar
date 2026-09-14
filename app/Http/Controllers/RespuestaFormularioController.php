<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\AvisoParaElUsuario;
use App\Http\Controllers\Concerns\AcotaPorCampus;
use App\Http\Controllers\Concerns\ResuelveMiSolicitud;
use App\Models\Admisiones\Aspirante;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Admisiones\RespuestaCampo;
use App\Models\ControlEscolar\Docente;
use App\Models\Formularios\Formulario;
use App\Models\Identidad\Persona;
use App\Models\Identidad\Usuario;
use App\Services\Formularios\CapturaDeFormulario;
use App\Services\ResolutorFormularios;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Contestar un formulario.
 *
 * `ResolutorFormularios` ya decía QUÉ formularios le tocan a alguien y cuánto
 * llevaba contestado; faltaba el sitio donde contestarlos. Sin esto el avance
 * de todos era siempre cero y no había forma de moverlo.
 *
 * ── Dos puertas, una pantalla ──────────────────────────────────────────────
 * El mismo formulario lo llena el interesado desde su portal o quien lo atiende
 * desde la ficha. Es la MISMA captura con distinto quién: separarlas habría
 * llevado a que una acepte un tipo de campo que la otra no, y a que las reglas
 * de validación se separaran sin que nadie lo decidiera.
 *
 * Lo único que cambia entre las dos es de dónde sale el titular —de la URL en
 * la administrativa, de la sesión en la del portal— y a dónde se vuelve.
 *
 * ── Las respuestas se guardan por campo ────────────────────────────────────
 * Una fila por (titular, campo), como manda `respuestas_campo`. Se conserva la
 * versión del formulario con la que se respondió: si mañana se publica una
 * versión nueva, lo ya contestado sigue diciendo con qué preguntas fue.
 *
 * ── Y el titular puede ser una persona ─────────────────────────────────────
 * Un docente o un tutor no son ni aspirante ni matrícula: sus respuestas
 * cuelgan de la persona, con las otras dos columnas en null. La regla vive en
 * `RespuestaCampo::scopeParaTitular` y no repartida aquí, porque tiene una
 * sutileza fácil de perder: para una persona no basta `persona_id`, hay que
 * exigir además que las dos columnas de capacidad estén vacías. Sin eso, el
 * expediente del docente arrastraría lo que esa misma persona contestó siendo
 * aspirante.
 */
class RespuestaFormularioController extends Controller
{
    use AcotaPorCampus;
    use ResuelveMiSolicitud;

    public function __construct(
        private readonly ResolutorFormularios $resolutor,
        private readonly CapturaDeFormulario $captura,
    ) {}

    // ── Desde la ficha: lo llena quien atiende ─────────────────────────────

    public function mostrar(Request $request, Aspirante $aspirante, Formulario $formulario): Response
    {
        $this->autorizarCampus($request, $aspirante->campus_id);

        return $this->pantalla($aspirante, $formulario, [
            'titulo' => $aspirante->persona?->nombreCompleto(),
            'volver' => "/aspirantes/{$aspirante->id}",
        ], "/aspirantes/{$aspirante->id}/formularios/{$formulario->id}", "/aspirantes/{$aspirante->id}/respuestas");
    }

    public function guardar(Request $request, Aspirante $aspirante, Formulario $formulario): RedirectResponse
    {
        $this->autorizarCampus($request, $aspirante->campus_id);
        $this->captura->guardar($request, $aspirante, $formulario);

        return redirect("/aspirantes/{$aspirante->id}")->with('exito', "«{$formulario->titulo}» quedó guardado.");
    }

    // ── Desde el portal: lo llena el interesado ────────────────────────────

    /*
     * No reciben id de aspirante: sale de la persona en sesión. Es la única
     * diferencia real con las de arriba —quién es el titular y a dónde se
     * vuelve—, y por eso el resto se comparte: separar las dos capturas habría
     * llevado a que una acepte un tipo de campo que la otra no, y a que las
     * reglas se separaran sin que nadie lo decidiera.
     */
    public function mostrarMio(Request $request, Formulario $formulario): Response
    {
        $aspirante = $this->miSolicitud($request);

        return $this->pantalla($aspirante, $formulario, [
            'titulo' => 'Mi solicitud',
            'volver' => '/mi-solicitud',
        ], "/mi-solicitud/formularios/{$formulario->id}", '/mi-solicitud/respuestas');
    }

    public function guardarMio(Request $request, Formulario $formulario): RedirectResponse
    {
        $aspirante = $this->miSolicitud($request);

        $this->captura->guardar($request, $aspirante, $formulario);

        return redirect('/mi-solicitud')->with('exito', "«{$formulario->titulo}» quedó guardado.");
    }

    // ── Desde la ficha del alumno ──────────────────────────────────────────

    /*
     * El mismo expediente después de la conversión.
     *
     * Un alumno sigue teniendo formularios que llenar —los que su programa académico le
     * pide, los que se agregaron después de que entró—, y son los mismos
     * bloques resueltos con el mismo criterio. Que aspirante y alumno usen esta
     * misma captura es lo que hace que su expediente no se parta al cruzar.
     */
    public function mostrarDeAlumno(Request $request, MatriculaOferta $alumno, Formulario $formulario): Response
    {
        $this->autorizarCampus($request, $alumno->oferta?->campus_id);

        return $this->pantalla($alumno, $formulario, [
            'titulo' => $alumno->persona?->nombreCompleto(),
            'volver' => "/escolar/alumnos/{$alumno->id}",
        ], "/escolar/alumnos/{$alumno->id}/formularios/{$formulario->id}", "/escolar/alumnos/{$alumno->id}/respuestas");
    }

    public function guardarDeAlumno(Request $request, MatriculaOferta $alumno, Formulario $formulario): RedirectResponse
    {
        $this->autorizarCampus($request, $alumno->oferta?->campus_id);
        $this->captura->guardar($request, $alumno, $formulario);

        return redirect("/escolar/alumnos/{$alumno->id}")
            ->with('exito', "«{$formulario->titulo}» quedó guardado.");
    }

    // ── Quien no es ni aspirante ni alumno ─────────────────────────────────

    /*
     * Docentes y tutores.
     *
     * A un docente también se le piden bloques de datos —la constancia de
     * situación fiscal, a quién avisar en una emergencia—, y hasta ahora los
     * formularios sólo sabían de quien estudia. El titular aquí es la PERSONA:
     * no hay programa académico ni matrícula de la que colgarlos, y tampoco hacen falta.
     *
     * Un docente lo es una sola vez, así que no hay ambigüedad que resolver
     * como la que hay con alguien que tiene dos matrículas.
     */
    public function mostrarDeDocente(Request $request, Docente $docente, Formulario $formulario): Response
    {
        $persona = $this->personaDeDocente($docente);

        return $this->pantalla($persona, $formulario, [
            'titulo' => $persona->nombreCompleto(),
            'volver' => "/escolar/docentes/{$docente->persona_id}",
        ], "/escolar/docentes/{$docente->persona_id}/formularios/{$formulario->id}", "/escolar/docentes/{$docente->persona_id}/respuestas");
    }

    public function guardarDeDocente(Request $request, Docente $docente, Formulario $formulario): RedirectResponse
    {
        $this->captura->guardar($request, $this->personaDeDocente($docente), $formulario);

        return redirect("/escolar/docentes/{$docente->persona_id}")
            ->with('exito', "«{$formulario->titulo}» quedó guardado.");
    }

    /*
     * El mismo formulario, llenado por el propio docente desde su expediente.
     *
     * Sin id en la URL: la persona sale de la sesión, como todo lo demás de
     * «Mi expediente». Es la misma pareja de arriba con otro quién, igual que
     * el portal del aspirante lo es de la ficha.
     */
    public function mostrarMiFormulario(Request $request, Formulario $formulario): Response
    {
        $persona = $this->miPersona($request);

        return $this->pantalla($persona, $formulario, [
            'titulo' => 'Mi expediente',
            'volver' => '/docencia/expediente',
        ], "/docencia/expediente/formularios/{$formulario->id}", '/docencia/expediente/respuestas');
    }

    public function guardarMiFormulario(Request $request, Formulario $formulario): RedirectResponse
    {
        $this->captura->guardar($request, $this->miPersona($request), $formulario);

        return redirect('/docencia/expediente')->with('exito', "«{$formulario->titulo}» quedó guardado.");
    }

    public function mostrarDeTutor(Request $request, Persona $tutor, Formulario $formulario): Response
    {
        return $this->pantalla($tutor, $formulario, [
            'titulo' => $tutor->nombreCompleto(),
            'volver' => "/padres-tutores/{$tutor->id}",
        ], "/padres-tutores/{$tutor->id}/formularios/{$formulario->id}", "/padres-tutores/{$tutor->id}/respuestas");
    }

    public function guardarDeTutor(Request $request, Persona $tutor, Formulario $formulario): RedirectResponse
    {
        $this->captura->guardar($request, $tutor, $formulario);

        return redirect("/padres-tutores/{$tutor->id}")
            ->with('exito', "«{$formulario->titulo}» quedó guardado.");
    }

    // ── «Mis datos»: el autoservicio de cualquier persona ──────────────────

    /*
     * Una puerta sin oficio.
     *
     * El aspirante llena los suyos en `/mi-solicitud`, el alumno en su portal y
     * el docente dentro de «Mi expediente»: cada uno donde ya vive. Un padre de
     * familia no tenía dónde —su portal es sobre sus HIJOS—, y un tutor
     * educativo tampoco.
     *
     * En vez de colgar un panel de un portal y luego del otro, y otro más cada
     * vez que aparezca un rol nuevo, esta página no habla de ningún oficio: son
     * los bloques que le tocan a la persona de la sesión, sea quien sea.
     *
     * Sin `can:` a propósito. No hay id en la URL: siempre resuelve a quien está
     * dentro, así que lo único que expone es lo suyo. Exigir un permiso aquí
     * sería decidir qué roles pueden contestar lo que la escuela ya les asignó
     * —y el primero en quedarse fuera sería el tutor educativo, que es
     * justamente uno de los que vinimos a resolver—.
     */
    public function mios(Request $request): Response
    {
        $persona = $this->miPersona($request);

        return Inertia::render('Formularios/Mios', [
            'persona' => ['nombre' => $persona->nombreCompleto()],
            'formularios' => $this->resolutor->para($persona),
        ]);
    }

    public function mostrarPersonal(Request $request, Formulario $formulario): Response
    {
        $persona = $this->miPersona($request);

        return $this->pantalla($persona, $formulario, [
            'titulo' => 'Mis datos',
            'volver' => '/mis-datos',
        ], "/mis-datos/{$formulario->id}", '/mis-datos/respuestas');
    }

    public function guardarPersonal(Request $request, Formulario $formulario): RedirectResponse
    {
        $this->captura->guardar($request, $this->miPersona($request), $formulario);

        return redirect('/mis-datos')->with('exito', "«{$formulario->titulo}» quedó guardado.");
    }

    // ── Descargar lo que se subió ──────────────────────────────────────────

    /*
     * Varias puertas y una comprobación.
     *
     * El archivo se guarda en el disco privado, así que no hay URL pública: la
     * única forma de bajarlo es por aquí. Cada método resuelve el titular como
     * su pantalla —de la URL o de la sesión— y `entregar()` comprueba que la
     * respuesta sea de ESE titular antes de servir nada. Sin esa comprobación,
     * cambiar un número en la URL bajaría el acta de cualquiera.
     */
    public function descargar(Request $request, Aspirante $aspirante, RespuestaCampo $respuesta): StreamedResponse
    {
        $this->autorizarCampus($request, $aspirante->campus_id);

        return $this->entregar($respuesta, $aspirante, $aspirante->persona);
    }

    public function descargarMio(Request $request, RespuestaCampo $respuesta): StreamedResponse
    {
        $aspirante = $this->miSolicitud($request);

        return $this->entregar($respuesta, $aspirante, $aspirante->persona);
    }

    public function descargarDeAlumno(Request $request, MatriculaOferta $alumno, RespuestaCampo $respuesta): StreamedResponse
    {
        $this->autorizarCampus($request, $alumno->oferta?->campus_id);

        return $this->entregar($respuesta, $alumno, $alumno->persona);
    }

    public function descargarDeDocente(Request $request, Docente $docente, RespuestaCampo $respuesta): StreamedResponse
    {
        $persona = $this->personaDeDocente($docente);

        return $this->entregar($respuesta, $persona, $persona);
    }

    public function descargarMiArchivo(Request $request, RespuestaCampo $respuesta): StreamedResponse
    {
        $persona = $this->miPersona($request);

        return $this->entregar($respuesta, $persona, $persona);
    }

    public function descargarDeTutor(Request $request, Persona $tutor, RespuestaCampo $respuesta): StreamedResponse
    {
        return $this->entregar($respuesta, $tutor, $tutor);
    }

    public function descargarPersonal(Request $request, RespuestaCampo $respuesta): StreamedResponse
    {
        $persona = $this->miPersona($request);

        return $this->entregar($respuesta, $persona, $persona);
    }

    /**
     * Sirve el archivo, con nombre legible.
     *
     * Se guarda con el nombre que le tocó al subirlo —un hash—, y bajarlo así
     * deja al revisor con veinte archivos indistinguibles en su carpeta de
     * descargas. Se renombra con la pregunta y de quién es, que es como se
     * habla de ellos.
     */
    private function entregar(
        RespuestaCampo $respuesta,
        Aspirante|MatriculaOferta|Persona $titular,
        ?Persona $persona,
    ): StreamedResponse {
        // Con el mismo criterio que las lee la pantalla: si esa respuesta no
        // sale al consultar las del titular, no es suya.
        abort_unless(
            RespuestaCampo::query()->paraTitular($titular)->whereKey($respuesta->id)->exists(),
            404,
        );
        abort_if($respuesta->documento_ruta === null, 404, 'Esa respuesta no tiene ningún archivo.');
        abort_unless(Storage::disk('local')->exists($respuesta->documento_ruta), 404);

        $extension = pathinfo($respuesta->documento_ruta, PATHINFO_EXTENSION);

        return Storage::disk('local')->download(
            $respuesta->documento_ruta,
            sprintf(
                '%s - %s.%s',
                $respuesta->campo?->pregunta ?? 'Documento',
                $persona?->nombreCompleto() ?? 'sin nombre',
                $extension,
            ),
        );
    }

    // ── Lo compartido ──────────────────────────────────────────────────────

    /**
     * @param  array{titulo: ?string, volver: string}  $contexto
     */
    private function pantalla(Aspirante|MatriculaOferta|Persona $titular, Formulario $formulario, array $contexto, string $accion, string $baseDescarga): Response
    {
        return Inertia::render('Formularios/Captura', [
            'contexto' => $contexto,
            ...$this->captura->ficha($titular, $formulario),
            'accion' => $accion,
            // De dónde bajar un archivo ya subido: `{base}/{id de la respuesta}`.
            'baseDescarga' => $baseDescarga,
        ]);
    }

    /**
     * La persona detrás del docente. Un docente sin persona no puede existir
     * —`docentes.persona_id` es su llave—, pero el modelo la deja nulable.
     */
    private function personaDeDocente(Docente $docente): Persona
    {
        return $docente->persona ?? abort(404, 'Ese docente no tiene persona.');
    }

    /**
     * La persona de la sesión, para el autoservicio. Sin id en la URL: no hay
     * número que cambiar para contestarle el formulario a otro.
     */
    private function miPersona(Request $request): Persona
    {
        /** @var Usuario $usuario */
        $usuario = $request->user();

        return Persona::find($usuario->persona_id)
            ?? AvisoParaElUsuario::lanzar(403, 'Tu cuenta todavía no está ligada a tus datos. Pídele a la escuela que la complete.');
    }
}
