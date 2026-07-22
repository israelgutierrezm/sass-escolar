<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Identidad\Persona;
use App\Models\Identidad\PersonaRol;
use App\Models\Identidad\Rol;
use App\Support\CatalogoPermisos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\PermissionRegistrar;

/**
 * Administración de roles y permisos.
 *
 * Hasta ahora, cambiar quién podía qué obligaba a tocar `PermisoSeeder` y
 * re-sembrar. Esta pantalla lo abre a la escuela, que es lo que pide un
 * organigrama real: cada una nombra sus puestos distinto y ninguna cabe en los
 * que trae el sistema de ejemplo.
 *
 * Lo que NO se abre, y es deliberado: **los permisos no se crean desde aquí**.
 * Un permiso es una llave que el código consulta; uno inventado en la interfaz
 * no lo comprobaría ninguna ruta y daría la sensación de haber restringido algo
 * sin restringir nada. El catálogo vive en `App\Support\CatalogoPermisos`.
 */
class RolController extends Controller
{
    public function index(Request $request): Response
    {
        $roles = Rol::query()
            ->with('padre:id,name,nombre')
            ->withCount(['asignaciones', 'hijos'])
            ->orderByRaw('rol_padre_id is not null') // facetas primero
            ->orderBy('nombre')
            ->get();

        return Inertia::render('Plataforma/Roles/Index', [
            'facetas' => $roles->whereNull('rol_padre_id')->map(
                fn (Rol $f) => $this->resumen($f, $roles)
            )->values(),
            'catalogo' => CatalogoPermisos::paraPantalla(),
            // El rol con el que se está operando. La pantalla lo marca para que
            // nadie se quite a sí mismo la llave sin darse cuenta.
            'rolActivo' => $request->user()?->rol_activo_id,
        ]);
    }

    public function show(Request $request, Rol $rol): Response
    {
        $rol->load('padre:id,name,nombre', 'permissions:id,name');

        $heredados = collect($rol->ancestros())
            ->flatMap(fn (Rol $a) => $a->permissions->pluck('name'))
            ->unique()
            ->values();

        return Inertia::render('Plataforma/Roles/Detalle', [
            'rol' => [
                'id' => $rol->id,
                'clave' => $rol->name,
                'nombre' => $rol->nombre,
                'tiempo_sesion' => $rol->tiempo_sesion,
                'rol_padre_id' => $rol->rol_padre_id,
                'padre' => $rol->padre?->nombre,
                'protegido' => $rol->protegido,
                'es_faceta' => $rol->rol_padre_id === null,
                'personas' => $rol->asignaciones()->count(),
            ],
            'catalogo' => CatalogoPermisos::paraPantalla(),
            'propios' => $rol->permissions->pluck('name')->values(),
            // Los heredados se muestran marcados y bloqueados: explican por qué
            // el rol puede algo que aquí no está palomeado. Ocultarlos haría
            // que la pantalla mintiera sobre lo que el rol puede hacer.
            'heredados' => $heredados,
            'padresPosibles' => Rol::query()
                ->where('id', '!=', $rol->id)
                ->orderBy('nombre')
                ->get(['id', 'nombre'])
                ->filter(fn (Rol $p) => $rol->admitePadre($p))
                ->values(),
            'asignados' => PersonaRol::query()
                ->with('persona:id,nombre,primer_apellido,segundo_apellido', 'campus:id,nombre')
                ->where('rol_id', $rol->id)
                ->get()
                ->map(fn (PersonaRol $a) => [
                    'id' => $a->id,
                    'persona_id' => $a->persona_id,
                    'persona' => $a->persona?->nombreCompleto(),
                    'campus' => $a->campus?->nombre,
                    'activo' => (bool) $a->activo,
                ])->values(),
            'esMiRolActivo' => $request->user()?->rol_activo_id === $rol->id,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'name' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9_]+$/', Rule::unique('roles', 'name')],
            'nombre' => ['required', 'string', 'max:120'],
            'tiempo_sesion' => ['nullable', 'integer', 'min:5', 'max:1440'],
            'rol_padre_id' => ['nullable', Rule::exists('roles', 'id')],
        ], [
            'name.regex' => 'La clave va en minúsculas, sin espacios ni acentos (ej. coordinador_becas).',
        ]);

        $rol = Rol::create($datos + ['guard_name' => 'web', 'protegido' => false]);

        $this->olvidarCache();

        return redirect("/plataforma/roles/{$rol->id}")
            ->with('exito', 'Rol creado. Ahora dile qué puede hacer.');
    }

    public function update(Request $request, Rol $rol): RedirectResponse
    {
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:120'],
            'tiempo_sesion' => ['nullable', 'integer', 'min:5', 'max:1440'],
            'rol_padre_id' => ['nullable', Rule::exists('roles', 'id')],
        ]);

        $padre = $datos['rol_padre_id'] === null ? null : Rol::find($datos['rol_padre_id']);

        if (! $rol->admitePadre($padre)) {
            return back()->with('error', 'Ese rol desciende de éste: colgarlo ahí formaría un ciclo.');
        }

        // La CLAVE de un rol protegido no se toca: hay código que la compara
        // por nombre. Su etiqueta y sus permisos sí son configurables.
        if ($rol->protegido && $padre !== null) {
            return back()->with('error', 'Las facetas del sistema no cuelgan de otro rol.');
        }

        $rol->update($datos);

        $this->olvidarCache();

        return back()->with('exito', 'Rol actualizado.');
    }

    /**
     * Reemplaza los permisos PROPIOS del rol. Los heredados no se tocan aquí:
     * se cambian en el rol padre, que es donde viven.
     */
    public function sincronizarPermisos(Request $request, Rol $rol): RedirectResponse
    {
        $datos = $request->validate([
            'permisos' => ['present', 'array'],
            'permisos.*' => ['string'],
        ]);

        // Solo permisos del catálogo. Uno que no exista en el código sería una
        // casilla que no restringe nada.
        $permisos = array_values(array_filter(
            $datos['permisos'],
            fn (string $p) => CatalogoPermisos::existe($p)
        ));

        // Salvaguarda contra el auto-encierro: si quien edita se quita
        // `gestionar-roles` de SU rol activo, nadie vuelve a entrar a esta
        // pantalla y la única salida es re-sembrar a mano. Se avisa en vez de
        // dejarlo pasar.
        $esMiRol = $request->user()?->rol_activo_id === $rol->id;
        $perdiaLaLlave = $esMiRol
            && $rol->permissions->pluck('name')->contains('gestionar-roles')
            && ! in_array('gestionar-roles', $permisos, true);

        if ($perdiaLaLlave) {
            return back()->with(
                'error',
                'No puedes quitarle «Administrar roles» al rol con el que estás operando: '
                .'te quedarías fuera de esta pantalla. Concédeselo antes a otro rol.'
            );
        }

        $rol->syncPermissions($permisos);

        $this->olvidarCache();

        return back()->with('exito', 'Permisos actualizados.');
    }

    /**
     * Un rol no se borra si alguien lo tiene o si otros cuelgan de él: en el
     * primer caso dejaría personas sin rol activo, en el segundo dejaría a sus
     * hijos sin la herencia que explicaba sus permisos.
     */
    public function destroy(Rol $rol): RedirectResponse
    {
        if ($rol->protegido) {
            return back()->with('error', 'Las facetas del sistema no se eliminan: hay código que las conoce por nombre.');
        }

        if ($rol->asignaciones()->exists()) {
            return back()->with('error', 'Hay personas con este rol. Reasígnalas antes de eliminarlo.');
        }

        if ($rol->hijos()->exists()) {
            return back()->with('error', 'Otros roles cuelgan de éste y perderían los permisos que heredan.');
        }

        $rol->delete();

        $this->olvidarCache();

        return redirect('/plataforma/roles')->with('exito', 'Rol eliminado.');
    }

    /** Le da este rol a una persona, con alcance opcional por campus. */
    public function asignar(Request $request, Rol $rol): RedirectResponse
    {
        $datos = $request->validate([
            'persona_id' => ['required', Rule::exists('personas', 'id')],
            'campus_id' => ['nullable', Rule::exists('campus', 'id')],
        ]);

        // MySQL trata los NULL como distintos, así que el índice único no
        // impide dos filas globales del mismo par persona-rol. Se valida aquí.
        $repetida = PersonaRol::query()
            ->where('persona_id', $datos['persona_id'])
            ->where('rol_id', $rol->id)
            ->where(fn ($q) => $datos['campus_id'] === null
                ? $q->whereNull('campus_id')
                : $q->where('campus_id', $datos['campus_id']))
            ->exists();

        if ($repetida) {
            return back()->with('error', 'Esa persona ya tiene este rol con ese alcance.');
        }

        PersonaRol::create($datos + ['rol_id' => $rol->id, 'activo' => true]);

        return back()->with('exito', 'Rol asignado.');
    }

    public function desasignar(Request $request, Rol $rol, PersonaRol $asignacion): RedirectResponse
    {
        abort_unless($asignacion->rol_id === $rol->id, 404);

        // Quitarse el propio rol activo deja al usuario sin contexto de trabajo
        // a medio request. El middleware `EstablecerRolActivo` lo reasignaría al
        // siguiente, pero es mejor explicarlo que sorprender.
        if ($request->user()?->persona_id === $asignacion->persona_id
            && $request->user()?->rol_activo_id === $rol->id) {
            return back()->with('error', 'Es el rol con el que estás operando ahora mismo. Conmuta a otro primero.');
        }

        $asignacion->delete();

        return back()->with('exito', 'Rol retirado.');
    }

    /** Personas para el buscador de asignación. */
    public function buscarPersonas(Request $request): JsonResponse
    {
        $termino = trim((string) $request->query('q', ''));

        if (mb_strlen($termino) < 2) {
            return response()->json([]);
        }

        return response()->json(
            Persona::query()
                ->whereRaw(
                    "concat_ws(' ', nombre, primer_apellido, segundo_apellido) like ?",
                    ["%{$termino}%"]
                )
                ->orWhere('curp', 'like', "%{$termino}%")
                ->orderBy('primer_apellido')
                ->limit(20)
                ->get()
                ->map(fn (Persona $p) => ['id' => $p->id, 'nombre' => $p->nombreCompleto()])
        );
    }

    /**
     * @param  Collection<int, Rol>  $todos
     * @return array<string, mixed>
     */
    private function resumen(Rol $rol, $todos): array
    {
        return [
            'id' => $rol->id,
            'clave' => $rol->name,
            'nombre' => $rol->nombre,
            'protegido' => $rol->protegido,
            'personas' => $rol->asignaciones_count,
            'permisos' => $rol->permissions->count(),
            'hijos' => $todos->where('rol_padre_id', $rol->id)->map(fn (Rol $h) => [
                'id' => $h->id,
                'clave' => $h->name,
                'nombre' => $h->nombre,
                'protegido' => $h->protegido,
                'personas' => $h->asignaciones_count,
                'permisos' => $h->permissions->count(),
            ])->values(),
        ];
    }

    /**
     * Spatie cachea el catálogo en el store configurado (database), así que
     * sobrevive entre procesos: sin este olvido, un permiso recién concedido
     * existe en la tabla pero NADIE lo ve hasta que el caché expira.
     */
    private function olvidarCache(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
