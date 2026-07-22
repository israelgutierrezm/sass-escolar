<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Academico\Oferta;
use App\Models\Admisiones\Aspirante;
use App\Models\Admisiones\DocumentoRequerido;
use App\Models\Admisiones\EstadoDocumento;
use App\Models\Admisiones\ExpedienteDocumento;
use App\Models\Finanzas\Adeudo;
use App\Models\Landlord\Sexo;
use App\Services\ProgresoSolicitud;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * El portal del interesado: llenar sus datos, subir sus papeles y ver lo que
 * debe.
 *
 * Todo lo que hace aquí lo puede hacer también un administrador desde la ficha
 * del aspirante — es el mismo expediente, no una copia—. Este portal existe
 * para que el dueño de la información pueda adelantarla él mismo.
 *
 * **No mueve la etapa del CRM.** El avance que calcula `ProgresoSolicitud` es
 * del EXPEDIENTE; el embudo lo sigue moviendo promoción con su criterio. Un
 * aspirante que subió todo no está "listo" hasta que alguien lo revise.
 *
 * Alcance: siempre el aspirante de la persona autenticada. No recibe id por la
 * URL, así que no hay forma de pedir el expediente de otro.
 */
class PortalAspiranteController extends Controller
{
    private const CARPETA = 'expedientes';

    public function __construct(private readonly ProgresoSolicitud $progreso) {}

    public function index(Request $request): Response
    {
        $aspirante = $this->miSolicitud($request);

        $aspirante->load('persona', 'ofertaInteres.carrera:id,nombre', 'ofertaInteres.campus:id,nombre');

        return Inertia::render('Portal/Solicitud', [
            'progreso' => $this->progreso->para($aspirante),
            'persona' => [
                'nombre' => $aspirante->persona?->nombre,
                'primer_apellido' => $aspirante->persona?->primer_apellido,
                'segundo_apellido' => $aspirante->persona?->segundo_apellido,
                'curp' => $aspirante->persona?->curp,
                'email' => $aspirante->persona?->email,
                'celular' => $aspirante->persona?->celular,
                'fecha_nacimiento' => $aspirante->persona?->fecha_nacimiento?->toDateString(),
                'sexo_id' => $aspirante->persona?->sexo_id,
            ],
            'solicitud' => [
                'oferta_id' => $aspirante->oferta_interes_id,
                'oferta' => $aspirante->ofertaInteres?->carrera?->nombre,
                'campus' => $aspirante->ofertaInteres?->campus?->nombre,
            ],
            'documentos' => $this->documentos($aspirante),
            'cargos' => $this->cargos($aspirante),
            'sexos' => Sexo::orderBy('id')->get(['id', 'nombre']),
            'ofertas' => Oferta::query()->with('carrera:id,nombre', 'campus:id,nombre')->get()
                ->map(fn (Oferta $o) => [
                    'id' => $o->id,
                    'nombre' => ($o->carrera?->nombre ?? 'Programa').' · '.($o->campus?->nombre ?? ''),
                ])->sortBy('nombre')->values(),
        ]);
    }

    public function guardarDatos(Request $request): RedirectResponse
    {
        $aspirante = $this->miSolicitud($request);

        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:100'],
            'primer_apellido' => ['required', 'string', 'max:100'],
            'segundo_apellido' => ['nullable', 'string', 'max:100'],
            // Única y nullable en `personas`: se ignora la propia fila para que
            // reguardar sin cambiarla no choque consigo misma.
            'curp' => [
                'nullable', 'string', 'size:18',
                Rule::unique('personas', 'curp')->ignore($aspirante->persona_id)->whereNull('deleted_at'),
            ],
            'email' => ['required', 'email', 'max:150'],
            'celular' => ['nullable', 'string', 'max:20'],
            'fecha_nacimiento' => ['nullable', 'date', 'before:today'],
            'sexo_id' => ['required', 'integer'],
            'oferta_id' => ['nullable', Rule::exists('oferta', 'id')],
        ], [
            'curp.unique' => 'Esa CURP ya está registrada con otra persona. Si crees que es un error, contáctanos.',
        ]);

        DB::transaction(function () use ($aspirante, $datos) {
            $aspirante->persona?->update([
                'nombre' => $datos['nombre'],
                'primer_apellido' => $datos['primer_apellido'],
                'segundo_apellido' => $datos['segundo_apellido'] ?? null,
                'curp' => filled($datos['curp'] ?? null) ? strtoupper($datos['curp']) : null,
                'email' => $datos['email'],
                'celular' => $datos['celular'] ?? null,
                'fecha_nacimiento' => $datos['fecha_nacimiento'] ?? null,
                'sexo_id' => $datos['sexo_id'],
            ]);

            if (filled($datos['oferta_id'] ?? null)) {
                $aspirante->update(['oferta_interes_id' => $datos['oferta_id']]);
            }
        });

        return back()->with('exito', 'Tus datos quedaron guardados.');
    }

    public function subirDocumento(Request $request): RedirectResponse
    {
        $aspirante = $this->miSolicitud($request);

        $datos = $request->validate([
            'documento_id' => ['required', 'integer', Rule::exists('documentos_requeridos', 'id')->whereNull('deleted_at')],
            'archivo' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ]);

        $ruta = $request->file('archivo')->store(
            sprintf('%s/%d', self::CARPETA, $aspirante->id),
            'local',
        );

        // Re-subir REEMPLAZA y reinicia la revisión: el archivo cambió, así que
        // el visto bueno anterior ya no dice nada del nuevo. Es la misma regla
        // del expediente del docente.
        ExpedienteDocumento::updateOrCreate(
            ['aspirante_id' => $aspirante->id, 'documento_id' => $datos['documento_id']],
            [
                'carrera_id' => $aspirante->ofertaInteres?->carrera_id,
                'url' => $ruta,
                'estado_documento_id' => EstadoDocumento::query()->where('clave', 'pendiente')->value('id'),
            ],
        );

        return back()->with('exito', 'Documento cargado. Alguien de la escuela lo va a revisar.');
    }

    /** Descarga de un documento propio. Nunca de otro: se filtra por aspirante. */
    public function descargarDocumento(Request $request, ExpedienteDocumento $documento): StreamedResponse
    {
        $aspirante = $this->miSolicitud($request);

        abort_unless($documento->aspirante_id === $aspirante->id, 403);
        abort_unless($documento->url !== null && Storage::disk('local')->exists($documento->url), 404);

        return Storage::disk('local')->download($documento->url);
    }

    /**
     * El expediente con lo que falta y lo que ya se revisó.
     *
     * Se listan TODOS los del ámbito aspirante, no solo los pendientes: ver el
     * catálogo completo es lo que le dice al interesado qué le van a pedir.
     *
     * @return array<int, array<string, mixed>>
     */
    private function documentos(Aspirante $aspirante): array
    {
        $entregados = ExpedienteDocumento::query()
            ->with('estado:id,clave,nombre')
            ->where('aspirante_id', $aspirante->id)
            ->get()
            ->keyBy('documento_id');

        return DocumentoRequerido::query()
            ->whereIn('id', DB::table('documento_ambitos')
                ->where('ambito', DocumentoRequerido::AMBITO_ASPIRANTE)
                ->pluck('documento_id'))
            ->orderByDesc('obligatorio')
            ->orderBy('nombre')
            ->get()
            ->map(function (DocumentoRequerido $d) use ($entregados) {
                $entrega = $entregados->get($d->id);

                return [
                    'id' => $d->id,
                    'nombre' => $d->nombre,
                    'descripcion' => $d->descripcion,
                    'obligatorio' => (bool) $d->obligatorio,
                    'entrega_id' => $entrega?->id,
                    'estado' => $entrega?->estado?->nombre,
                    'estado_clave' => $entrega?->estado?->clave,
                    // La observación del rechazo es lo único que le dice qué
                    // corregir; sin ella tiene que adivinar.
                    'observacion' => $entrega?->observaciones,
                ];
            })->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function cargos(Aspirante $aspirante): array
    {
        $cargos = Adeudo::query()
            ->with('concepto:id,nombre')
            ->deAspirante($aspirante->id)
            ->orderBy('fecha_vencimiento')
            ->get();

        return [
            'renglones' => $cargos->map(fn (Adeudo $a) => [
                'concepto' => $a->concepto?->nombre,
                'total' => (float) $a->monto_total,
                'saldo' => $a->saldo(),
                'vencimiento' => $a->fecha_vencimiento?->toDateString(),
                'vencido' => $a->estaVencido(),
                'estatus' => $a->estatus,
            ])->values(),
            'saldo' => round($cargos->sum(fn (Adeudo $a) => max(0, $a->saldo())), 2),
        ];
    }

    /**
     * La solicitud de quien entró. Si su persona no tiene aspirante, no hay
     * portal que mostrar — le pasa a quien conserva el rol pero ya se convirtió
     * en alumno, o a quien se lo asignaron por error.
     */
    private function miSolicitud(Request $request): Aspirante
    {
        $aspirante = Aspirante::query()
            ->where('persona_id', $request->user()->persona_id)
            ->orderByDesc('id')
            ->first();

        abort_if($aspirante === null, 404, 'No tienes una solicitud de admisión abierta.');

        return $aspirante;
    }
}
