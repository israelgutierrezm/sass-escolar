<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogos oficiales SEP que alimentan el título electrónico y que aún no
 * existían en el sistema:
 *
 *  - modalidades_titulacion            → Expedicion/@idModalidadTitulacion
 *  - fundamentos_legales_servicio_social → Expedicion/@idFundamentoLegalServicioSocial
 *
 * Cada uno lleva un `identificador` = el id OFICIAL de la SEP (no el id interno
 * de la fila), que es lo que se escribe en el XML. Quedan `protegido` (oficiales,
 * no editables desde Catálogos).
 *
 * Además, `niveles_estudio` ya trae su `identificador` para CERTIFICACIÓN
 * (idNivelEstudios 81–95). El título usa OTRO catálogo para el antecedente
 * (idTipoEstudioAntecedente 1–6), que NO coincide con el de certificación, así
 * que se agrega una columna independiente `identificador_titulo`. Se siembra el
 * mapeo de los niveles que sí tienen equivalente; el resto queda nulo hasta que
 * se construyan los formularios del antecedente.
 */
return new class extends Migration
{
    private const MODALIDADES = [
        [1, 'POR TESIS', 'Acta de examen'],
        [2, 'POR PROMEDIO', 'Constancia de exención'],
        [3, 'POR ESTUDIOS DE POSGRADO', 'Constancia de exención'],
        [4, 'POR EXPERIENCIA LABORAL', 'Constancia de exención'],
        [5, 'POR CENEVAL', 'Constancia de exención'],
        [6, 'OTRO', 'Constancia de exención'],
    ];

    private const FUNDAMENTOS = [
        [1, 'ART. 52 LRART. 5 CONST'],
        [2, 'ART. 55 LRART. 5 CONST'],
        [3, 'ART. 91 RLRART. 5 CONST'],
        [4, 'ART. 10 REGLAMENTO PARA LA PRESTACIÓN DEL SERVICIO SOCIAL DE LOS ESTUDIANTES DE LAS INSTITUCIONES DE EDUCACIÓN SUPERIOR EN LA REPÚBLICA MEXICANA'],
        [5, 'NO APLICA'],
    ];

    /**
     * nombre normalizado del nivel → idTipoEstudioAntecedente del título (1–6).
     * Se mapea por nombre y no por clave porque al alinear a la SEP los niveles
     * oficiales quedaron con clave numérica (81, 82…). Los niveles inferiores
     * del catálogo de antecedentes (equivalente a bachillerato, secundaria) no
     * existen en `niveles_estudio`, así que no se mapean.
     */
    private const NIVEL_A_ANTECEDENTE = [
        'MAESTRIA' => 1,
        'LICENCIATURA' => 2,
        'TECNICO SUPERIOR UNIVERSITARIO' => 3,
        'BACHILLERATO' => 4,
    ];

    public function up(): void
    {
        Schema::create('modalidades_titulacion', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('identificador')->index(); // idModalidadTitulacion SEP
            $table->string('descripcion', 160);
            $table->string('tipo_modalidad', 60)->nullable(); // Acta de examen | Constancia de exención
            $table->boolean('protegido')->default(false);
            $table->boolean('activo')->default(true);
            $table->auditoria();
        });

        Schema::create('fundamentos_legales_servicio_social', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('identificador')->index(); // idFundamentoLegalServicioSocial SEP
            $table->string('descripcion', 400);
            $table->boolean('protegido')->default(false);
            $table->boolean('activo')->default(true);
            $table->auditoria();
        });

        if (! Schema::hasColumn('niveles_estudio', 'identificador_titulo')) {
            Schema::table('niveles_estudio', function (Blueprint $table) {
                // idTipoEstudioAntecedente del título; independiente del de
                // certificación (`identificador` / id de fila).
                $table->unsignedInteger('identificador_titulo')->nullable()->after('identificador');
            });
        }

        $ahora = now();

        foreach (self::MODALIDADES as [$id, $descripcion, $tipo]) {
            DB::table('modalidades_titulacion')->insert([
                'identificador' => $id,
                'descripcion' => $descripcion,
                'tipo_modalidad' => $tipo,
                'protegido' => true,
                'activo' => true,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ]);
        }

        foreach (self::FUNDAMENTOS as [$id, $descripcion]) {
            DB::table('fundamentos_legales_servicio_social')->insert([
                'identificador' => $id,
                'descripcion' => $descripcion,
                'protegido' => true,
                'activo' => true,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ]);
        }

        $sinAcentos = fn (string $s): string => mb_strtoupper(strtr($s, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U',
        ]));

        foreach (DB::table('niveles_estudio')->get(['id', 'nombre']) as $nivel) {
            $normal = $sinAcentos((string) $nivel->nombre);
            if (isset(self::NIVEL_A_ANTECEDENTE[$normal])) {
                DB::table('niveles_estudio')->where('id', $nivel->id)
                    ->update(['identificador_titulo' => self::NIVEL_A_ANTECEDENTE[$normal]]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('niveles_estudio', 'identificador_titulo')) {
            Schema::table('niveles_estudio', fn (Blueprint $table) => $table->dropColumn('identificador_titulo'));
        }
        Schema::dropIfExists('fundamentos_legales_servicio_social');
        Schema::dropIfExists('modalidades_titulacion');
    }
};
