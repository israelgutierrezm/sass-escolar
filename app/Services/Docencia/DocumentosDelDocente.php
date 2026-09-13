<?php

declare(strict_types=1);

namespace App\Services\Docencia;

use App\Models\Admisiones\DocumentoRequerido;
use App\Models\Admisiones\EstadoDocumento;
use App\Models\ControlEscolar\DocumentoDocente;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * El docente entrega, ve y retira los comprobantes de SU propio expediente.
 *
 * ── Una sola verdad ────────────────────────────────────────────────────────
 * La lista, la subida y el retiro vivían dentro de `ExpedienteDocenteController`
 * (la web); la app móvil necesitaba lo mismo. En vez de copiar las reglas —qué
 * papeles pide la escuela al docente, que re-subir reinicia la revisión, que un
 * aceptado no se retira desde aquí—, viven aquí y las usan los dos portales. Es
 * el molde de `EntregaDocumentos` para el alumno.
 *
 * ── Es SU expediente, no el de un menor ────────────────────────────────────
 * A diferencia de la familia, aquí no hay vínculo ni edad que comprobar: el
 * docente entrega lo suyo. Por eso re-subir un aceptado SÍ se permite —es su
 * documento y no sorprende a nadie—, pero retirarlo no: la escuela se apoyó en
 * él para acreditarlo.
 */
class DocumentosDelDocente
{
    /** La misma carpeta que «Mi expediente» del docente: es el mismo expediente. */
    private const CARPETA = 'docentes';

    /**
     * Los papeles que la escuela le pide al docente y los que ya subió, con su
     * estado de revisión. La misma forma que la familia: `documentos` + `tipos`.
     *
     * @return array{documentos: array<int, mixed>, tipos: array<int, mixed>}
     */
    public function datos(int $personaId): array
    {
        return [
            'documentos' => DocumentoDocente::query()
                ->with(['documento:id,nombre', 'estado:id,clave,nombre'])
                ->where('persona_id', $personaId)
                ->get()
                ->map(fn (DocumentoDocente $d) => [
                    'id' => $d->id,
                    // Con qué tipo cumple: es lo que permite saber cuáles de los
                    // obligatorios siguen sin entregar.
                    'documento_id' => $d->documento_id,
                    'documento' => $d->documento?->nombre,
                    'descripcion' => $d->descripcion,
                    'estado' => $d->estado?->nombre,
                    'estado_clave' => $d->estado?->clave,
                    'vigencia' => $d->vigencia?->toDateString(),
                    'vencido' => $d->estaVencido(),
                    'observaciones' => $d->observaciones,
                ])->values()->all(),
            // Sólo lo que la escuela pide a los DOCENTES: el catálogo del
            // aspirante o del alumno no tiene nada que hacer aquí.
            'tipos' => DocumentoRequerido::query()
                ->delAmbito(DocumentoRequerido::AMBITO_DOCENTE)
                ->orderBy('nombre')
                ->get(['id', 'nombre', 'obligatorio'])
                ->map(fn (DocumentoRequerido $d) => [
                    'id' => $d->id,
                    'nombre' => $d->nombre,
                    // Distinguirlos importa: faltar un obligatorio bloquea, y
                    // faltar uno opcional no es un pendiente que reclamar.
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
        $anterior = DocumentoDocente::query()
            ->where('persona_id', $personaId)
            ->where('documento_id', $documentoId)
            ->first();

        $ruta = $archivo->store(sprintf('%s/%d', self::CARPETA, $personaId), 'local');

        DocumentoDocente::updateOrCreate(
            ['persona_id' => $personaId, 'documento_id' => $documentoId],
            [
                'url' => $ruta,
                'descripcion' => $descripcion,
                'vigencia' => $vigencia,
                'estado_documento_id' => EstadoDocumento::query()->where('clave', 'pendiente')->value('id'),
                'observaciones' => null,
            ],
        );

        // El archivo viejo se borra del disco: se reemplazó, y guardarlo sólo
        // acumula datos personales que ya nadie va a consultar.
        if ($anterior !== null && $anterior->url !== $ruta) {
            Storage::disk('local')->delete($anterior->url);
        }
    }

    /** Que el documento sea de ESTE docente: la id viaja por la URL. */
    public function exigirDelDocente(int $personaId, DocumentoDocente $documento): void
    {
        abort_unless((int) $documento->persona_id === $personaId, 404);
    }

    /**
     * Retira un comprobante. Devuelve un mensaje de error —lo aceptado no se
     * retira desde aquí— o `null` en éxito.
     */
    public function eliminar(DocumentoDocente $documento): ?string
    {
        // Un documento ya aceptado no lo retira el docente: es el comprobante
        // en el que la escuela se apoyó para acreditarlo.
        if ($documento->estado?->clave === 'aceptado') {
            return 'Ese documento ya fue aceptado; pide a control escolar que lo retire.';
        }

        Storage::disk('local')->delete($documento->url);
        $documento->delete();

        return null;
    }
}
