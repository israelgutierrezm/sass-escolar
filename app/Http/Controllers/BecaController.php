<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AcotaPorCampus;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\ControlEscolar\Ciclo;
use App\Models\Finanzas\Beca;
use App\Models\Finanzas\BecaAlumno;
use App\Models\Finanzas\BecaAlumnoAutorizacion;
use App\Models\Finanzas\BecaAlumnoEvidencia;
use App\Models\Finanzas\BecaAlumnoMovimiento;
use App\Models\Finanzas\ConceptoPago;
use App\Models\Finanzas\Patrocinador;
use App\Models\Finanzas\PresupuestoBeca;
use App\Services\AutorizacionDeBecas;
use App\Services\EvaluadorBecas;
use App\Services\GeneradorAdeudos;
use App\Services\PresupuestoDeBecas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Becas: el catálogo y su otorgamiento.
 *
 * Una beca se define una vez con sus REGLAS (a qué conceptos aplica, si se
 * renueva por ciclo, qué pasa si el alumno se atrasa y qué promedio necesita) y
 * después se le otorga a alumnos concretos. Otorgar recalcula sus cargos
 * pendientes: quien ya pagó no se toca, pero lo que aún debe se le recompone con
 * el descuento.
 */
class BecaController extends Controller
{
    use AcotaPorCampus;

    public function __construct(
        private readonly GeneradorAdeudos $generador,
        private readonly EvaluadorBecas $evaluador,
        private readonly AutorizacionDeBecas $autorizacion,
    ) {}

    public function index(Request $request): Response
    {
        // Se busca una beca por su nombre, y se acota a las que siguen vivas:
        // el catálogo acumula las de convocatorias pasadas y ofrecerlas todas al
        // otorgar es invitar a asignar una que ya no existe.
        $filtros = [
            'busqueda' => trim((string) $request->query('busqueda', '')),
            'activo' => $request->query('activo'),
        ];

        $becas = Beca::query()
            ->with('conceptos:id,nombre')
            ->when($filtros['busqueda'] !== '', fn ($q) => $q->where(fn ($sub) => $sub
                ->where('clave', 'like', "%{$filtros['busqueda']}%")
                ->orWhere('nombre', 'like', "%{$filtros['busqueda']}%")))
            ->when($filtros['activo'], fn ($q) => $q->where('activo', true))
            ->withCount(['otorgadas as activas_count' => fn ($q) => $q->where('estatus', BecaAlumno::ACTIVA)])
            ->orderBy('nombre')
            ->get()
            ->map(fn (Beca $b) => [
                'id' => $b->id,
                'clave' => $b->clave,
                'nombre' => $b->nombre,
                'descripcion' => $b->descripcion,
                'modo' => $b->modo,
                'valor' => (float) $b->valor,
                'tope_monto' => $b->tope_monto !== null ? (float) $b->tope_monto : null,
                'conceptos' => $b->conceptos->pluck('nombre')->all(),
                'por_ciclo' => $b->por_ciclo,
                'requiere_renovacion' => $b->requiere_renovacion,
                'requiere_pago_puntual' => $b->requiere_pago_puntual,
                'dias_tolerancia' => $b->dias_tolerancia,
                'efecto_atraso' => $b->efecto_atraso,
                'promedio_minimo' => $b->promedio_minimo !== null ? (float) $b->promedio_minimo : null,
                'efecto_promedio' => $b->efecto_promedio,
                'activo' => $b->activo,
                'activas' => $b->activas_count,
            ]);

        return Inertia::render('Finanzas/Becas/Index', [
            'filtros' => $filtros,
            'becas' => $becas,
            'catalogoConceptos' => ConceptoPago::orderBy('nombre')->get(['id', 'nombre']),
            // Vigentes: una beca se otorga o se renueva sobre el ciclo que corre.
            'ciclos' => Ciclo::query()->vigentes()->orderByDesc('fecha_inicio')->get(['id', 'nombre']),
            // Cuántas becas renovables hay vivas: si no hay ninguna, la
            // herramienta de renovación no tiene sobre qué operar.
            'renovables' => BecaAlumno::query()
                ->activas()
                ->whereHas('beca', fn ($q) => $q->where('requiere_renovacion', true))
                ->count(),
            'efectosAtraso' => [
                ['valor' => Beca::ATRASO_NINGUNO, 'etiqueta' => 'No pasa nada'],
                ['valor' => Beca::ATRASO_SUSPENDE_PERIODO, 'etiqueta' => 'Ese cargo se cobra completo'],
                ['valor' => Beca::ATRASO_PIERDE, 'etiqueta' => 'Pierde la beca'],
            ],
            'efectosPromedio' => [
                ['valor' => Beca::PROMEDIO_NINGUNO, 'etiqueta' => 'No pasa nada'],
                ['valor' => Beca::PROMEDIO_NO_RENUEVA, 'etiqueta' => 'No se le renueva'],
                ['valor' => Beca::PROMEDIO_PIERDE, 'etiqueta' => 'Pierde la beca'],
            ],
        ]);
    }

    // ---------------------------------------------------- Bolsas y presupuesto

    public function presupuesto(Request $peticion, PresupuestoDeBecas $presupuesto): Response
    {
        $ciclos = $presupuesto->ciclos();
        // Sin ciclo pedido, el más reciente: es el que se está otorgando.
        $cicloId = (int) ($peticion->query('ciclo') ?: ($ciclos->first()->id ?? 0));

        return Inertia::render('Finanzas/Becas/Presupuesto', [
            'ciclos' => $ciclos->map(fn ($c) => ['valor' => $c->id, 'texto' => $c->nombre])->values(),
            'cicloId' => $cicloId,
            'bolsas' => $cicloId === 0 ? [] : $presupuesto->panorama($cicloId),
            'patrocinadores' => Patrocinador::query()->orderBy('nombre')->get()
                ->map(fn (Patrocinador $p) => [
                    'id' => $p->id,
                    'clave' => $p->clave,
                    'nombre' => $p->nombre,
                    'contacto' => $p->contacto,
                    'correo' => $p->correo,
                    'telefono' => $p->telefono,
                    'notas' => $p->notas,
                    'activo' => $p->activo,
                    'protegido' => (bool) $p->protegido,
                    'becas' => $p->becas()->count(),
                ])->values(),
        ]);
    }

    public function guardarPresupuesto(Request $peticion): RedirectResponse
    {
        $datos = $peticion->validate([
            'patrocinador_id' => ['required', 'integer', Rule::exists('patrocinadores', 'id')],
            'ciclo_id' => ['required', 'integer', Rule::exists('ciclos', 'id')],
            'monto' => ['required', 'numeric', 'min:0'],
            'notas' => ['nullable', 'string', 'max:1000'],
        ]);

        // `updateOrCreate` sobre la pareja que ya es única en la base: dos
        // capturas simultáneas no dejan dos bolsas del mismo ciclo, y volver a
        // guardar corrige el monto en vez de acumular filas.
        PresupuestoBeca::updateOrCreate(
            ['patrocinador_id' => (int) $datos['patrocinador_id'], 'ciclo_id' => (int) $datos['ciclo_id']],
            ['monto' => (float) $datos['monto'], 'notas' => $datos['notas'] ?? null],
        );

        return back()->with('exito', 'Presupuesto guardado.');
    }

    public function crearPatrocinador(Request $peticion): RedirectResponse
    {
        Patrocinador::create($this->validarPatrocinador($peticion));

        return back()->with('exito', 'Patrocinador creado.');
    }

    public function actualizarPatrocinador(Request $peticion, Patrocinador $patrocinador): RedirectResponse
    {
        $datos = $this->validarPatrocinador($peticion, $patrocinador);

        // «La escuela» no se renombra ni se apaga: es el valor por omisión de
        // toda beca nueva y hay becas colgando de ella. Sus datos de contacto sí
        // se pueden llenar, que es lo único que ahí significa algo.
        if ($patrocinador->protegido) {
            unset($datos['clave'], $datos['nombre'], $datos['activo']);
        }

        $patrocinador->update($datos);

        return back()->with('exito', 'Patrocinador actualizado.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validarPatrocinador(Request $peticion, ?Patrocinador $patrocinador = null): array
    {
        $datos = $peticion->validate([
            'clave' => ['required', 'string', 'max:30', Rule::unique('patrocinadores', 'clave')->ignore($patrocinador?->id)],
            'nombre' => ['required', 'string', 'max:150'],
            'contacto' => ['nullable', 'string', 'max:150'],
            'correo' => ['nullable', 'email', 'max:150'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'notas' => ['nullable', 'string', 'max:1000'],
            'activo' => ['boolean'],
        ]);

        $datos['activo'] = $peticion->boolean('activo');

        return $datos;
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $this->validar($request);

        $beca = Beca::create($datos);
        $beca->conceptos()->sync($datos['conceptos'] ?? []);

        return back()->with('exito', 'Beca creada.');
    }

    public function update(Request $request, Beca $beca): RedirectResponse
    {
        $datos = $this->validar($request, $beca);

        $beca->update($datos);
        $beca->conceptos()->sync($datos['conceptos'] ?? []);

        return back()->with('exito', 'Beca actualizada.');
    }

    public function destroy(Beca $beca): RedirectResponse
    {
        if ($beca->otorgadas()->exists()) {
            return back()->with('error', 'No se puede eliminar: ya se le otorgó a alumnos. Desactívala para que deje de aplicar.');
        }

        $beca->delete();

        return back()->with('exito', 'Beca eliminada.');
    }

    // ---------- Otorgamiento ----------

    /** Alumnos con esta beca, y a quiénes se les puede otorgar. */
    public function show(Request $request, Beca $beca): Response
    {
        $beca->load('conceptos:id,nombre');

        $consulta = BecaAlumno::query()->where('beca_id', $beca->id);

        // Solo los becarios de sus campus: la beca es global, los alumnos no.
        $this->acotarMatriculas($consulta, $request, 'matricula');

        $otorgadas = $consulta
            ->with([
                'matricula.persona:id,nombre,primer_apellido,segundo_apellido',
                'matricula.oferta.programaAcademico:id,nombre',
                'ciclo:id,nombre',
                'movimientos',
                'autorizaciones.nivel.rol:id,name,nombre',
                'autorizaciones.usuario.persona:id,nombre,primer_apellido,segundo_apellido',
                'evidencias',
            ])
            ->orderByDesc('id')
            ->get()
            ->map(fn (BecaAlumno $b) => [
                'id' => $b->id,
                'alumno' => $b->matricula?->persona?->nombreCompleto(),
                'matricula' => $b->matricula?->matricula,
                'programa_academico' => $b->matricula?->oferta?->programaAcademico?->nombre,
                'ciclo' => $b->ciclo?->nombre,
                'estatus' => $b->estatus,
                'vigente_desde' => $b->vigente_desde?->toDateString(),
                'vigente_hasta' => $b->vigente_hasta?->toDateString(),
                'promedio_evaluado' => $b->promedio_evaluado !== null ? (float) $b->promedio_evaluado : null,
                'motivo' => $b->motivo,
                'autorizaciones' => $b->autorizaciones
                    ->sortBy(fn (BecaAlumnoAutorizacion $a) => [$a->nivel?->orden ?? 0, $a->nivel_id])
                    ->map(fn (BecaAlumnoAutorizacion $a) => [
                        'nivel' => $a->nivel?->nombre,
                        'rol' => $a->nivel?->rol?->nombre ?: $a->nivel?->rol?->name,
                        'firmada' => $a->estaFirmada(),
                        'por' => $a->usuario?->persona?->nombreCompleto(),
                        'fecha' => $a->autorizada_en?->format('d/m/Y H:i'),
                        'motivo' => $a->motivo,
                    ])->values(),
                'evidencias' => $b->evidencias->map(fn (BecaAlumnoEvidencia $e) => [
                    'id' => $e->id,
                    'nombre' => $e->nombre,
                    'notas' => $e->notas,
                    'fecha' => $e->created_at?->format('d/m/Y'),
                ])->values(),
                'movimientos' => $b->movimientos->map(fn (BecaAlumnoMovimiento $m) => [
                    'accion' => $m->accion,
                    'detalle' => $m->detalle,
                    'por' => $m->realizado_por_nombre,
                    'fecha' => $m->created_at?->format('d/m/Y H:i'),
                ])->values(),
            ]);

        return Inertia::render('Finanzas/Becas/Detalle', [
            'beca' => [
                'id' => $beca->id,
                'clave' => $beca->clave,
                'nombre' => $beca->nombre,
                'descripcion' => $beca->descripcion,
                'modo' => $beca->modo,
                'valor' => (float) $beca->valor,
                'tope_monto' => $beca->tope_monto !== null ? (float) $beca->tope_monto : null,
                'conceptos' => $beca->conceptos->pluck('nombre')->all(),
                'por_ciclo' => $beca->por_ciclo,
                'requiere_renovacion' => $beca->requiere_renovacion,
                'requiere_pago_puntual' => $beca->requiere_pago_puntual,
                'dias_tolerancia' => $beca->dias_tolerancia,
                'efecto_atraso' => $beca->efecto_atraso,
                'promedio_minimo' => $beca->promedio_minimo !== null ? (float) $beca->promedio_minimo : null,
                'efecto_promedio' => $beca->efecto_promedio,
                'activo' => $beca->activo,
            ],
            'otorgadas' => $otorgadas,
            // Vigentes: una beca se otorga o se renueva sobre el ciclo que corre.
            'ciclos' => Ciclo::query()->vigentes()->orderByDesc('fecha_inicio')->get(['id', 'nombre']),
        ]);
    }

    /**
     * Con qué se sostiene una beca: el estudio socioeconómico, la carta, el
     * acta del comité.
     *
     * Cuelga de la beca OTORGADA y no del expediente de la persona: el papel
     * sostiene ESTA decisión, y la del ciclo que viene tendrá el suyo. Colgada
     * de la persona, renovar heredaría la evidencia vieja sin que nadie la
     * volviera a mirar.
     */
    public function subirEvidencia(Request $request, Beca $beca, BecaAlumno $otorgada): RedirectResponse
    {
        abort_unless($otorgada->beca_id === $beca->id, 404);
        $this->autorizarOtorgada($request, $otorgada);

        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:150'],
            'notas' => ['nullable', 'string', 'max:255'],
            'archivo' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:8192'],
        ]);

        // Al disco PRIVADO: un estudio socioeconómico trae el ingreso de una
        // familia, y en `public/` lo abriría cualquiera con la dirección.
        $ruta = $request->file('archivo')->store(
            sprintf('becas/evidencias/%d', $otorgada->id),
            'local',
        );

        BecaAlumnoEvidencia::create([
            'beca_alumno_id' => $otorgada->id,
            'nombre' => $datos['nombre'],
            'archivo_ruta' => $ruta,
            'notas' => $datos['notas'] ?? null,
        ]);

        return back(303)->with('exito', 'Evidencia cargada.');
    }

    public function descargarEvidencia(Request $request, Beca $beca, BecaAlumno $otorgada, BecaAlumnoEvidencia $evidencia): StreamedResponse
    {
        abort_unless($otorgada->beca_id === $beca->id, 404);
        // Las tres ids viajan por la URL: sin comprobar la PAREJA, con una beca
        // propia en los primeros huecos se pediría la evidencia de cualquiera.
        abort_unless($evidencia->beca_alumno_id === $otorgada->id, 404);
        $this->autorizarOtorgada($request, $otorgada);

        abort_unless(Storage::disk('local')->exists($evidencia->archivo_ruta), 404);

        return Storage::disk('local')->download($evidencia->archivo_ruta, $evidencia->nombre);
    }

    /**
     * Se retira mientras la beca espera firma. Una vez autorizada, no: la
     * evidencia es sobre lo que alguien firmó, y quitarla dejaría la firma
     * explicando un expediente que ya no está.
     */
    public function eliminarEvidencia(Request $request, Beca $beca, BecaAlumno $otorgada, BecaAlumnoEvidencia $evidencia): RedirectResponse
    {
        abort_unless($otorgada->beca_id === $beca->id, 404);
        abort_unless($evidencia->beca_alumno_id === $otorgada->id, 404);
        $this->autorizarOtorgada($request, $otorgada);

        $firmada = $otorgada->autorizaciones()->whereNotNull('autorizada_en')->exists();

        if ($firmada) {
            return back(303)->with('error', 'Esta beca ya tiene firmas: su evidencia no se retira. Sube la que falte.');
        }

        Storage::disk('local')->delete($evidencia->archivo_ruta);
        $evidencia->delete();

        return back(303)->with('exito', 'Evidencia retirada.');
    }

    /** Busca alumnos activos por matrícula o nombre, para otorgarles la beca. */
    public function buscarAlumnos(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        if (mb_strlen($q) < 2) {
            return response()->json([]);
        }

        $consulta = MatriculaOferta::query()->where('estatus', 'activo');

        // No se puede becar a quien no se administra: el buscador solo
        // encuentra alumnos de los campus del usuario.
        $this->acotarMatriculas($consulta, $request);

        $alumnos = $consulta
            ->where(function ($query) use ($q) {
                $query->where('matricula', 'like', "%{$q}%")
                    ->orWhereHas('persona', fn ($p) => $p
                        ->where('nombre', 'like', "%{$q}%")
                        ->orWhere('primer_apellido', 'like', "%{$q}%")
                        ->orWhere('segundo_apellido', 'like', "%{$q}%"));
            })
            ->with(['persona:id,nombre,primer_apellido,segundo_apellido', 'oferta.programaAcademico:id,nombre'])
            ->limit(20)
            ->get()
            ->map(fn (MatriculaOferta $m) => [
                'id' => $m->id,
                'matricula' => $m->matricula,
                'nombre' => $m->persona?->nombreCompleto(),
                'programa_academico' => $m->oferta?->programaAcademico?->nombre,
            ]);

        return response()->json($alumnos);
    }

    /**
     * Otorga la beca. Recalcula los cargos pendientes del alumno para que el
     * descuento se refleje en lo que todavía debe; lo pagado no se toca.
     */
    public function otorgar(Request $request, Beca $beca): RedirectResponse
    {
        $datos = $request->validate([
            'matricula_oferta_id' => ['required', 'integer', Rule::exists('matricula_oferta', 'id')],
            'ciclo_id' => ['nullable', 'integer', Rule::exists('ciclos', 'id')],
            'vigente_desde' => ['required', 'date'],
            'vigente_hasta' => ['nullable', 'date', 'after_or_equal:vigente_desde'],
            'promedio_evaluado' => ['nullable', 'numeric', 'min:0', 'max:10'],
            'motivo' => ['nullable', 'string', 'max:255'],
        ]);

        // El id viaja en el POST: filtrar el buscador no basta.
        $destino = MatriculaOferta::with('oferta:id,campus_id')->findOrFail($datos['matricula_oferta_id']);
        $this->autorizarMatricula($request, $destino);

        $yaTiene = BecaAlumno::query()
            ->where('beca_id', $beca->id)
            ->where('matricula_oferta_id', $datos['matricula_oferta_id'])
            ->where('ciclo_id', $datos['ciclo_id'] ?? null)
            ->exists();

        if ($yaTiene) {
            return back()->with('error', 'Ese alumno ya tiene esta beca en ese ciclo.');
        }

        /*
         * Si la escuela configuró niveles para una beca de este tamaño, nace
         * `por_autorizar` y NO descuenta: `aplicaEn()` exige ACTIVA. Sin
         * niveles se comporta como siempre, así que quien no use la escala no
         * nota el cambio.
         *
         * `autorizado_por` guarda a quien la PROPUSO. Las firmas viven en
         * `beca_alumno_autorizaciones`, que es lo que de verdad la habilita.
         */
        $niveles = $this->autorizacion->nivelesPara($beca);

        [, $tocados] = DB::transaction(function () use ($datos, $beca, $request, $niveles) {
            $creada = BecaAlumno::create($datos + [
                'beca_id' => $beca->id,
                'estatus' => $niveles->isEmpty() ? BecaAlumno::ACTIVA : BecaAlumno::POR_AUTORIZAR,
                'autorizado_por' => $request->user()?->persona_id,
            ]);

            $this->autorizacion->abrir($creada, $beca);
            $this->evaluador->registrar($creada, BecaAlumnoMovimiento::OTORGADA, $datos['motivo'] ?? null);

            // Recalcular una beca que todavía no descuenta sería recorrer sus
            // cargos para dejarlos igual, y el mensaje diría que se tocaron.
            if ($niveles->isNotEmpty()) {
                return [$creada, 0];
            }

            $matricula = MatriculaOferta::find($datos['matricula_oferta_id']);

            return [$creada, $matricula !== null ? $this->generador->recalcularPendientes($matricula) : 0];
        });

        if ($niveles->isNotEmpty()) {
            $quienes = $niveles->map(fn ($n) => $n->rol?->nombre ?: $n->rol?->name)->filter()->implode(', ');

            return back()->with(
                'exito',
                "Beca otorgada, en espera de {$niveles->count()} autorización(es): {$quienes}. No descuenta nada hasta que se firme."
            );
        }

        return back()->with(
            'exito',
            'Beca otorgada.'.($tocados > 0 ? " Se recalcularon {$tocados} cargo(s) pendientes." : '')
        );
    }

    /** Quita la beca (la marca perdida) y recompone sus cargos pendientes. */
    public function revocar(Request $request, Beca $beca, BecaAlumno $otorgada): RedirectResponse
    {
        abort_unless($otorgada->beca_id === $beca->id, 404);
        $this->autorizarOtorgada($request, $otorgada);

        $datos = $request->validate(['motivo' => ['required', 'string', 'max:255']]);

        $this->evaluador->perder($otorgada, $datos['motivo']);

        return back()->with('exito', 'Beca revocada. Sus cargos pendientes se recalcularon sin el descuento.');
    }

    /** Renueva la beca para otro ciclo: se crea la del ciclo nuevo. */
    public function renovar(Request $request, Beca $beca, BecaAlumno $otorgada): RedirectResponse
    {
        abort_unless($otorgada->beca_id === $beca->id, 404);
        $this->autorizarOtorgada($request, $otorgada);

        $datos = $request->validate([
            'ciclo_id' => ['required', 'integer', Rule::exists('ciclos', 'id')],
            'vigente_desde' => ['required', 'date'],
            'vigente_hasta' => ['nullable', 'date', 'after_or_equal:vigente_desde'],
        ]);

        /*
         * Renovar VUELVE a pedir firmas. Es un gasto nuevo, de otro ciclo, y
         * heredar la autorización del anterior convertiría una beca firmada
         * una vez en una beca firmada para siempre.
         */
        $niveles = $this->autorizacion->nivelesPara($beca);

        DB::transaction(function () use ($otorgada, $beca, $datos, $request, $niveles) {
            $creada = BecaAlumno::create([
                'matricula_oferta_id' => $otorgada->matricula_oferta_id,
                'beca_id' => $beca->id,
                'ciclo_id' => $datos['ciclo_id'],
                'estatus' => $niveles->isEmpty() ? BecaAlumno::ACTIVA : BecaAlumno::POR_AUTORIZAR,
                'vigente_desde' => $datos['vigente_desde'],
                'vigente_hasta' => $datos['vigente_hasta'] ?? null,
                'promedio_evaluado' => $otorgada->promedio_evaluado,
                'autorizado_por' => $request->user()?->persona_id,
                'motivo' => 'Renovación',
            ]);

            $this->autorizacion->abrir($creada, $beca);
            $this->evaluador->registrar($creada, BecaAlumnoMovimiento::RENOVADA, 'Renovada desde el ciclo anterior.');

            if ($niveles->isEmpty() && $otorgada->matricula !== null) {
                $this->generador->recalcularPendientes($otorgada->matricula);
            }

            return $creada;
        });

        return back()->with('exito', $niveles->isEmpty()
            ? 'Beca renovada para el ciclo nuevo.'
            : "Renovación registrada, en espera de {$niveles->count()} autorización(es). No descuenta hasta que se firme.");
    }

    /**
     * Cierra un ciclo para efectos de becas: con el promedio de cada alumno
     * decide cuáles quedan por renovar, cuáles no se renuevan y cuáles se
     * pierden.
     *
     * Las que sí califican quedan en `por_renovar`, NO renovadas solas: renovar
     * una beca es autorizar un gasto y debe hacerlo una persona.
     */
    public function evaluarRenovacion(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'ciclo_id' => ['required', 'integer', Rule::exists('ciclos', 'id')],
        ]);

        $ciclo = Ciclo::findOrFail($datos['ciclo_id']);
        $r = $this->evaluador->renovarCiclo($ciclo);

        if ($r['evaluados'] === 0) {
            return back()->with('advertencia', "El ciclo «{$ciclo->nombre}» no tiene calificaciones finales capturadas: no hay con qué evaluar los promedios.");
        }

        return back()->with(
            'exito',
            "Ciclo «{$ciclo->nombre}» evaluado sobre {$r['evaluados']} alumno(s): "
            ."{$r['por_renovar']} por renovar, {$r['no_renovadas']} sin renovar, {$r['perdidas']} perdida(s)."
        );
    }

    /** Una beca otorgada solo la toca quien administra el campus del alumno. */
    private function autorizarOtorgada(Request $request, BecaAlumno $otorgada): void
    {
        $matricula = $otorgada->matricula;

        if ($matricula === null) {
            return;
        }

        $matricula->loadMissing('oferta:id,campus_id');
        $this->autorizarMatricula($request, $matricula);
    }

    /** @return array<string, mixed> */
    private function validar(Request $request, ?Beca $beca = null): array
    {
        return $request->validate([
            'clave' => ['required', 'string', 'max:50', Rule::unique('becas', 'clave')->ignore($beca?->id)],
            'nombre' => ['required', 'string', 'max:150'],
            'descripcion' => ['nullable', 'string', 'max:255'],
            'modo' => ['required', Rule::in([Beca::MODO_PORCENTAJE, Beca::MODO_MONTO_FIJO])],
            'valor' => ['required', 'numeric', 'min:0'],
            'tope_monto' => ['nullable', 'numeric', 'min:0'],
            'conceptos' => ['array'],
            'conceptos.*' => ['integer', Rule::exists('conceptos_pago', 'id')],
            'por_ciclo' => ['boolean'],
            'requiere_renovacion' => ['boolean'],
            'requiere_pago_puntual' => ['boolean'],
            'dias_tolerancia' => ['required', 'integer', 'min:0', 'max:60'],
            'efecto_atraso' => ['required', Rule::in([Beca::ATRASO_NINGUNO, Beca::ATRASO_SUSPENDE_PERIODO, Beca::ATRASO_PIERDE])],
            'promedio_minimo' => ['nullable', 'numeric', 'min:0', 'max:10'],
            'efecto_promedio' => ['required', Rule::in([Beca::PROMEDIO_NINGUNO, Beca::PROMEDIO_NO_RENUEVA, Beca::PROMEDIO_PIERDE])],
            'activo' => ['boolean'],
        ]);
    }
}
