<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Academico\Institucion;
use App\Models\Identidad\MenuRol;
use App\Models\Identidad\PersonaRol;
use App\Models\Identidad\Rol;
use App\Models\Identidad\Usuario;
use App\Services\ResolutorTema;
use App\Services\Suplantador;
use Illuminate\Http\Request;
use Inertia\Middleware;

/**
 * Props que Inertia comparte con TODAS las páginas.
 *
 * Aquí se expone el contexto de sesión que el front necesita para pintar el
 * conmutador de rol y decidir qué menús mostrar: el usuario, su rol activo, los
 * roles entre los que puede conmutar (con su alcance por campus) y los permisos
 * EFECTIVOS del rol activo (propios + heredados de la jerarquía de roles).
 *
 * Los permisos que se envían son solo los del rol activo: el front no debe
 * conocer lo que podría hacer con otro rol. La autorización real se sigue
 * validando en el servidor.
 */
class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        // Explícitamente el guard `web` (el de las escuelas): este middleware es
        // del app de tenant. En el dominio central el usuario autenticado es un
        // SuperAdmin (guard `central`) y NO debe tratarse como Usuario de escuela.
        /** @var Usuario|null $usuario */
        $usuario = $request->user('web');

        return [
            ...parent::share($request),

            'auth' => [
                'usuario' => $usuario === null ? null : $this->datosUsuario($usuario),
            ],

            'escuela' => tenant() === null ? null : [
                'id' => tenant('id'),
                'nombre' => tenant('id'),
                // La institución (nombre, clave y logo público) para membretar la
                // barra lateral y el login. Lazy: solo se consulta cuando la
                // página la usa.
                'institucion' => fn () => $this->institucion(),
            ],

            // Disposición del menú lateral del ROL ACTIVO (orden y anidamiento
            // que definió la escuela). Null → la barra usa el orden por defecto
            // del catálogo. Lazy: solo se consulta si la página lo usa.
            'menu' => fn () => $this->menuDelRolActivo($usuario),

            // Colores ya resueltos en cascada; el front solo los inyecta como
            // CSS custom properties.
            'tema' => fn () => app(ResolutorTema::class)->paraUsuario($usuario),

            // Quien esta realmente detras de esta sesion. Null salvo que se
            // este suplantando; con esto se pinta la banda del layout.
            'suplantacion' => fn () => $this->suplantacion($request),
            'flash' => [
                'exito' => fn () => $request->session()->get('exito'),
                'error' => fn () => $request->session()->get('error'),
                // Una operación puede terminar bien y aun así tener algo que
                // advertir —"se aplicó a 40 materias, 3 no se tocaron porque ya
                // tienen calificaciones"—. Sin esta clave ese aviso se perdía
                // en silencio, que es justo lo contrario de lo que se quiere.
                'advertencia' => fn () => $request->session()->get('advertencia'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function datosUsuario(Usuario $usuario): array
    {
        $usuario->loadMissing(['persona', 'rolActivo']);

        return [
            'id' => $usuario->id,
            'usuario' => $usuario->usuario,
            'email' => $usuario->email,
            'nombre_completo' => $usuario->persona?->nombreCompleto() ?? $usuario->usuario,
            'rol_activo' => $usuario->rolActivo === null ? null : [
                'id' => $usuario->rolActivo->id,
                'clave' => $usuario->rolActivo->name,
                'nombre' => $usuario->rolActivo->nombre,
                // La faceta a la que pertenece: "Encargado de admisiones" es una
                // figura DENTRO de "Administrativo". Mostrarla hace evidente la
                // jerarquía en la interfaz.
                'faceta' => $this->faceta($usuario->rolActivo),
                // El ámbito (clave canónica: administrativo/docente/alumno/…) es
                // con lo que el menú decide qué SECCIONES mostrar. No basta
                // filtrar por permiso: `capturar-calificaciones` es de admin Y
                // de docente, y por sí solo colaba la sección «Docencia» a un
                // administrativo. Una faceta creada por la escuela cuenta como
                // administrativa, que es justo lo que hace `ambitoDePermisos`.
                'ambito' => $usuario->rolActivo->ambitoDePermisos(),
            ],
            'roles_disponibles' => $this->rolesDisponibles($usuario),
            'permisos' => $usuario->rolActivo?->permisosEfectivos()->pluck('name')->sort()->values()->all() ?? [],
        ];
    }

    /**
     * Roles activos de la persona, con el campus al que se acotan (si aplica).
     *
     * @return array<int, array<string, mixed>>
     */
    private function rolesDisponibles(Usuario $usuario): array
    {
        return PersonaRol::query()
            ->with(['rol', 'campus'])
            ->where('persona_id', $usuario->persona_id)
            ->where('activo', true)
            ->get()
            ->map(fn (PersonaRol $asignacion) => [
                'id' => $asignacion->rol->id,
                'clave' => $asignacion->rol->name,
                'nombre' => $asignacion->rol->nombre,
                'faceta' => $this->faceta($asignacion->rol),
                'campus_id' => $asignacion->campus_id,
                'campus_nombre' => $asignacion->campus?->nombre,
            ])
            ->all();
    }

    /**
     * Faceta de un rol: el ancestro más alto de su cadena. Si el rol no tiene
     * padre, él mismo ES la faceta (p. ej. "Docente" o "Alumno").
     */
    private function faceta(Rol $rol): string
    {
        $ancestros = $rol->ancestros();

        return $ancestros === [] ? $rol->nombre : end($ancestros)->nombre;
    }

    /**
     * Datos del usuario REAL cuando la sesion es una suplantacion.
     *
     * Viaja en cada respuesta porque la banda tiene que estar visible en todas
     * las pantallas: quien suplanta debe saber en todo momento que no es el.
     *
     * @return array<string, string|null>|null
     */
    private function suplantacion(Request $request): ?array
    {
        if (! $request->session()->has(Suplantador::CLAVE_SESION)) {
            return null;
        }

        $real = Usuario::with('persona')->find($request->session()->get(Suplantador::CLAVE_SESION));

        return $real === null ? null : [
            'usuario' => $real->usuario,
            'nombre' => $real->persona?->nombreCompleto(),
        ];
    }

    /**
     * La disposición del menú del rol activo (árbol de claves) o null si ese rol
     * no tiene una configurada (usa el orden por defecto del catálogo).
     *
     * @return array<int, mixed>|null
     */
    private function menuDelRolActivo(?Usuario $usuario): ?array
    {
        $rolId = $usuario?->rolActivo?->id;

        if ($rolId === null) {
            return null;
        }

        $menu = MenuRol::query()->where('rol_id', $rolId)->first(['estructura', 'ocultos']);

        if ($menu === null) {
            return null;
        }

        return ['arbol' => $menu->estructura, 'ocultos' => $menu->ocultos ?? []];
    }

    /**
     * La institución de la escuela (solo puede haber una) para membretar la
     * barra lateral y el login. El logo va como URL pública con un parámetro de
     * versión, para que al cambiarlo no se quede el del navegador en caché.
     *
     * @return array{nombre: string, clave: string, siglas: ?string, logo: ?string}|null
     */
    private function institucion(): ?array
    {
        $institucion = Institucion::query()->first();

        if ($institucion === null) {
            return null;
        }

        return [
            // Para membretar se usa el nombre A MOSTRAR; si no hay, el oficial.
            'nombre' => $institucion->nombre_mostrar ?: $institucion->nombre,
            'clave' => $institucion->clave,
            'siglas' => $institucion->siglas,
            'logo' => $institucion->logo_url === null
                ? null
                : route('tenant.institucion.logo').'?v='.$institucion->updated_at?->timestamp,
        ];
    }
}
