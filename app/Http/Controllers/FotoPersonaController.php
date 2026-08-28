<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Admisiones\Aspirante;
use App\Models\ControlEscolar\Inscripcion;
use App\Models\ControlEscolar\Tutoria;
use App\Models\Identidad\Persona;
use App\Models\Identidad\Usuario;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Foto de perfil de una persona.
 *
 * Un solo punto para toda la escuela: la usan la ficha del alumno, la del
 * docente y el expediente que cada quien mantiene de sí mismo. Tener un
 * endpoint por rol habría multiplicado el mismo control de acceso.
 *
 * Quién puede cambiarla: uno mismo siempre, y quien tenga permiso para editar a
 * esa clase de persona.
 *
 * ── Y quién puede VERLA ───────────────────────────────────────────────────
 * Esto ya estaba escrito aquí —«cualquiera que pueda ver su ficha»— y **no lo
 * aplicaba ninguna línea de código**: `mostrar()` recibía la petición y no la
 * miraba. Con la ruta detrás de `auth` y sin un solo `can:`, cualquier persona
 * con cuenta —un alumno, un aspirante, un padre— podía pedir
 * `/personas/1/foto`, `/personas/2/foto`… y bajarse la cara de TODA la escuela,
 * menores incluidos. Los ids son consecutivos, así que enumerarlos es trivial.
 *
 * Comprobado antes de arreglarlo: la cuenta `alumno.demo.1`, sin ninguno de los
 * permisos de personal, recibía un 200 con la foto del director.
 *
 * Guardar el archivo en el disco privado no sirve de nada si la ruta que lo
 * sirve no pregunta quién llama: el disco privado sólo mueve la pregunta aquí.
 *
 * ── Por qué no se resuelve con un `can:` en la ruta ───────────────────────
 * Porque por este endpoint entran SIETE oficios: control escolar mirando a un
 * alumno, el catálogo de docentes, admisiones y promoción mirando a un
 * prospecto, la pantalla de usuarios, el padrón de padres y tutores, el padre
 * mirando a su hijo, el tutor educativo a su tutorado, la inscripción por grupo,
 * el ALUMNO mirando a sus docentes, y cada quien su propia cara. Un middleware
 * con el permiso de uno rebotaría a los demás — que es la lección que este
 * proyecto ya tenía anotada con la descarga de adjuntos de entrega.
 *
 * Los consumidores se enumeraron uno por uno (`urlFoto()`) antes de escribir la
 * regla: tres de ellos —el padrón de tutores, la inscripción masiva y el portal
 * del alumno— no habrían pasado con la primera versión, y el síntoma habría
 * sido una pantalla llena de avatares rotos.
 *
 * Y en los tres casos con vínculo se exigen las DOS capas de siempre: el
 * permiso dice QUÉ puede hacer el rol, y el vínculo dice SOBRE QUIÉN.
 */
class FotoPersonaController extends Controller
{
    private const CARPETA = 'fotos';

    public function actualizar(Request $request, Persona $persona): RedirectResponse
    {
        $this->autorizar($request, $persona);

        $request->validate([
            'foto' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ], [
            'foto.image' => 'El archivo debe ser una imagen.',
            'foto.max' => 'La foto no puede pasar de 2 MB.',
        ]);

        $anterior = $persona->foto_url;

        $ruta = $request->file('foto')->store(self::CARPETA, 'local');

        $persona->update(['foto_url' => $ruta]);

        // La anterior se borra: se reemplazó, y conservarla solo acumula datos
        // personales que ya nadie va a ver.
        if ($anterior !== null && $anterior !== $ruta) {
            Storage::disk('local')->delete($anterior);
        }

        return back()->with('exito', 'Foto actualizada.');
    }

    public function mostrar(Request $request, Persona $persona): StreamedResponse
    {
        $this->autorizarVer($request, $persona);

        abort_if($persona->foto_url === null, 404);
        abort_unless(Storage::disk('local')->exists($persona->foto_url), 404);

        // `response()->file` sirve la imagen en línea para que el <img> la
        // pinte, en vez de forzar una descarga.
        return Storage::disk('local')->response($persona->foto_url);
    }

    public function eliminar(Request $request, Persona $persona): RedirectResponse
    {
        $this->autorizar($request, $persona);

        if ($persona->foto_url !== null) {
            Storage::disk('local')->delete($persona->foto_url);
            $persona->update(['foto_url' => null]);
        }

        return back()->with('exito', 'Foto eliminada.');
    }

    /**
     * Quién puede VER la cara de alguien.
     *
     * Se comprueba contra lo que la persona ES —alumna, docente, prospecto— y
     * contra el VÍNCULO cuando lo hay, nunca contra un permiso genérico: quien
     * puede mirar el listado de docentes no tiene por qué mirar la cara de cada
     * aspirante.
     *
     * El orden va de lo barato a lo caro: primero la sesión y los permisos, que
     * no tocan la base, y sólo después las consultas.
     */
    private function autorizarVer(Request $request, Persona $persona): void
    {
        /** @var Usuario $usuario */
        $usuario = $request->user();

        // Uno mismo, siempre. Es su cara.
        if ($usuario->persona_id === $persona->id) {
            return;
        }

        // Quien administra el padrón entero.
        if ($usuario->can('editar-personas')) {
            return;
        }

        $puede = ($usuario->can('ver-alumnos') || $usuario->can('editar-alumnos')
            || $usuario->can('inscribir-alumnos'))
            && $persona->matriculas()->exists();

        $puede = $puede || (($usuario->can('ver-docentes') || $usuario->can('gestionar-docentes'))
            && $persona->docente()->exists());

        $puede = $puede || (($usuario->can('ver-aspirantes') || $usuario->can('editar-aspirantes')
            || $usuario->can('gestionar-promocion'))
            && Aspirante::where('persona_id', $persona->id)->exists());

        $puede = $puede || ($usuario->can('gestionar-usuarios') && $persona->usuario()->exists());

        // El padrón de padres y tutores: se es tutor por tener a alguien a cargo.
        $puede = $puede || (($usuario->can('ver-tutores') || $usuario->can('editar-tutores'))
            && $persona->hijos()->exists());

        /*
         * Y al revés que todo lo anterior: el ALUMNO ve la cara de SUS docentes.
         *
         * «Mi materia» los enumera con foto, así que sin esto el portal del
         * alumno se llenaba de avatares rotos. El vínculo es la inscripción: ve
         * a quien le da clase, no al claustro entero.
         */
        $puede = $puede || ($usuario->can('ver-mis-cursos')
            && Inscripcion::query()
                ->whereHas('matriculaOferta', fn ($q) => $q->where('persona_id', $usuario->persona_id))
                ->whereHas('asignaturaGrupo.docentes', fn ($q) => $q->where('docentes.persona_id', $persona->id))
                ->exists());

        // El tutor educativo, sobre SUS tutorados y no sobre los de otro.
        $puede = $puede || ($usuario->can('ver-mis-tutorados')
            && Tutoria::de((int) $usuario->persona_id)
                ->where('alumno_persona_id', $persona->id)->exists());

        // El padre o tutor familiar, sobre sus hijos.
        $puede = $puede || ($usuario->can('ver-mis-hijos')
            && $persona->tutoresFamiliares()->where('personas.id', $usuario->persona_id)->exists());

        // El asesor, sobre los prospectos que le tocaron.
        $puede = $puede || ($usuario->can('ver-mis-prospectos')
            && Aspirante::where('persona_id', $persona->id)
                ->whereHas('asesores', fn ($q) => $q->where('asesores.persona_id', $usuario->persona_id))
                ->exists());

        if (! $puede) {
            /*
             * 404 y no 403: un 403 confirma que esa persona existe y tiene
             * foto, así que enumerando ids se levanta el padrón de la escuela
             * sin ver una sola cara. Misma decisión que con la rúbrica ajena.
             */
            abort(404);
        }
    }

    /**
     * Uno mismo, o quien administre a esa clase de persona. Se comprueba contra
     * lo que la persona ES —alumno, docente— y no contra un permiso genérico de
     * "editar personas", que no distingue a quién.
     */
    private function autorizar(Request $request, Persona $persona): void
    {
        /** @var Usuario $usuario */
        $usuario = $request->user();

        if ($usuario->persona_id === $persona->id) {
            return;
        }

        $esAlumno = $persona->matriculas()->exists();
        $esDocente = $persona->docente()->exists();

        $puede = ($esAlumno && $usuario->can('editar-alumnos'))
            || ($esDocente && $usuario->can('gestionar-docentes'))
            || $usuario->can('editar-personas');

        if (! $puede) {
            throw new AccessDeniedHttpException('No puedes cambiar la foto de esa persona.');
        }
    }
}
