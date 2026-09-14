<?php

declare(strict_types=1);

namespace App\Services\Admisiones;

use App\Models\Admisiones\Aspirante;
use App\Models\Admisiones\DocumentoRequerido;
use App\Models\Admisiones\EstadoDocumento;
use App\Models\Admisiones\ExpedienteDocumento;
use App\Models\Academico\Oferta;
use App\Models\Finanzas\Adeudo;
use App\Models\Landlord\Genero;
use App\Rules\CurpValida;
use App\Services\IdentidadPersona;
use App\Services\ProgresoSolicitud;
use App\Services\ResolutorFormularios;
use App\Support\Curp;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * La solicitud de admisión vista y editada por el propio interesado.
 *
 * Vive aquí y no en el controlador porque la web (`PortalAspiranteController`) y
 * la app la comparten. Lo que se comparte son las REGLAS: el avance de los
 * cuatro pasos (que ya calcula `ProgresoSolicitud`), qué documentos se piden y
 * cómo se derivan sexo/fecha/entidad de la CURP al guardar los datos. Escrito
 * dos veces, la app dejaría entrar por una puerta que la web cierra.
 */
class AutoservicioSolicitud
{
    private const CARPETA = 'expedientes';

    public function __construct(
        private readonly ProgresoSolicitud $progreso,
        private readonly ResolutorFormularios $formularios,
        private readonly IdentidadPersona $identidad,
    ) {}

    /**
     * Todo lo que la pantalla del interesado necesita: el avance, sus datos, la
     * oferta, los documentos, los cargos, los formularios que le tocan y los
     * catálogos para editar (géneros y ofertas).
     *
     * @return array<string, mixed>
     */
    public function panorama(Aspirante $aspirante): array
    {
        $aspirante->load(
            'persona.entidadNacimiento',
            'ofertaInteres.programaAcademico:id,nombre',
            'ofertaInteres.campus:id,nombre',
        );

        // Una sola resolución para el avance y para la lista de formularios.
        $formularios = $this->formularios->para($aspirante);

        return [
            'progreso' => $this->progreso->para($aspirante, $formularios),
            'persona' => [
                'nombre' => $aspirante->persona?->nombre,
                'primer_apellido' => $aspirante->persona?->primer_apellido,
                'segundo_apellido' => $aspirante->persona?->segundo_apellido,
                // Al que declaró no tener CURP se le devuelve la marca, no un
                // campo vacío: escribió EXTRANJERO y volver a verlo en blanco
                // parece que no se guardó.
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
                'oferta' => $aspirante->ofertaInteres?->programaAcademico?->nombre,
                'campus' => $aspirante->ofertaInteres?->campus?->nombre,
            ],
            'documentos' => $this->documentos($aspirante),
            'cargos' => $this->cargos($aspirante),
            'formularios' => $formularios->values()->all(),
            'generos' => Genero::orderBy('id')->get(['id', 'nombre'])->all(),
            'ofertas' => Oferta::query()->with('programaAcademico:id,nombre', 'campus:id,nombre')->get()
                ->map(fn (Oferta $o) => [
                    'id' => $o->id,
                    'nombre' => ($o->programaAcademico?->nombre ?? 'Programa').' · '.($o->campus?->nombre ?? ''),
                ])->sortBy('nombre')->values()->all(),
        ];
    }

    /**
     * Las reglas de validación de los datos del interesado, en UN sitio: la web y
     * la app validan igual. La CURP es obligatoria y se autoverifica (dígito), y
     * es única salvo cuando es EXTRANJERO (se guarda como null y no colisiona); el
     * correo es único en la plataforma (el mismo comprobador que el alta
     * administrativa).
     *
     * @return array<string, mixed>
     */
    public function reglasDatos(?int $personaId, ?string $curpInput): array
    {
        return [
            'nombre' => ['required', 'string', 'max:100'],
            'primer_apellido' => ['required', 'string', 'max:100'],
            'segundo_apellido' => ['nullable', 'string', 'max:100'],
            'curp' => array_filter([
                'required', 'string', 'max:20',
                new CurpValida,
                Curp::esMarcaDeExtranjero($curpInput)
                    ? null
                    : Rule::unique('personas', 'curp')->ignore($personaId)->whereNull('deleted_at'),
            ]),
            'email' => ['required', 'email', 'max:150', function (string $atributo, mixed $valor, \Closure $fallar) use ($personaId) {
                if ($this->identidad->correoEnUso($valor, $personaId) !== null) {
                    $fallar('Ese correo ya está registrado a nombre de otra persona. Si crees que es un error, contáctanos.');
                }
            }],
            'celular' => ['nullable', 'string', 'max:20'],
            'fecha_nacimiento' => ['nullable', 'date', 'before:today'],
            // Sin saber a qué aspira, la escuela no sabe qué documentos pedirle.
            'genero_id' => ['required', 'integer'],
            'oferta_id' => ['required', Rule::exists('oferta', 'id')],
        ];
    }

    /**
     * Mensajes propios: quien llena esto es el interesado desde su celular, no
     * alguien que conozca el sistema.
     *
     * @return array<string, string>
     */
    public function mensajesDatos(): array
    {
        return [
            'curp.required' => 'Falta tu CURP. Si no tienes, escribe EXTRANJERO.',
            'curp.unique' => 'Esa CURP ya está registrada a nombre de otra persona. Si crees que es un error, contáctanos.',
            'genero_id.required' => 'Falta elegir tu género.',
            'oferta_id.required' => 'Falta elegir el programa que te interesa.',
            'oferta_id.exists' => 'Ese programa ya no está disponible. Elige otro de la lista.',
            'email.required' => 'Falta tu correo: por ahí te avisan si te aceptan.',
        ];
    }

    /**
     * Guarda los datos del interesado. La CURP —el único dato con dígito
     * verificador— deriva sexo, fecha y entidad de nacimiento por el MISMO
     * resolvedor que el resto de los roles. Los datos ya vienen validados.
     */
    public function guardarDatos(Aspirante $aspirante, array $datos): void
    {
        DB::transaction(function () use ($aspirante, $datos) {
            $resuelto = $this->identidad->resolver($datos);

            // El portal no captura correo institucional ni teléfono local;
            // escribirlos borraría lo que la escuela ya le haya asignado.
            unset($resuelto['correo_institucional'], $resuelto['telefono_local']);

            $aspirante->persona?->update($resuelto);

            if (filled($datos['oferta_id'] ?? null)) {
                $aspirante->update(['oferta_interes_id' => $datos['oferta_id']]);
            }
        });
    }

    /**
     * Sube (o reemplaza) un documento del expediente. Re-subir REEMPLAZA y
     * reinicia la revisión: el archivo cambió, así que el visto bueno anterior ya
     * no dice nada del nuevo (misma regla que el expediente del docente).
     */
    public function subirDocumento(Aspirante $aspirante, int $documentoId, UploadedFile $archivo): void
    {
        $ruta = $archivo->store(sprintf('%s/%d', self::CARPETA, $aspirante->id), 'local');

        ExpedienteDocumento::updateOrCreate(
            ['aspirante_id' => $aspirante->id, 'documento_id' => $documentoId],
            [
                'programa_academico_id' => $aspirante->ofertaInteres?->programa_academico_id,
                'url' => $ruta,
                'estado_documento_id' => EstadoDocumento::query()->where('clave', 'pendiente')->value('id'),
            ],
        );
    }

    /**
     * Todos los del ámbito aspirante —no sólo los pendientes—: ver el catálogo
     * completo es lo que le dice qué le van a pedir.
     *
     * @return array<int, array<string, mixed>>
     */
    public function documentos(Aspirante $aspirante): array
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
                    // La observación del rechazo es lo único que le dice qué corregir.
                    'observacion' => $entrega?->observaciones,
                ];
            })->values()->all();
    }

    /** @return array<string, mixed> */
    public function cargos(Aspirante $aspirante): array
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
            ])->values()->all(),
            'saldo' => round($cargos->sum(fn (Adeudo $a) => max(0, $a->saldo())), 2),
        ];
    }
}
