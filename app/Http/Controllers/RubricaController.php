<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Lms\Rubrica;
use App\Models\Lms\RubricaCriterio;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * El catálogo de rúbricas.
 *
 * ── Por qué cuelga de la raíz y no de /academico ni de /docencia ───────────
 * Porque la usan los dos oficios, igual que la captura de calificaciones vive
 * en `/captura` y no dentro de `/escolar`. Metida en el catálogo académico
 * habría quedado detrás de un permiso administrativo y ningún docente podría
 * armarse la suya; metida en `/docencia`, al revés.
 *
 * ── Un permiso abre la puerta, otro decide qué se hace dentro ──────────────
 * Entrar lo resuelve `usar-rubricas` (derivado). Ya adentro:
 *
 *   - con `gestionar-rubricas` se publican y editan las de la ESCUELA;
 *   - sin él, ésas sólo se ven y se usan, y cada quien arma las SUYAS.
 *
 * Es alcance, no acceso, y por eso no son dos rutas.
 */
class RubricaController extends Controller
{
    public function index(Request $request): Response
    {
        $personaId = $request->user()?->persona_id;

        $rubricas = Rubrica::query()
            ->visiblesPara($personaId)
            ->with(['criterios.niveles', 'dueno:id,nombre,primer_apellido,segundo_apellido'])
            ->withCount('actividades')
            ->orderBy('ambito')
            ->orderBy('nombre')
            ->get();

        return Inertia::render('Rubricas/Index', [
            'rubricas' => $rubricas->map(fn (Rubrica $r) => $this->resumen($r, $personaId, $request))->values(),
            'puedo' => [
                'publicar' => $request->user()?->can('gestionar-rubricas') ?? false,
                // Sin persona no hay a nombre de quién guardar una rúbrica
                // propia. Pasa con las cuentas de sistema.
                'tenerPropias' => $personaId !== null,
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $personaId = $request->user()?->persona_id;
        $ambito = $this->ambitoElegido($request, $personaId);
        $datos = $this->validarFicha($request) + ['criterios' => $this->validarEstructura($request)];

        $rubrica = DB::transaction(function () use ($datos, $personaId, $ambito) {
            $rubrica = Rubrica::create([
                'nombre' => $datos['nombre'],
                'descripcion' => $datos['descripcion'] ?? null,
                'ambito' => $ambito,
                // Las de la escuela no tienen dueño: si lo tuvieran, el día que
                // esa persona se va habría que decidir de quién son.
                'persona_id' => $ambito === Rubrica::DOCENTE ? $personaId : null,
                'activa' => $datos['activa'] ?? true,
            ]);

            $this->rehacerEstructura($rubrica, $datos['criterios']);

            return $rubrica;
        });

        return back(303)->with('exito', "Rúbrica «{$rubrica->nombre}» guardada.");
    }

    public function update(Request $request, Rubrica $rubrica): RedirectResponse
    {
        $this->exigirQuePuedaEditarla($request, $rubrica);

        $ficha = $this->validarFicha($request);

        /*
         * El ÁMBITO no se toca al editar, a propósito.
         *
         * Pasar una propia a plataforma es PUBLICARLA, y traerla de vuelta se la
         * quitaría a todos los que ya la usan. Con un desplegable dentro del
         * formulario, ese efecto queda escondido detrás de un campo más. Para
         * publicar se duplica, que además deja el original donde estaba.
         */
        $congelada = ! $rubrica->estructuraEditable();

        DB::transaction(function () use ($rubrica, $ficha, $congelada, $request) {
            // El nombre y el interruptor se dejan cambiar SIEMPRE: son de la
            // ficha, no de la cuenta. Lo que no se puede con evaluaciones
            // colgando es mover los criterios.
            $rubrica->update([
                'nombre' => $ficha['nombre'],
                'descripcion' => $ficha['descripcion'] ?? null,
                'activa' => $ficha['activa'] ?? true,
            ]);

            if (! $congelada) {
                $this->rehacerEstructura($rubrica, $this->validarEstructura($request));
            }
        });

        return back(303)->with(
            'exito',
            $congelada
                ? 'Se guardó el nombre. Los criterios no: esta rúbrica ya calificó a alguien, así que para cambiarlos hay que duplicarla.'
                : 'Rúbrica actualizada.',
        );
    }

    /**
     * Duplicar: es la forma de «editar» una rúbrica que ya calificó.
     *
     * También es cómo se publica una propia —se duplica hacia plataforma— y cómo
     * un docente se queda con su versión de la de la escuela para ajustarla a su
     * materia sin cambiársela a nadie.
     */
    public function duplicar(Request $request, Rubrica $rubrica): RedirectResponse
    {
        // Se duplica cualquiera que se VEA, aunque no se pueda editar: copiar no
        // toca el original.
        $this->exigirQueLaVea($request, $rubrica);

        $personaId = $request->user()?->persona_id;
        $aPlataforma = $request->boolean('a_plataforma');

        if ($aPlataforma && ! ($request->user()?->can('gestionar-rubricas') ?? false)) {
            abort(403);
        }

        if (! $aPlataforma && $personaId === null) {
            return back()->with('error', 'Tu cuenta no está ligada a una persona, así que no puede tener rúbricas propias.');
        }

        $copia = DB::transaction(function () use ($rubrica, $personaId, $aPlataforma) {
            $copia = Rubrica::create([
                'nombre' => $this->nombreLibre($rubrica->nombre, $personaId, $aPlataforma),
                'descripcion' => $rubrica->descripcion,
                'ambito' => $aPlataforma ? Rubrica::PLATAFORMA : Rubrica::DOCENTE,
                'persona_id' => $aPlataforma ? null : $personaId,
                'activa' => true,
            ]);

            foreach ($rubrica->criterios as $criterio) {
                $nuevo = $copia->criterios()->create([
                    'titulo' => $criterio->titulo,
                    'descripcion' => $criterio->descripcion,
                    'orden' => $criterio->orden,
                ]);

                foreach ($criterio->niveles as $nivel) {
                    $nuevo->niveles()->create([
                        'titulo' => $nivel->titulo,
                        'descripcion' => $nivel->descripcion,
                        'puntos' => $nivel->puntos,
                        'orden' => $nivel->orden,
                    ]);
                }
            }

            return $copia;
        });

        return back(303)->with('exito', "Se creó «{$copia->nombre}». Es una copia: el original no cambió.");
    }

    /**
     * Retirarla del catálogo.
     *
     * Se borra sólo si nunca se usó ni está amarrada a nada. En cuanto hay
     * actividades detrás, se APAGA: borrarla les quitaría en silencio la rúbrica
     * con la que se iban a calificar, y a las ya calificadas, la explicación de
     * su nota.
     */
    public function destroy(Request $request, Rubrica $rubrica): RedirectResponse
    {
        $this->exigirQuePuedaEditarla($request, $rubrica);

        if ($rubrica->estaEnUso() || $rubrica->actividades()->exists()) {
            $rubrica->update(['activa' => false]);

            return back(303)->with(
                'exito',
                'Ya está en uso, así que se apagó en vez de borrarse: deja de ofrecerse para actividades nuevas y sigue explicando las calificaciones que puso.',
            );
        }

        $rubrica->delete();

        return back(303)->with('exito', 'Rúbrica eliminada.');
    }

    /**
     * Reemplaza criterios y niveles por los que vengan.
     *
     * Borrado FUERTE y de un tirón, sin diferenciar fila por fila. Se puede
     * porque quien llama ya comprobó que la rúbrica no ha calificado a nadie: no
     * hay un solo renglón de `entrega_rubrica` apuntando a estos criterios, así
     * que la cascada no se lleva nada por delante. Ir guardando cada borrador
     * como filas con `deleted_at` sólo llenaría la tabla.
     *
     * @param  array<int, array<string, mixed>>  $criterios
     */
    private function rehacerEstructura(Rubrica $rubrica, array $criterios): void
    {
        /*
         * Por el query builder y NO por el modelo: `delete()` de Eloquent sería
         * borrado lógico, la cascada de la base no se dispararía y los niveles
         * quedarían colgando de un criterio invisible —contando para el total
         * de una rúbrica que ya no los tiene—.
         */
        DB::table('rubrica_criterios')->where('rubrica_id', $rubrica->id)->delete();

        foreach (array_values($criterios) as $posicion => $criterio) {
            /** @var RubricaCriterio $nuevo */
            $nuevo = $rubrica->criterios()->create([
                'titulo' => $criterio['titulo'],
                'descripcion' => $criterio['descripcion'] ?? null,
                'orden' => $posicion,
            ]);

            foreach (array_values($criterio['niveles']) as $i => $nivel) {
                $nuevo->niveles()->create([
                    'titulo' => $nivel['titulo'],
                    'descripcion' => $nivel['descripcion'] ?? null,
                    'puntos' => $nivel['puntos'],
                    'orden' => $i,
                ]);
            }
        }

        $rubrica->load('criterios.niveles');
    }

    /**
     * Lo de la ficha: nombre, explicación e interruptor.
     *
     * @return array<string, mixed>
     */
    private function validarFicha(Request $request): array
    {
        return $request->validate([
            'nombre' => ['required', 'string', 'max:180'],
            'descripcion' => ['nullable', 'string', 'max:2000'],
            'activa' => ['boolean'],
        ], [], ['descripcion' => 'descripción']);
    }

    /**
     * Los criterios con sus niveles.
     *
     * @return array<int, array<string, mixed>>
     */
    private function validarEstructura(Request $request): array
    {
        $datos = $request->validate([
            'criterios' => ['required', 'array', 'min:1', 'max:30'],
            'criterios.*.titulo' => ['required', 'string', 'max:180'],
            'criterios.*.descripcion' => ['nullable', 'string', 'max:1000'],
            /*
             * Al menos DOS niveles por criterio.
             *
             * Uno solo no evalúa nada: da los mismos puntos pase lo que pase. Es
             * la clase de rúbrica que se arma a medias, se guarda, y luego
             * reparte calificaciones idénticas a treinta alumnos sin que nadie
             * entienda por qué.
             */
            'criterios.*.niveles' => ['required', 'array', 'min:2', 'max:10'],
            'criterios.*.niveles.*.titulo' => ['required', 'string', 'max:120'],
            'criterios.*.niveles.*.descripcion' => ['nullable', 'string', 'max:1000'],
            'criterios.*.niveles.*.puntos' => ['required', 'numeric', 'min:0', 'max:1000'],
        ], [], [
            'criterios.*.titulo' => 'título del criterio',
            'criterios.*.niveles' => 'niveles del criterio',
            'criterios.*.niveles.*.titulo' => 'título del nivel',
            'criterios.*.niveles.*.puntos' => 'puntos del nivel',
        ]);

        $total = 0.0;

        foreach ($datos['criterios'] as $criterio) {
            $total += (float) max(array_column($criterio['niveles'], 'puntos'));
        }

        // Una rúbrica que suma cero le pondría cero a todo el grupo, y lo haría
        // sin fallar: es exactamente el error que nadie ve hasta que es tarde.
        if ($total <= 0) {
            throw ValidationException::withMessages([
                'criterios' => 'La rúbrica suma cero puntos: al menos un nivel tiene que valer algo.',
            ]);
        }

        return $datos['criterios'];
    }

    /** Dónde se guarda: en la escuela o en lo propio. */
    private function ambitoElegido(Request $request, ?int $personaId): string
    {
        $quierePlataforma = $request->input('ambito') === Rubrica::PLATAFORMA;

        // Se comprueba en el servidor: la pantalla esconde la opción, pero el
        // POST llega igual.
        if ($quierePlataforma && ! ($request->user()?->can('gestionar-rubricas') ?? false)) {
            abort(403);
        }

        if (! $quierePlataforma && $personaId === null) {
            // Una cuenta sin persona no puede tener rúbricas propias. Si además
            // puede publicar, que la suya sea de la escuela.
            abort_unless($request->user()?->can('gestionar-rubricas') ?? false, 403);

            return Rubrica::PLATAFORMA;
        }

        return $quierePlataforma ? Rubrica::PLATAFORMA : Rubrica::DOCENTE;
    }

    /** «Ortografía» → «Ortografía (copia)», y si ya está, «(copia 2)». */
    private function nombreLibre(string $base, ?int $personaId, bool $aPlataforma): string
    {
        $existe = fn (string $nombre) => Rubrica::query()
            ->where('nombre', $nombre)
            ->when(
                $aPlataforma,
                fn ($q) => $q->where('ambito', Rubrica::PLATAFORMA),
                fn ($q) => $q->where('ambito', Rubrica::DOCENTE)->where('persona_id', $personaId),
            )
            ->exists();

        $candidato = "{$base} (copia)";
        $n = 2;

        while ($existe($candidato)) {
            $candidato = "{$base} (copia {$n})";
            $n++;
        }

        return mb_substr($candidato, 0, 180);
    }

    private function exigirQueLaVea(Request $request, Rubrica $rubrica): void
    {
        $personaId = $request->user()?->persona_id;

        $laVe = $rubrica->esDePlataforma()
            || ($personaId !== null && (int) $rubrica->persona_id === $personaId);

        // 404 y no 403: la de otro docente no existe para quien pregunta, y un
        // 403 ya revelaría que existe.
        abort_unless($laVe, 404);
    }

    private function exigirQuePuedaEditarla(Request $request, Rubrica $rubrica): void
    {
        $this->exigirQueLaVea($request, $rubrica);

        abort_unless(
            $rubrica->laPuedeEditar(
                $request->user()?->persona_id,
                $request->user()?->can('gestionar-rubricas') ?? false,
            ),
            403,
        );
    }

    /**
     * Lo que la pantalla necesita de cada rúbrica.
     *
     * @return array<string, mixed>
     */
    private function resumen(Rubrica $rubrica, ?int $personaId, Request $request): array
    {
        $gestiona = $request->user()?->can('gestionar-rubricas') ?? false;

        return [
            'id' => $rubrica->id,
            'nombre' => $rubrica->nombre,
            'descripcion' => $rubrica->descripcion,
            'ambito' => $rubrica->ambito,
            'dueno' => $rubrica->dueno?->nombreCompleto(),
            'mia' => $personaId !== null && (int) $rubrica->persona_id === $personaId,
            'activa' => (bool) $rubrica->activa,
            'total' => $rubrica->total(),
            'actividades' => (int) $rubrica->actividades_count,
            'en_uso' => $rubrica->estaEnUso(),
            'puedo_editar' => $rubrica->laPuedeEditar($personaId, $gestiona),
            'criterios' => $rubrica->criterios->map(fn (RubricaCriterio $c) => [
                'id' => $c->id,
                'titulo' => $c->titulo,
                'descripcion' => $c->descripcion,
                'maximo' => $c->maximo(),
                'niveles' => $c->niveles->map(fn ($n) => [
                    'id' => $n->id,
                    'titulo' => $n->titulo,
                    'descripcion' => $n->descripcion,
                    'puntos' => (float) $n->puntos,
                ])->values(),
            ])->values(),
        ];
    }
}
