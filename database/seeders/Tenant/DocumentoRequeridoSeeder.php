<?php

declare(strict_types=1);

namespace Database\Seeders\Tenant;

use App\Models\Admisiones\DocumentoRequerido;
use App\Models\Admisiones\EtiquetaDocumento;
use Illuminate\Database\Seeder;

/**
 * Documentos que se piden en admisión (TENANT). Son los típicos de una escuela
 * mexicana; cada escuela ajusta la lista y decide qué carreras exigen cuáles
 * (pivote documento_carrera).
 *
 * Idempotente por nombre.
 */
class DocumentoRequeridoSeeder extends Seeder
{
    public function run(): void
    {
        $etiquetas = [];

        foreach ([
            ['identidad', 'Identidad'],
            ['academico', 'Académico'],
            ['domicilio', 'Domicilio'],
        ] as [$clave, $nombre]) {
            $etiquetas[$clave] = EtiquetaDocumento::query()->updateOrCreate(
                ['clave' => $clave],
                ['nombre' => $nombre],
            )->id;
        }

        $documentos = [
            ['Acta de nacimiento', 'Copia certificada.', true, 'identidad'],
            ['CURP', 'Impresión reciente del formato oficial.', true, 'identidad'],
            ['Identificación oficial', 'INE del aspirante o del tutor si es menor.', true, 'identidad'],
            ['Certificado de estudios previos', 'Certificado del nivel inmediato anterior.', true, 'academico'],
            ['Comprobante de domicilio', 'No mayor a 3 meses de antigüedad.', true, 'domicilio'],
            ['Fotografías tamaño infantil', 'Seis, blanco y negro.', false, 'identidad'],
            ['Certificado médico', 'Expedido por institución pública.', false, null],
        ];

        foreach ($documentos as [$nombre, $descripcion, $obligatorio, $etiqueta]) {
            $documento = DocumentoRequerido::query()->updateOrCreate(
                ['nombre' => $nombre],
                ['descripcion' => $descripcion, 'obligatorio' => $obligatorio],
            );

            if ($etiqueta !== null) {
                $documento->etiquetas()->syncWithoutDetaching([$etiquetas[$etiqueta]]);
            }
        }
    }
}
