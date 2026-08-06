<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResuelveMiSolicitud;
use App\Models\Academico\Oferta;
use App\Models\Admisiones\Aspirante;
use App\Models\Admisiones\DocumentoRequerido;
use App\Models\Admisiones\EstadoDocumento;
use App\Models\Admisiones\ExpedienteDocumento;
use App\Models\Finanzas\Adeudo;
use App\Models\Landlord\Genero;
use App\Rules\CurpValida;
use App\Services\IdentidadPersona;
use App\Services\ProgresoSolicitud;
use App\Services\ResolutorFormularios;
use App\Support\Curp;
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
    use ResuelveMiSolicitud;

    private const CARPETA = 'expedientes';

    public function __construct(private readonly ProgresoSolicitud $progreso) {}

    public function index(Request $request): Response
    {
        $aspirante = $this->miSolicitud($request);

        // `persona.entidadNacimiento` la mira `sinCurpPorExtranjero()`, y sin
        // precargarla saldría por consulta suelta en cada visita.
        $aspirante->load(
            'persona.entidadNacimiento',
            'ofertaInteres.carrera:id,nombre',
            'ofertaInteres.campus:id,nombre',
        );

        return Inertia::render('Portal/Solicitud', [
            'progreso' => $this->progreso->para($aspirante),
            'persona' => [
                'nombre' => $aspirante->persona?->nombre,
                'primer_apellido' => $aspirante->persona?->primer_apellido,
                'segundo_apellido' => $aspirante->persona?->segundo_apellido,
                // Al que declaró no tener CURP se le devuelve la marca, no un
                // campo vacío: escribió EXTRANJERO, se guardó, y volver a
                // encontrarlo en blanco parece que no se guardó nada.
                'curp' => $aspirante->persona?->sinCurpPorExtranjero()
                    ? Curp::MARCA_EXTRANJERO
                    : $aspirante->persona?->curp,
                'email' => $aspirante->persona?->email,
                'celular' => $aspirante->persona?->celular,
                'fecha_nacimiento' => $aspirante->persona?->fecha_nacimiento?->toDateString(),
                'genero_id' => $aspirante->persona?->genero_id,
            ],
            'solicitud' => [
                'oferta_id' => $aspirante->oferta_interes_id,
                'oferta' => $aspirante->ofertaInteres?->carrera?->nombre,
                'campus' => $aspirante->ofertaInteres?->campus?->nombre,
            ],
            'documentos' => $this->documentos($aspirante),
            'cargos' => $this->cargos($aspirante),
            // Los formularios que le tocan. Los mismos que ve quien lo atiende
            // desde la ficha: es un solo expediente mirado desde dos lados.
            'formularios' => app(ResolutorFormularios::class)->para($aspirante),
            'generos' => Genero::orderBy('id')->get(['id', 'nombre']),
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
            /*
             * OBLIGATORIA, y validada de verdad.
             *
             * Era `nullable` + `size:18`, que acepta cualquier ristra de
             * dieciocho caracteres: se podía terminar la solicitud sin CURP, o
             * con una mal copiada. Es el único dato de esta pantalla que se
             * autoverifica, y de él salen fecha, sexo y entidad de nacimiento.
             *
             * `size:18` no está y no puede estar: hay que dejar pasar la palabra
             * EXTRANJERO, que es como se registra quien no tiene CURP.
             * `CurpValida` acepta esa marca y comprueba el dígito del resto.
             *
             * Única: dos filas con la misma CURP son la misma persona capturada
             * dos veces. Se ignora la propia para que reguardar sin cambiarla no
             * choque consigo misma; y cuando es EXTRANJERO no aplica, porque se
             * guarda como null y ahí no hay nada que colisionar.
             */
            'curp' => array_filter([
                'required', 'string', 'max:20',
                new CurpValida,
                Curp::esMarcaDeExtranjero($request->input('curp'))
                    ? null
                    : Rule::unique('personas', 'curp')->ignore($aspirante->persona_id)->whereNull('deleted_at'),
            ]),
            /*
             * Y ÚNICO en la plataforma. No lo era aquí: el aspirante podía
             * teclear el correo de otra persona ya registrada y quedarse con
             * él, que es exactamente lo que parte a alguien en dos cuentas y
             * cruza sesiones en el login. Se usa el mismo comprobador que el
             * alta administrativa, para que el mensaje sea el mismo.
             */
            'email' => ['required', 'email', 'max:150', function (string $atributo, mixed $valor, \Closure $fallar) use ($aspirante) {
                $conflicto = app(IdentidadPersona::class)->correoEnUso($valor, $aspirante->persona_id);

                if ($conflicto !== null) {
                    $fallar('Ese correo ya está registrado a nombre de otra persona. Si crees que es un error, contáctanos.');
                }
            }],
            'celular' => ['nullable', 'string', 'max:20'],
            'fecha_nacimiento' => ['nullable', 'date', 'before:today'],
            'genero_id' => ['required', 'integer'],
            // Obligatorio: sin saber a qué aspira, la escuela no sabe qué
            // documentos pedirle ni a qué oferta inscribirlo si lo aceptan.
            'oferta_id' => ['required', Rule::exists('oferta', 'id')],
        ], [
            /*
             * Mensajes propios, no el genérico con el nombre del campo.
             *
             * `:attribute es obligatorio` no concuerda en género —salía «la CURP
             * es obligatorio»— y, sobre todo, no dice qué hacer. Quien llena
             * esto es el interesado desde su celular, no alguien que conozca el
             * sistema: la frase tiene que bastarle.
             */
            'curp.required' => 'Falta tu CURP. Si no tienes, escribe EXTRANJERO.',
            'curp.unique' => 'Esa CURP ya está registrada a nombre de otra persona. Si crees que es un error, contáctanos.',
            'genero_id.required' => 'Falta elegir tu género.',
            'oferta_id.required' => 'Falta elegir el programa que te interesa.',
            'oferta_id.exists' => 'Ese programa ya no está disponible. Elige otro de la lista.',
            'email.required' => 'Falta tu correo: por ahí te avisan si te aceptan.',
        ]);

        DB::transaction(function () use ($aspirante, $datos) {
            /*
             * Por el mismo resolvedor que el resto de los roles.
             *
             * Aquí se armaba el arreglo a mano y se escribía `$datos['sexo_id']`
             * —una clave que la validación ya no produce—: lo que el aspirante
             * elegía no llegaba a su persona. `resolver()` deriva sexo, fecha y
             * entidad de nacimiento de la CURP, que es el único dato con dígito
             * verificador de toda la pantalla.
             */
            $resuelto = app(IdentidadPersona::class)->resolver($datos);

            // El portal no captura correo institucional ni teléfono local:
            // `resolver()` los devuelve en null, y escribirlos borraría lo que
            // la escuela ya le haya asignado.
            unset($resuelto['correo_institucional'], $resuelto['telefono_local']);

            /*
             * Aquí se conservaba la CURP tecleada cuando `resolver()` la
             * devolvía en null, para no borrar la que ya estaba. Sobra —y
             * ahora estorba—: `CurpValida` ya no deja pasar una CURP que no
             * cuadre, así que el único null posible es el del EXTRANJERO, y ése
             * es la respuesta correcta, no una pérdida. Conservarlo escribiría
             * la palabra «EXTRANJERO» en la columna única y sólo cabría UN
             * extranjero en toda la escuela; el segundo chocaría con un error
             * incomprensible.
             */

            $aspirante->persona?->update($resuelto);

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

}
