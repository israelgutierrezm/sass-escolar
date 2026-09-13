<?php

declare(strict_types=1);

namespace App\Services\ControlEscolar;

use App\Models\Admisiones\DocumentoRequerido;
use App\Models\Admisiones\EstadoDocumento;
use App\Models\ControlEscolar\DocumentoAlumno;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * El alumno entrega, ve y retira los comprobantes de SU propio expediente.
 *
 * ── Una sola verdad ────────────────────────────────────────────────────────
 * La lista, la subida y el retiro vivían dentro de `ExpedienteAlumnoController`
 * (la web); la app móvil necesitaba lo mismo. La regla vive aquí y la usan los
 * dos portales. Es el molde de `DocumentosDelDocente`.
 *
 * ── No confundir con `EntregaDocumentos` ───────────────────────────────────
 * Aquél es para que el TUTOR entregue por su hijo MENOR —con vínculo y edad—.
 * Éste es el alumno sobre SÍ MISMO: no hay vínculo ni edad, así que re-subir un
 * aceptado SÍ se permite (es su documento y no sorprende a nadie), pero
 * retirarlo no —la escuela se apoyó en él para acreditarlo—.
 */
class DocumentosDelAlumno
{
    /** La misma carpeta que «Mi expediente» del alumno y que la familia. */
    private const CARPETA = 'alumnos';

    /**
     * Los papeles que la escuela le pide al alumno y los que ya subió, con su
     * estado de revisión. Misma forma que la familia y el docente.
     *
     * @return array{documentos: array<int, mixed>, tipos: array<int, mixed>}
     */
    public function datos(int $personaId): array
    {
        return [
            'documentos' => DocumentoAlumno::query()
                ->with(['documento:id,nombre', 'estado:id,clave,nombre', 'registro.persona'])
                ->where('persona_id', $personaId)
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
                    // Quién lo entregó, y SÓLO cuando no fue el alumno: desde que
                    // el tutor puede entregar por su hijo menor, su expediente
                    // puede traer archivos que él no subió.
                    'entregado_por' => $d->created_by !== null && $d->registro?->persona_id !== $personaId
                        ? $d->registro?->persona?->nombreCompleto()
                        : null,
                ])->values()->all(),
            // Sólo lo del ámbito ALUMNO: ofrecerle el del aspirante le pediría
            // cosas que ya entregó al inscribirse.
            'tipos' => DocumentoRequerido::query()
                ->delAmbito(DocumentoRequerido::AMBITO_ALUMNO)
                ->orderBy('nombre')
                ->get(['id', 'nombre', 'obligatorio'])
                ->map(fn (DocumentoRequerido $d) => [
                    'id' => $d->id,
                    'nombre' => $d->nombre,
                    'obligatorio' => (bool) $d->obligatorio,
                ])->values()->all(),
        ];
    }

    /**
     * Sube (o reemplaza) un comprobante. Re-subir REINICIA la revisión: el
     * archivo cambió, así que el visto bueno anterior ya no dice nada del nuevo.
     */
    public function subir(int $personaId, int $documentoId, UploadedFile $archivo, ?string $descripcion, ?string $vigencia): void
    {
        $anterior = DocumentoAlumno::query()
            ->where('persona_id', $personaId)
            ->where('documento_id', $documentoId)
            ->first();

        $ruta = $archivo->store(sprintf('%s/%d', self::CARPETA, $personaId), 'local');

        DocumentoAlumno::updateOrCreate(
            ['persona_id' => $personaId, 'documento_id' => $documentoId],
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
    }

    /** Que el documento sea de ESTE alumno: la id viaja por la URL. */
    public function exigirDelAlumno(int $personaId, DocumentoAlumno $documento): void
    {
        abort_unless((int) $documento->persona_id === $personaId, 404);
    }

    /**
     * Retira un comprobante. Devuelve un mensaje de error —lo aceptado no se
     * retira desde aquí— o `null` en éxito.
     */
    public function eliminar(DocumentoAlumno $documento): ?string
    {
        if ($documento->estado?->clave === 'aceptado') {
            return 'Ese documento ya fue aceptado. Si cambió, súbelo otra vez.';
        }

        Storage::disk('local')->delete($documento->url);
        $documento->delete();

        return null;
    }
}
