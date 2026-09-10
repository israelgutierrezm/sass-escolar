<?php

declare(strict_types=1);

namespace App\Services\Familia;

use App\Exceptions\AvisoParaElUsuario;
use App\Models\Admisiones\DocumentoRequerido;
use App\Models\Admisiones\EstadoDocumento;
use App\Models\ControlEscolar\DocumentoAlumno;
use App\Models\Identidad\Persona;
use App\Models\Identidad\TutorAlumno;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * El tutor entrega, ve y retira los documentos de su hijo MENOR.
 *
 * ── Una sola verdad ────────────────────────────────────────────────────────
 * La lista, las tres capas de permiso y la escritura vivían repartidas entre
 * `PadreController` y `DocumentosDelHijoController`; la app móvil necesitaba lo
 * mismo. En vez de copiar los guardas —de si la escuela lo permite, de si es
 * este hijo, de que un aceptado no se pise—, la regla vive aquí y la usan los
 * dos portales.
 *
 * ── Escribe en la MISMA tabla del alumno ───────────────────────────────────
 * El acta de un alumno es de su expediente, la haya subido él o su madre. Quién
 * la entregó lo dice la auditoría, no una tabla aparte.
 */
class EntregaDocumentos
{
    /** La misma carpeta que «Mi expediente» del alumno: es el mismo expediente. */
    private const CARPETA = 'alumnos';

    public function __construct(private readonly RepresentacionDelTutor $representacion) {}

    /**
     * Los papeles que la escuela le pide al hijo y los que ya subió, o `null`
     * cuando la escuela no tiene contratado este acto —la sección no existe—.
     * Si el tutor no puede entregar por este hijo, se devuelve el motivo y NADA
     * más: consultarlos es representarlo igual que subirlos.
     *
     * @return array{motivo: ?string, edad: ?int, mayoria_de_edad: int, documentos: array<int, mixed>, tipos: array<int, mixed>}|null
     */
    public function datos(Persona $hijo, ?TutorAlumno $vinculo): ?array
    {
        if (! $this->representacion->laEscuelaPermiteEntregarDocumentos()) {
            return null;
        }

        $motivo = $this->representacion->motivoParaNoEntregarDocumentos($vinculo, $hijo);

        $bloque = [
            'motivo' => $motivo,
            'edad' => $this->representacion->edad($hijo),
            'mayoria_de_edad' => $this->representacion->mayoriaDeEdad(),
            'documentos' => [],
            'tipos' => [],
        ];

        if ($motivo !== null) {
            return $bloque;
        }

        return [
            ...$bloque,
            'documentos' => DocumentoAlumno::query()
                ->with(['documento:id,nombre', 'estado:id,clave,nombre'])
                ->where('persona_id', $hijo->id)
                ->get()
                ->map(fn (DocumentoAlumno $d) => [
                    'id' => $d->id,
                    'documento_id' => $d->documento_id,
                    'documento' => $d->documento?->nombre,
                    'descripcion' => $d->descripcion,
                    'estado' => $d->estado?->nombre,
                    'estado_clave' => $d->estado?->clave,
                    'vigencia' => $d->vigencia?->toDateString(),
                    'vencido' => $d->estaVencido(),
                    'observaciones' => $d->observaciones,
                ])->values(),
            'tipos' => DocumentoRequerido::query()
                ->delAmbito(DocumentoRequerido::AMBITO_ALUMNO)
                ->orderByDesc('obligatorio')
                ->orderBy('nombre')
                ->get(['id', 'nombre', 'obligatorio'])
                ->map(fn (DocumentoRequerido $d) => [
                    'id' => $d->id,
                    'nombre' => $d->nombre,
                    'obligatorio' => (bool) $d->obligatorio,
                ])->values(),
        ];
    }

    /**
     * Las tres capas, en el orden en que cambian la respuesta: primero el 404 de
     * la escuela —lo que no está contratado no existe para nadie— y después el
     * 403 de este vínculo, que sí es personal y lleva su razón escrita.
     */
    public function exigirPoderEntregar(?TutorAlumno $vinculo, Persona $hijo): void
    {
        abort_unless($this->representacion->laEscuelaPermiteEntregarDocumentos(), 404);

        $motivo = $this->representacion->motivoParaNoEntregarDocumentos($vinculo, $hijo);

        AvisoParaElUsuario::si($motivo !== null, 403, (string) $motivo);
    }

    /** Que el documento sea de ESE hijo: las dos ids viajan por la URL. */
    public function exigirDeEseHijo(Persona $hijo, DocumentoAlumno $documento): void
    {
        AvisoParaElUsuario::aMenosQue(
            (int) $documento->persona_id === (int) $hijo->id,
            403,
            'Ese documento no es de tu hijo.',
        );
    }

    /**
     * Sube (o reemplaza) un documento. Devuelve un mensaje de error cuando no se
     * puede —lo aceptado no se pisa desde aquí— o `null` en éxito.
     *
     * Re-subir REINICIA la revisión: el archivo cambió, así que el visto bueno
     * anterior ya no dice nada del nuevo.
     */
    public function subir(Persona $hijo, int $documentoId, UploadedFile $archivo, ?string $descripcion, ?string $vigencia): ?string
    {
        $anterior = DocumentoAlumno::query()
            ->where('persona_id', $hijo->id)
            ->where('documento_id', $documentoId)
            ->first();

        // Al tutor no se le deja pisar lo aceptado: un documento aprobado y luego
        // reemplazado por otra persona volvería a revisión sin que el alumno se
        // entere de que su expediente cambió.
        if ($anterior?->estado?->clave === 'aceptado') {
            return 'Ese documento ya fue aceptado. Si cambió, pídelo en control escolar.';
        }

        $ruta = $archivo->store(sprintf('%s/%d', self::CARPETA, $hijo->id), 'local');

        DocumentoAlumno::updateOrCreate(
            ['persona_id' => $hijo->id, 'documento_id' => $documentoId],
            [
                'url' => $ruta,
                'descripcion' => $descripcion,
                'vigencia' => $vigencia,
                'estado_documento_id' => EstadoDocumento::query()->where('clave', 'pendiente')->value('id'),
                'observaciones' => null,
            ],
        );

        if ($anterior !== null && $anterior->url !== $ruta) {
            Storage::disk('local')->delete($anterior->url);
        }

        return null;
    }

    /**
     * Retira un documento. Devuelve un mensaje de error —lo aceptado no se retira
     * desde aquí— o `null` en éxito.
     */
    public function eliminar(DocumentoAlumno $documento): ?string
    {
        if ($documento->estado?->clave === 'aceptado') {
            return 'Ese documento ya fue aceptado y no se puede retirar desde aquí.';
        }

        Storage::disk('local')->delete($documento->url);
        $documento->delete();

        return null;
    }
}
