<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Deja los catálogos en caso LEGIBLE (mayúsculas solo las necesarias) para que los
 * desplegables no se vean feos en TODO MAYÚSCULAS. El XML del título/certificado
 * los pasa a mayúsculas al generarse (ver Constructor*Xml::mayus), así que el
 * documento oficial sigue en mayúsculas con acentos.
 *
 * Se corrigen además acentos/ortografía. Los ids/identificadores NO cambian.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Modalidad de titulación (por identificador SEP).
        foreach ([
            1 => ['Por tesis', 'Acta de examen'],
            2 => ['Por promedio', 'Constancia de exención'],
            3 => ['Por estudios de posgrados', 'Constancia de exención'],
            4 => ['Por experiencia laboral', 'Constancia de exención'],
            5 => ['Por CENEVAL', 'Constancia de exención'],
            6 => ['Otro', 'Constancia de exención'],
        ] as $id => [$desc, $tipo]) {
            DB::table('modalidades_titulacion')->where('identificador', $id)->update(['descripcion' => $desc, 'tipo_modalidad' => $tipo]);
        }

        // Fundamento legal del servicio social.
        foreach ([
            1 => 'Art. 52 LRART. 5 Const',
            2 => 'Art. 55 LRART. 5 Const',
            3 => 'Art. 91 RLRART. 5 Const',
            4 => 'Art. 10 Reglamento para la Prestación del Servicio Social de los Estudiantes de las Instituciones de Educación Superior en la República Mexicana',
            5 => 'No aplica',
        ] as $id => $desc) {
            DB::table('fundamentos_legales_servicio_social')->where('identificador', $id)->update(['descripcion' => $desc]);
        }

        // Autorización o reconocimiento (id de fila = id SEP).
        foreach ([
            1 => 'RVOE federal', 2 => 'RVOE estatal', 3 => 'Autorización federal',
            4 => 'Autorización estatal', 5 => 'Acta de sesión', 6 => 'Acuerdo de incorporación',
            7 => 'Acuerdo secretarial SEP', 8 => 'Decreto de creación', 9 => 'Otro',
        ] as $id => $nombre) {
            DB::table('autorizaciones_reconocimiento')->where('id', $id)->update(['nombre' => $nombre]);
        }

        // Cargos firmantes (por identificador SEP 0–11).
        foreach ([
            0 => 'Secretario de Educación Pública', 1 => 'Director', 2 => 'Subdirector',
            3 => 'Rector', 4 => 'Vicerrector', 5 => 'Responsable de expedición',
            6 => 'Secretario general', 7 => 'Autoridad local', 8 => 'Autoridad federal',
            9 => 'Director general', 10 => 'Rector general',
            11 => 'Titular de la Autoridad Educativa Federal en la Ciudad de México',
        ] as $id => $nombre) {
            DB::table('cargos')->where('identificador', (string) $id)->update(['nombre' => $nombre]);
        }

        // Niveles de estudio (los alineados a la SEP quedaron en mayúsculas).
        foreach ([
            'LICENCIATURA' => 'Licenciatura',
            'MAESTRÍA' => 'Maestría',
            'TÉCNICO SUPERIOR UNIVERSITARIO' => 'Técnico Superior Universitario',
            'PROFESIONAL ASOCIADO' => 'Profesional asociado',
            'ESPECIALIDAD' => 'Especialidad',
            'DOCTORADO' => 'Doctorado',
            'SECUNDARIA' => 'Secundaria',
            'EQUIVALENTE A BACHILLERATO' => 'Equivalente a bachillerato',
        ] as $may => $legible) {
            DB::table('niveles_estudio')->where('nombre', $may)->update(['nombre' => $legible]);
        }
    }

    public function down(): void
    {
        // No se revierte: el caso legible es la forma correcta de mostrarlos.
    }
};
