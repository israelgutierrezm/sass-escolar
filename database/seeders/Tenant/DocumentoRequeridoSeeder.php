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

        // El cuarto elemento es a QUIÉN se le pide. Los de identidad se piden a
        // todos porque son los mismos papeles: el acta de nacimiento de un
        // docente no es un documento distinto del de un alumno.
        $documentos = [
            ['Acta de nacimiento', 'Copia certificada.', true, 'identidad', ['aspirante', 'alumno', 'docente']],
            ['CURP', 'Impresión reciente del formato oficial.', true, 'identidad', ['aspirante', 'alumno', 'docente']],
            ['Identificación oficial', 'INE del aspirante o del tutor si es menor.', true, 'identidad', ['aspirante', 'alumno', 'docente', 'tutor']],
            ['Certificado de estudios previos', 'Certificado del nivel inmediato anterior.', true, 'academico', ['aspirante']],
            ['Comprobante de domicilio', 'No mayor a 3 meses de antigüedad.', true, 'domicilio', ['aspirante', 'docente']],
            ['Fotografías tamaño infantil', 'Seis, blanco y negro.', false, 'identidad', ['aspirante']],
            ['Certificado médico', 'Expedido por institución pública.', false, null, ['aspirante']],
            // Propios del docente: lo que acredita que puede dar clase.
            ['Título profesional', 'Copia del título registrado.', true, 'academico', ['docente']],
            ['Cédula profesional', 'Frente y reverso.', true, 'academico', ['docente']],
            ['Currículum vitae', 'Actualizado, con documentos probatorios.', false, 'academico', ['docente']],
            ['RFC', 'Constancia de situación fiscal.', false, null, ['docente']],
        ];

        foreach ($documentos as [$nombre, $descripcion, $obligatorio, $etiqueta, $ambitos]) {
            $documento = DocumentoRequerido::query()->updateOrCreate(
                ['nombre' => $nombre],
                ['descripcion' => $descripcion, 'obligatorio' => $obligatorio],
            );

            if ($etiqueta !== null) {
                $documento->etiquetas()->syncWithoutDetaching([$etiquetas[$etiqueta]]);
            }

            // Los ámbitos se AGREGAN sin quitar los que la escuela haya puesto
            // a mano: sembrar no debe deshacer una decisión de la escuela.
            $documento->sincronizarAmbitos(array_values(array_unique(
                array_merge($documento->ambitos(), $ambitos)
            )));
        }
    }
}
