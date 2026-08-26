<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\AvisoParaElUsuario;
use App\Services\Plataforma\ModulosDeLaEscuela;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\ControlEscolar\Historial;
use App\Models\Finanzas\CuentaBancaria;
use App\Models\Finanzas\Factura;
use App\Models\Identidad\BitacoraAcceso;
use App\Models\Identidad\Parentesco;
use App\Models\Identidad\Persona;
use App\Models\Identidad\TutorAlumno;
use App\Services\EstadoCuenta;
use App\Services\EstadoDelAlumno;
use App\Services\HistorialDelAlumno;
use App\Services\Pagos\Pasarelas;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Portal del padre / tutor familiar: la información de los alumnos que tiene
 * vinculados.
 *
 * El alcance NO lo da un permiso amplio sino la PERTENENCIA: solo ve a los hijos
 * ligados a él en `tutores_alumno`, y de cada uno solo lo que el vínculo
 * permite —lo académico y/o lo financiero—. Cambiar el id en la URL para espiar
 * a un alumno ajeno choca contra ese vínculo y devuelve 403.
 */
class PadreController extends Controller
{
    public function __construct(
        private readonly EstadoCuenta $estadoCuenta,
        private readonly EstadoDelAlumno $estadoDelAlumno,
        private readonly HistorialDelAlumno $historialDelAlumno,
    ) {}

    public function misHijos(Request $request): Response
    {
        $persona = $request->user()->persona;

        $hijos = $persona->hijos()->get()->map(function (Persona $hijo) {
            $carreras = $hijo->matriculas()
                ->with('oferta.carrera:id,nombre')
                ->get()
                ->map(fn (MatriculaOferta $m) => $m->oferta?->carrera?->nombre)
                ->filter()
                ->values();

            return [
                'id' => $hijo->id,
                'nombre' => $hijo->nombreCompleto(),
                'foto' => $hijo->urlFoto(),
                'parentesco' => Parentesco::nombreDe($hijo->pivot->parentesco_id),
                'carreras' => $carreras,
                'puede_ver_academico' => (bool) $hijo->pivot->puede_ver_academico,
                'puede_ver_finanzas' => (bool) $hijo->pivot->puede_ver_finanzas,
                /*
                 * El ESTADO, no solo el nombre.
                 *
                 * El listado era un directorio: nombre, parentesco y carreras.
                 * Un padre no entra aquí a recordar cómo se llaman sus hijos;
                 * entra a saber si deben algo o si van mal, y para enterarse
                 * tenía que abrir cada ficha una por una. Con dos hijos son dos
                 * clics de más cada vez; con cuatro, ocho.
                 *
                 * Se respeta lo que la escuela le dejó ver: sin permiso
                 * académico no se calcula el promedio, y sin permiso de
                 * finanzas no se toca el estado de cuenta. Que la señal no
                 * exista es más seguro que ocultarla en la vista.
                 */
                'estado' => $this->estadoDelAlumno->de($hijo, (bool) $hijo->pivot->puede_ver_academico, (bool) $hijo->pivot->puede_ver_finanzas),
            ];
        })->values();

        return Inertia::render('Padre/MisHijos', [
            'hijos' => $hijos,
            /*
             * Lo que le PIDEN contestar, arriba de todo lo demás en la pantalla.
             *
             * Va aquí y no en una sección aparte porque una autorización tiene
             * plazo: si vive en otra página, se contesta cuando alguien se
             * acuerda de entrar, y el día de la excursión faltan firmas.
             */
            'autorizaciones' => AutorizacionController::deFamiliar($request->user()),
        ]);
    }

    public function hijo(Request $request, Persona $hijo): Response
    {
        $vinculo = TutorAlumno::query()
            ->where('tutor_persona_id', $request->user()->persona_id)
            ->where('alumno_persona_id', $hijo->id)
            ->first();

        AvisoParaElUsuario::si($vinculo === null, 403, 'Este alumno no está vinculado a tu cuenta.');

        $matriculas = $hijo->matriculas()
            ->with([
                'oferta.carrera:id,nombre',
                'oferta.plan:id,nombre,total_creditos',
                'oferta.campus:id,nombre',
                'situacion:id,nombre',
            ])
            ->orderByDesc('fecha_ingreso')
            ->get();

        return Inertia::render('Padre/Hijo', [
            'hijo' => [
                'id' => $hijo->id,
                'nombre' => $hijo->nombreCompleto(),
                'foto' => $hijo->urlFoto(),
                'curp' => $hijo->curp,
                'parentesco' => $vinculo->parentesco?->nombre,
            ],
            'permisos' => [
                'academico' => $vinculo->puede_ver_academico,
                'finanzas' => $vinculo->puede_ver_finanzas,
            ],
            /*
             * Qué estudia, aparte del detalle de cada cosa.
             *
             * La pantalla elige una carrera a la vez, y necesita la lista para
             * ofrecerla. Va por separado de `academico` y `finanzas` porque
             * cada uno de ésos depende de su propio permiso: sin esto, a un
             * padre que sólo ve lo financiero no se le podría decir cuál de las
             * dos carreras está mirando.
             */
            'carreras' => $matriculas->map(fn (MatriculaOferta $m) => [
                'matricula' => $m->matricula,
                'carrera' => $m->oferta?->carrera?->nombre,
                'campus' => $m->oferta?->campus?->nombre,
            ])->values(),
            'academico' => $vinculo->puede_ver_academico
                ? $matriculas->map(fn (MatriculaOferta $m) => $this->academicoDe($m))->values()
                : null,
            'finanzas' => $vinculo->puede_ver_finanzas
                ? $matriculas->map(fn (MatriculaOferta $m) => $this->finanzasDe($m))->values()
                : null,
            /*
             * Con qué puede pagar aquí mismo.
             *
             * Va atado al permiso financiero del vínculo, igual que los saldos:
             * un padre que no puede ver lo que se debe tampoco tiene por qué ver
             * botones para pagarlo. Y vacío cuando la escuela no tiene ninguna
             * pasarela encendida, para que el panel no aparezca.
             */
            'pasarelas' => $vinculo->puede_ver_finanzas
                ? app(Pasarelas::class)->disponibles()
                : [],
            /*
             * Y la transferencia directa, con las cuentas que sirven para las
             * carreras de sus hijos. Se juntan las de todas sus matrículas: la
             * pantalla enseña una carrera a la vez, pero pedirlas por separado
             * obligaría a recargar al cambiar de foco.
             */
            'cuentasBancarias' => $vinculo->puede_ver_finanzas
                ? $matriculas
                    ->flatMap(fn (MatriculaOferta $m) => CuentaBancaria::paraCarrera($m->oferta?->carrera_id))
                    ->filter(fn (CuentaBancaria $c) => $c->puedeRecibir())
                    ->unique('id')
                    ->map(fn (CuentaBancaria $c) => [
                        'id' => $c->id,
                        'nombre' => $c->nombre,
                        'banco' => $c->banco,
                        'titular' => $c->titular,
                        'clabe' => $c->clabe,
                        'numero_cuenta' => $c->numero_cuenta,
                        'instrucciones' => $c->instrucciones,
                    ])->values()
                : [],
            /*
             * La conducta del hijo: incidencias y sanciones, gateadas por el
             * permiso `ver-conducta-hijo` de la faceta —no por el vínculo, que
             * distingue académico de financiero pero no contempla disciplina—.
             * Sólo se consulta si el módulo está encendido y el padre puede.
             */
            'conducta' => ($request->user()->can('ver-conducta-hijo') && app(ModulosDeLaEscuela::class)->activo('disciplina'))
                ? $this->conductaDe($matriculas)
                : null,
            // Los accesos del hijo: un padre puede vigilar cuándo y desde dónde
            // entra su hijo, aunque no vea sus calificaciones ni sus finanzas.
            'accesos' => BitacoraAcceso::query()
                ->where('persona_id', $hijo->id)
                ->whereIn('tipo', [BitacoraAcceso::ENTRADA, BitacoraAcceso::SALIDA])
                ->orderByDesc('creado_en')
                ->limit(20)
                ->get()
                ->map(fn (BitacoraAcceso $b) => [
                    'tipo' => $b->tipo,
                    'ip' => $b->ip,
                    'navegador' => $b->navegador,
                    'equipo' => $b->equipo,
                    'momento' => $b->creado_en?->toDateTimeString(),
                ]),
        ]);
    }

    /**
     * Incidencias y sanciones de todas las matrículas del hijo, para su portal.
     *
     * De sólo lectura: el padre CONSULTA, no registra. Se juntan las de todas
     * sus carreras porque la conducta se lee por alumno, aunque cuelgue de la
     * matrícula.
     *
     * @param  \Illuminate\Support\Collection<int, MatriculaOferta>  $matriculas
     * @return array{incidencias: array<int, mixed>, sanciones: array<int, mixed>}
     */
    private function conductaDe($matriculas): array
    {
        $ids = $matriculas->pluck('id');

        $incidencias = \App\Models\Disciplina\Incidencia::query()
            ->whereIn('matricula_oferta_id', $ids)
            ->with('tipo:id,nombre,nivel')
            ->orderByDesc('fecha')
            ->limit(50)
            ->get()
            ->map(fn ($i) => [
                'id' => $i->id,
                'tipo' => $i->tipo?->nombre,
                'nivel' => $i->tipo?->nivel,
                'fecha' => $i->fecha?->format('Y-m-d'),
                'descripcion' => $i->descripcion,
            ]);

        $sanciones = \App\Models\Disciplina\Sancion::query()
            ->whereIn('matricula_oferta_id', $ids)
            ->with('tipo:id,nombre')
            ->orderByDesc('fecha')
            ->limit(50)
            ->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'tipo' => $s->tipo?->nombre,
                'fecha' => $s->fecha?->format('Y-m-d'),
                'desde' => $s->desde?->format('Y-m-d'),
                'hasta' => $s->hasta?->format('Y-m-d'),
                'vigente' => $s->vigente(),
                'motivo' => $s->motivo,
            ]);

        return ['incidencias' => $incidencias->all(), 'sanciones' => $sanciones->all()];
    }

    /**
     * @return array<string, mixed>
     */
    private function academicoDe(MatriculaOferta $m): array
    {
        $historial = Historial::query()
            ->with(['planMateria.asignatura:id,nombre,creditos', 'ciclo:id,clave', 'estatus:id,clave,nombre'])
            ->where('matricula_oferta_id', $m->id)
            ->get()
            ->sortBy([['ciclo.clave', 'asc']])
            ->values();

        /*
         * Las cifras salen de `HistorialDelAlumno`, no de aquí.
         *
         * Ésta era la TERCERA implementación del promedio del proyecto, y
         * promediaba TODOS los renglones: una materia reprobada en ordinario y
         * aprobada a título contaba dos veces, y sus créditos también —
         * `Creditos::sumar()` sobre todas las aprobadas los cobra dos veces si
         * la misma materia se aprobó dos veces—. El servicio toma el MEJOR
         * intento por materia, que es la regla oficial escrita en
         * `docs/decisiones.md` (2026-08-26).
         *
         * Los RENGLONES sí se enseñan todos: es historia escolar y no se borra.
         * Lo que no se hace es sumarlos.
         */
        $resumen = $this->historialDelAlumno->resumen($m);

        return [
            'matricula' => $m->matricula,
            'carrera' => $m->oferta?->carrera?->nombre,
            'plan' => $m->oferta?->plan?->nombre,
            'estatus' => $m->estatus,
            'promedio' => $resumen['promedio'],
            'creditos' => $resumen['creditos'],
            'creditos_del_plan' => $resumen['creditos_del_plan'],
            'materias' => $historial->map(fn (Historial $h) => [
                'materia' => $h->planMateria?->asignatura?->nombre,
                'ciclo' => $h->ciclo?->clave,
                'calificacion' => $h->calificacion,
                'estatus' => $h->estatus?->nombre,
                'estatus_clave' => $h->estatus?->clave,
            ]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function finanzasDe(MatriculaOferta $m): array
    {
        $cuenta = $this->estadoCuenta->para($m);

        $facturas = Factura::query()
            ->where('matricula_oferta_id', $m->id)
            ->orderByDesc('id')
            ->get()
            ->map(fn (Factura $f) => [
                'uuid' => $f->uuid,
                'total' => (float) $f->total,
                'estatus' => $f->estatus,
                'fecha' => $f->fecha_timbrado?->toDateString(),
            ]);

        return [
            // El id, no sólo la matrícula impresa: es lo que necesita el panel
            // de pago para decirle al servidor qué cuenta se está pagando.
            'matricula_id' => $m->id,
            'matricula' => $m->matricula,
            'carrera' => $m->oferta?->carrera?->nombre,
            'saldo' => $cuenta['resumen']['saldo'],
            'adeudos' => $cuenta['adeudos'],
            'pagos' => $cuenta['pagos'],
            'facturas' => $facturas,
        ];
    }
}
