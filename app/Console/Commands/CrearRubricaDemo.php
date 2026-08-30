<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\ControlEscolar\Docente;
use App\Models\ControlEscolar\Inscripcion;
use App\Models\Lms\Actividad;
use App\Models\Lms\Curso;
use App\Models\Lms\Entrega;
use App\Models\Lms\Rubrica;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Deja una materia lista para probar la calificación por rúbrica de punta a
 * punta: docente asignado, curso, actividad amarrada a una rúbrica y entregas
 * SIN calificar esperando al docente.
 *
 * ── Por qué hizo falta ─────────────────────────────────────────────────────
 * En el demo, `docente_asignatura_grupo` está VACÍA: ningún docente imparte
 * nada, así que `/docencia` no tiene por dónde entrar. Y los dos únicos cursos
 * del LMS cuelgan de dos `asignatura_grupo` que ya no existen —restos de una
 * resiembra con las comprobaciones de foránea apagadas, la misma que dejó tres
 * actividades apuntando a un componente inexistente—, con sus 7 actividades y
 * sus 3 entregas inalcanzables para cualquiera.
 *
 * O sea que el LMS del demo no se podía abrir. Esto lo repone sobre datos
 * vivos, sin tocar la isla huérfana: repararla sería inventarle un grupo a un
 * contenido cuyo grupo se perdió.
 *
 * ── Las entregas nacen SIN calificar ───────────────────────────────────────
 * Es el punto: lo que se quiere probar es calificar, y una materia que llega
 * con todo calificado no deja nada que hacer. El docente entra y encuentra
 * trabajo esperando, que es el estado real de una materia a media semana.
 *
 * Solo para desarrollo. Idempotente: correrlo dos veces no duplica nada.
 */
class CrearRubricaDemo extends Command
{
    protected $signature = 'acadion:rubrica-demo
        {--tenant=demo : Id de la escuela}
        {--materia= : Id de la asignatura_grupo; si no, se elige una con inscritos}
        {--docente= : persona_id del docente; si no, el primero del catálogo}';

    protected $description = 'Deja una materia con docente, rúbrica y entregas por calificar';

    public function handle(): int
    {
        $tenant = Tenant::find($this->option('tenant'));

        if ($tenant === null) {
            $this->error("No existe la escuela '{$this->option('tenant')}'.");

            return self::FAILURE;
        }

        tenancy()->initialize($tenant);

        $materia = $this->materia();

        if ($materia === null) {
            $this->error('No hay ninguna materia impartida con alumnos inscritos.');

            return self::FAILURE;
        }

        $docente = $this->docente();

        if ($docente === null) {
            $this->error('No hay docentes en el catálogo. Corre primero el seeder del tenant.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($materia, $docente) {
            $this->asignarDocente($materia, $docente);

            $rubrica = $this->rubrica();
            $actividad = $this->actividad($materia, $rubrica);

            $this->entregas($materia, $actividad);

            $this->info('Listo.');
            $this->line("  Materia    #{$materia->id} · ".($materia->planMateria?->asignatura?->nombre ?? 'sin nombre'));
            $this->line("  Docente    persona {$docente->persona_id} · ".($docente->persona?->nombreCompleto() ?? '?'));
            $this->line("  Rúbrica    «{$rubrica->nombre}» sobre {$rubrica->total()} puntos");
            $this->line("  Actividad  «{$actividad->titulo}» sobre {$actividad->puntos} puntos");
            $this->newLine();
            $this->line("  Entra en  /docencia/materias/{$materia->id}?panel=actividades");
        });

        return self::SUCCESS;
    }

    /**
     * La materia sobre la que se siembra.
     *
     * Se prefiere una que YA tenga componente de evaluación: así la nota de la
     * rúbrica no se queda en la entrega, sino que recorre el camino entero
     * hasta el parcial, que es lo que interesa comprobar.
     */
    private function materia(): ?AsignaturaGrupo
    {
        if ($this->option('materia') !== null) {
            return AsignaturaGrupo::with('planMateria.asignatura')->find((int) $this->option('materia'));
        }

        $conInscritos = AsignaturaGrupo::query()
            ->with('planMateria.asignatura')
            ->whereHas('inscripciones')
            ->get();

        return $conInscritos
            ->sortByDesc(fn (AsignaturaGrupo $ag) => DB::table('esquema_evaluacion')
                ->where('plan_materia_id', $ag->plan_materia_id)
                ->whereNull('deleted_at')
                ->count())
            ->first();
    }

    private function docente(): ?Docente
    {
        $id = $this->option('docente');

        return Docente::query()
            ->with('persona')
            ->when($id !== null, fn ($q) => $q->whereKey((int) $id))
            ->first();
    }

    /** El alcance del docente sale de aquí, no del permiso. */
    private function asignarDocente(AsignaturaGrupo $materia, Docente $docente): void
    {
        $materia->docentes()->syncWithoutDetaching([
            $docente->persona_id => ['tipo' => 'titular'],
        ]);
    }

    /**
     * Una rúbrica DE LA ESCUELA, no del docente.
     *
     * Es la que sirve para probar los dos caminos: se puede usar desde el curso
     * del docente y también desde la plantilla de un plan, donde las propias no
     * caben.
     */
    private function rubrica(): Rubrica
    {
        $rubrica = Rubrica::query()
            ->where('nombre', 'Trabajo escrito')
            ->where('ambito', Rubrica::PLATAFORMA)
            ->with('criterios.niveles')
            ->first();

        if ($rubrica !== null) {
            return $rubrica;
        }

        $rubrica = Rubrica::create([
            'nombre' => 'Trabajo escrito',
            'descripcion' => 'Para ensayos y reportes de dos a cuatro cuartillas.',
            'ambito' => Rubrica::PLATAFORMA,
            'activa' => true,
        ]);

        $armado = [
            ['Argumentación', 'Si sostiene lo que afirma.', [
                ['Excelente', 4, 'Tesis clara, sostenida con al menos dos fuentes.'],
                ['Suficiente', 2, 'Tesis clara, con una fuente o ninguna.'],
                ['Insuficiente', 0, 'No se distingue qué está sosteniendo.'],
            ]],
            ['Organización', 'Si se puede seguir de principio a fin.', [
                ['Excelente', 3, 'Introducción, desarrollo y cierre, cada uno en su sitio.'],
                ['Suficiente', 1, 'Se sigue, pero hay que releer para ubicarse.'],
                ['Insuficiente', 0, 'Las ideas aparecen sin orden.'],
            ]],
            ['Ortografía y redacción', null, [
                ['Sin errores', 3, 'Ninguna falta; la puntuación ayuda a leer.'],
                ['Con descuidos', 1, 'Hasta cinco faltas que no estorban la lectura.'],
                ['Con errores', 0, 'Las faltas obligan a adivinar qué quiso decir.'],
            ]],
        ];

        foreach ($armado as $i => [$titulo, $descripcion, $niveles]) {
            $criterio = $rubrica->criterios()->create([
                'titulo' => $titulo,
                'descripcion' => $descripcion,
                'orden' => $i,
            ]);

            foreach ($niveles as $j => [$etiqueta, $puntos, $descriptor]) {
                $criterio->niveles()->create([
                    'titulo' => $etiqueta,
                    'descripcion' => $descriptor,
                    'puntos' => $puntos,
                    'orden' => $j,
                ]);
            }
        }

        return $rubrica->load('criterios.niveles');
    }

    /**
     * La actividad, sobre 10 puntos y con la rúbrica de 10.
     *
     * Van a la MISMA escala a propósito: para mirar la pantalla, que la nota
     * coincida con la suma quita una variable. La conversión ya está cubierta
     * por `scripts/prueba-rubricas.php`, donde la rúbrica va sobre 6 y la
     * actividad sobre 10.
     */
    private function actividad(AsignaturaGrupo $materia, Rubrica $rubrica): Actividad
    {
        $curso = Curso::primeraOReviver(
            ['asignatura_grupo_id' => $materia->id],
            ['publicado' => true, 'titulo' => 'Curso en línea'],
        );

        $componente = DB::table('esquema_evaluacion')
            ->where('plan_materia_id', $materia->plan_materia_id)
            ->whereNull('deleted_at')
            ->value('id');

        /*
         * `updateOrCreate` a secas y no `actualizarOReviver`: `actividades` no
         * tiene único sobre (curso, título), así que no hay llave contra la que
         * chocar, y `Actividad` no lleva ese trait. Si alguien borró la
         * actividad de prueba, crear otra es lo correcto.
         */
        return Actividad::updateOrCreate(
            ['curso_id' => $curso->id, 'titulo' => 'Ensayo: por qué elegí este programa académico'],
            [
                'tipo' => 'actividad',
                'instrucciones' => 'Dos cuartillas. Se califica con la rúbrica de abajo: léela antes de escribir.',
                'esquema_evaluacion_id' => $componente,
                'rubrica_id' => $rubrica->id,
                'puntos' => 10,
                'permite_reentrega' => true,
                'publicada' => true,
                'orden' => 1,
            ],
        );
    }

    /**
     * Una entrega por alumno inscrito, SIN calificar.
     *
     * `primeraOReviver` y no `actualizarOReviver`: si el docente ya calificó
     * alguna al probar, volver a correr el comando no debe borrarle el trabajo.
     */
    private function entregas(AsignaturaGrupo $materia, Actividad $actividad): void
    {
        $inscripciones = Inscripcion::query()
            ->where('asignatura_grupo_id', $materia->id)
            ->pluck('id');

        foreach ($inscripciones as $i => $inscripcionId) {
            Entrega::primeraOReviver(
                ['actividad_id' => $actividad->id, 'inscripcion_id' => (int) $inscripcionId],
                [
                    'estado' => Entrega::ENTREGADA,
                    'entregada_en' => now()->subDays($i + 1),
                    'contenido' => 'Elegí este programa académico porque desde la preparatoria me llamó '
                        .'la atención cómo se resuelven los problemas con lógica, y quiero '
                        .'dedicarme a eso. (Texto de prueba.)',
                ],
            );
        }

        $this->line("  Entregas   {$inscripciones->count()}, sin calificar");
    }
}
