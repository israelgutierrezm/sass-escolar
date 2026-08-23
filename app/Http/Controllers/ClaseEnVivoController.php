<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Configuracion\Ajustes;
use App\Configuracion\CatalogoAjustes;
use App\Http\Controllers\Concerns\AutorizaMateriaPropia;
use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\Identidad\Usuario;
use App\Models\Lms\AccesoVideoconferencia;
use App\Models\Lms\Videoconferencia;
use App\Services\Videoconferencia\AsignadorDeCuenta;
use App\Services\Videoconferencia\ProgramadorDeClases;
use App\Support\ProveedoresVideoCatalogo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Las clases en línea de una materia, desde el lado del docente.
 *
 * Mismo criterio de alcance que el resto de `/docencia`: el permiso deja entrar
 * al portal y la ASIGNACIÓN dice en qué materia. Un docente con el permiso y sin
 * la materia recibe 403 — programar una clase en el grupo de otro es entrar a su
 * salón.
 *
 * ── El enlace de anfitrión sale SOLO aquí ──────────────────────────────────
 * Y sólo de este controlador, que ya comprobó la asignación. El del alumno lo
 * arma el modelo con `paraElAlumno`, que no lo incluye. Son dos caminos
 * distintos a propósito: mientras el dato del alumno se construya en otro sitio,
 * no hay forma de que un descuido aquí se lo mande.
 */
class ClaseEnVivoController extends Controller
{
    use AutorizaMateriaPropia;

    public function __construct(
        private readonly ProgramadorDeClases $programador,
        private readonly AsignadorDeCuenta $asignador,
        private readonly Ajustes $ajustes,
    ) {}

    public function programar(Request $request, AsignaturaGrupo $asignaturaGrupo): RedirectResponse
    {
        $this->autorizarMateriaPropia($request, $asignaturaGrupo);

        $datos = $request->validate([
            'proveedor' => ['required', Rule::in(ProveedoresVideoCatalogo::claves())],
            'titulo' => ['required', 'string', 'max:180'],
            'inicio' => ['required', 'date'],
            // Un tope alto pero real: una clase de más de seis horas es un dedo
            // de más, y la licencia se quedaría ocupada todo el día.
            'minutos' => ['required', 'integer', 'min:5', 'max:360'],
        ], [], [
            'titulo' => 'título',
            'inicio' => 'fecha y hora',
            'minutos' => 'duración',
        ]);

        /** @var Usuario $usuario */
        $usuario = $request->user();

        $sesion = $this->programador->programar(
            $asignaturaGrupo,
            $datos['proveedor'],
            $datos['titulo'],
            now()->parse($datos['inicio']),
            (int) $datos['minutos'],
            $usuario->id,
        );

        return back(303)->with(
            'exito',
            "Clase programada para el {$sesion->inicio->format('d/m/Y H:i')}. Tus alumnos verán el botón "
            .$this->ajustes->entero(CatalogoAjustes::VIDEO_ANTELACION).' minutos antes.',
        );
    }

    public function cancelar(Request $request, AsignaturaGrupo $asignaturaGrupo, Videoconferencia $sesion): RedirectResponse
    {
        $this->autorizarMateriaPropia($request, $asignaturaGrupo);
        $this->exigirDeLaMateria($sesion, $asignaturaGrupo);

        $this->programador->cancelar($sesion);

        return back(303)->with('exito', 'Clase cancelada. La sala también se retiró del proveedor.');
    }

    /**
     * Lo que la pantalla del docente necesita.
     *
     * Vive aquí y lo llama `DocenciaController` para no partir en dos la regla
     * de qué se le enseña a quién.
     *
     * @return array<string, mixed>
     */
    public function datosPara(AsignaturaGrupo $asignaturaGrupo): array
    {
        $sesiones = Videoconferencia::query()
            ->where('asignatura_grupo_id', $asignaturaGrupo->id)
            ->with(['cuenta:id,etiqueta', 'grabaciones'])
            ->orderByDesc('inicio')
            ->limit(30)
            ->get();

        $antelacion = $this->ajustes->entero(CatalogoAjustes::VIDEO_ANTELACION);

        /*
         * Los accesos de las treinta sesiones, en UNA consulta y agrupados.
         *
         * Preguntándolos por sesión serían treinta consultas para dibujar una
         * pantalla, y con `with()` en la relación vendrían igual de bien pero
         * sin el orden por llegada, que es el que hace legible la lista.
         */
        $accesos = AccesoVideoconferencia::query()
            ->whereIn('videoconferencia_id', $sesiones->pluck('id'))
            ->with('persona:id,nombre,primer_apellido,segundo_apellido')
            ->orderBy('primer_acceso')
            ->get()
            ->groupBy('videoconferencia_id');

        return [
            // Sólo los que de verdad pueden dar clase: encendidos, con
            // credenciales y con al menos una cuenta. Ofrecer uno que no cumple
            // las tres cosas es mandar al docente a un error.
            'proveedores' => collect($this->asignador->disponibles())
                ->map(fn (string $clave) => [
                    'clave' => $clave,
                    'nombre' => ProveedoresVideoCatalogo::uno($clave)['nombre'],
                ])->values(),
            'antelacion' => $antelacion,
            'sesiones' => $sesiones->map(fn (Videoconferencia $s) => [
                'id' => $s->id,
                'titulo' => $s->titulo,
                'proveedor' => $s->proveedor,
                'proveedor_nombre' => ProveedoresVideoCatalogo::uno($s->proveedor)['nombre'] ?? $s->proveedor,
                'inicio' => $s->inicio?->format('Y-m-d H:i'),
                'fin' => $s->fin?->format('Y-m-d H:i'),
                'estado' => $s->estado,
                'cuenta' => $s->cuenta?->etiqueta,
                'termino' => $s->yaTermino(),
                /*
                 * También el docente entra por la puerta propia.
                 *
                 * Podría llevarse el `url_anfitrion` directo —ya pasó por
                 * `autorizarMateriaPropia`—, pero entonces su propia llegada no
                 * quedaría anotada, y «¿el docente entró a su clase?» es
                 * justamente una de las preguntas que esta tabla existe para
                 * contestar. El controlador de la puerta le reconoce el papel y
                 * le da el enlace de anfitrión.
                 *
                 * Efecto secundario: el `start_url` de Zoom —que es una
                 * credencial, entra como dueño de la sala— deja de viajar al
                 * navegador incluso aquí.
                 */
                'url_iniciar' => $s->estaCancelada() ? null : "/clases/{$s->id}/entrar",
                /*
                 * El del invitado SÍ viaja: el docente lo copia y lo pega en su
                 * grupo de mensajería, que es como se avisa de verdad. No es una
                 * credencial —es el mismo enlace que ve cualquier alumno—, y
                 * quien lo use por ahí simplemente no quedará anotado.
                 */
                'url_invitado' => $s->url_join,
                /*
                 * Quiénes entraron. Va con la sesión y no en una pantalla
                 * aparte: el docente lo mira mientras pasa lista, y una segunda
                 * pantalla obligaría a cruzar dos listas de memoria.
                 */
                'accesos' => $accesos->get($s->id, collect())
                    ->map(fn (AccesoVideoconferencia $a) => [
                        'persona' => $a->persona?->nombreCompleto(),
                        'papel' => $a->papel,
                        'entro' => $a->primer_acceso?->format('H:i'),
                        'veces' => $a->veces,
                        'retraso' => $a->minutosDeRetraso(),
                    ])->values(),
                /*
                 * Lo que dejó grabado. Se manda incluso lo que falló: si sólo
                 * viajara lo archivado, una grabación que no se pudo traer sería
                 * indistinguible de una clase que nadie grabó, y son dos
                 * problemas distintos.
                 */
                'grabaciones' => $s->grabaciones->map(fn ($g) => [
                    'id' => $g->id,
                    'tipo' => $g->tipo,
                    'nombre' => $g->nombre,
                    'estado' => $g->estado,
                    'peso' => $g->pesoLegible(),
                    'destino' => $g->destino,
                    'error' => $g->error,
                    'visible_alumnos' => (bool) $g->visible_alumnos,
                ])->values(),
            ])->values(),
        ];
    }

    private function exigirDeLaMateria(Videoconferencia $sesion, AsignaturaGrupo $asignaturaGrupo): void
    {
        abort_unless((int) $sesion->asignatura_grupo_id === $asignaturaGrupo->id, 404);
    }
}
