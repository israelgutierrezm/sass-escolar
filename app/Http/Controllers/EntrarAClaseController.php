<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Configuracion\Ajustes;
use App\Configuracion\CatalogoAjustes;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\ControlEscolar\Inscripcion;
use App\Models\Identidad\Usuario;
use App\Models\Lms\AccesoVideoconferencia;
use App\Models\Lms\Videoconferencia;
use App\Services\Videoconferencia\RegistroDeAcceso;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * La puerta de la clase en línea: anota quién entra y lo manda a Zoom o Meet.
 *
 * ── Por qué el botón dejó de ser un enlace directo ─────────────────────────
 * Antes el `url_join` viajaba al navegador y el alumno picaba un `<a>` que se
 * iba derecho al proveedor. Funcionaba, pero **nadie se enteraba de quién
 * entraba**: una clase en línea no podía pasar lista. Pasando por aquí, el clic
 * queda anotado y de paso el enlace del proveedor deja de aparecer en el HTML.
 *
 * ── Lo que esto mide, y lo que no ──────────────────────────────────────────
 * Que pidió el enlace con la clase abierta. Ni permanencia ni cámara. Ver la
 * migración `quien_entro_a_la_clase_en_linea`, donde está el porqué.
 *
 * ── Sin `can:`, y es la regla de siempre ───────────────────────────────────
 * Por esta ruta entran DOS oficios: el alumno de la materia y quien la imparte.
 * Un middleware con el permiso de uno rebotaría al otro, así que el alcance lo
 * resuelve el controlador con el par de siempre —quién eres + sobre qué—.
 */
class EntrarAClaseController extends Controller
{
    public function __construct(
        private readonly RegistroDeAcceso $registro,
        private readonly Ajustes $ajustes,
    ) {}

    public function __invoke(Request $request, Videoconferencia $clase): RedirectResponse
    {
        /** @var Usuario $usuario */
        $usuario = $request->user();

        $papel = $this->papelEn($usuario, $clase);

        /*
         * 404 y no 403: quien no es de esta materia no tiene por qué enterarse
         * de que la clase existe. Misma decisión que las rúbricas ajenas.
         */
        abort_if($papel === null, 404);

        $destino = $this->destinoPara($clase, $papel);

        /*
         * La clase cerrada devuelve a la pantalla en vez de al proveedor.
         *
         * `abiertaPara` es la MISMA regla que decide si se dibuja el botón, así
         * que llegar aquí con la clase cerrada significa un enlace guardado o
         * una pestaña vieja. Se dice por qué: un redirect mudo a la materia se
         * lee como que el botón está roto.
         */
        if ($destino === null) {
            return back(303)->with('error', $this->porQueNoSePuedeEntrar($clase));
        }

        $this->registro->registrar($clase, (int) $usuario->persona_id, $papel);

        return redirect()->away($destino);
    }

    /**
     * Con qué papel entra esta persona, o null si no le toca.
     *
     * El docente se comprueba PRIMERO: quien imparte la materia y además la
     * cursa —pasa en las escuelas chicas, con un egresado dando clase— entra
     * como quien la da, que es el papel con más responsabilidad de los dos.
     */
    private function papelEn(Usuario $usuario, Videoconferencia $clase): ?string
    {
        if ($usuario->persona_id === null) {
            return null;
        }

        $imparte = $clase->materia?->docentes()
            ->where('docentes.persona_id', $usuario->persona_id)
            ->exists();

        if ($imparte === true) {
            return AccesoVideoconferencia::DOCENTE;
        }

        /*
         * El alumno lo dice su INSCRIPCIÓN a esa materia, no un permiso.
         *
         * Va por `matricula_oferta` de esta persona: una persona con dos
         * programas académicos tiene dos matrículas, y basta con que cualquiera de ellas
         * esté inscrita en el grupo.
         */
        $cursa = Inscripcion::query()
            ->where('asignatura_grupo_id', $clase->asignatura_grupo_id)
            ->whereIn(
                'matricula_oferta_id',
                MatriculaOferta::query()
                    ->where('persona_id', $usuario->persona_id)
                    ->select('id')
            )
            ->exists();

        return $cursa ? AccesoVideoconferencia::ALUMNO : null;
    }

    /**
     * A dónde se le manda, o null si todavía no se puede entrar.
     *
     * El docente recibe el enlace de ANFITRIÓN, que es una credencial: entra
     * como dueño de la sala sin pedir contraseña. Por eso sale de aquí y nunca
     * del modelo, que es lo que se le serializa al alumno.
     */
    private function destinoPara(Videoconferencia $clase, string $papel): ?string
    {
        if ($clase->estaCancelada()) {
            return null;
        }

        if ($papel === AccesoVideoconferencia::DOCENTE) {
            /*
             * Al docente no se le aplica la antelación: es quien abre la sala y
             * suele entrar antes a preparar. Y en Meet no hay enlace de
             * anfitrión aparte —todos usan el mismo—, así que se cae al otro.
             */
            return $clase->url_anfitrion ?: ($clase->url_join ?: null);
        }

        $antelacion = $this->ajustes->entero(CatalogoAjustes::VIDEO_ANTELACION);

        return $clase->abiertaPara($antelacion) ? $clase->url_join : null;
    }

    /** Por qué no se puede entrar, con sus palabras. */
    private function porQueNoSePuedeEntrar(Videoconferencia $clase): string
    {
        if ($clase->estaCancelada()) {
            return 'Esa clase se canceló.';
        }

        if ($clase->yaTermino()) {
            return 'Esa clase ya terminó. Si se grabó, el video aparece aquí mismo cuando esté listo.';
        }

        $antelacion = $this->ajustes->entero(CatalogoAjustes::VIDEO_ANTELACION);

        return 'Todavía no abre. El botón se enciende '.$antelacion
            .' minutos antes de que empiece, a las '.$clase->inicio?->format('H:i').'.';
    }
}
