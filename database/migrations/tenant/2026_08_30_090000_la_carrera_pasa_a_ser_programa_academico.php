<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tres renombrados de concepto, en el esquema y en los datos.
 *
 *   - «programa_academico»    → «programa académico»
 *   - «recursos_digitales» → «recursos digitales»
 *   - «captación»  → «captación»  (SÓLO el CRM)
 *
 * ── Qué se renombra y qué NO ──────────────────────────────────────────────
 * Se renombran las tablas, las columnas y —esto es lo que hace que el cambio no
 * rompa nada— los VALORES GUARDADOS que son claves del sistema: el
 * discriminador de un destino, la clave de una columna en una vista de reporte,
 * la de un campo del gafete, la de una columna del historial.
 *
 * NO se toca lo que escribió la escuela o un alumno. `puestos.nombre` puede
 * decir «Coordinador de programa_academico» y es SU organigrama; el título de una
 * actividad puede decir «por qué elegí este programa académico» y es el trabajo de alguien.
 * Renombrar eso sería editarles su contenido.
 *
 * Tampoco se toca `migrations.migration`: es el registro de lo que ya corrió, y
 * reescribirlo haría que Laravel creyera que faltan migraciones por aplicar.
 *
 * ── Las llaves foráneas y los índices se conservan solos ──────────────────
 * MySQL actualiza las referencias al renombrar una tabla o una columna, así que
 * aquí NO aplica la trampa del índice compuesto que sostiene una foránea: no se
 * tira ningún índice. Lo que queda con el nombre viejo es el NOMBRE de cada
 * constraint (`oferta_carrera_id_foreign`), que es un identificador interno que
 * nadie consulta; renombrarlos exigiría tirarlos y recrearlos, que sí es la
 * operación peligrosa, a cambio de nada.
 *
 * ── Comprueba antes de actuar, pieza por pieza ────────────────────────────
 * Cada renombrado mira si hace falta. Un reintento tras un fallo parcial no
 * choca contra su propio trabajo — y aquí importa de verdad, porque son
 * catorce operaciones de esquema seguidas.
 */
return new class extends Migration
{
    /**
     * Tabla vieja => tabla nueva.
     *
     * OJO al editar este archivo con una herramienta de renombrado masivo: el
     * lado IZQUIERDO tiene que conservar el nombre viejo. Ya pasó una vez —el
     * renombrado convirtió `'programas_academicos' => 'programas_academicos'` en
     * `'programas_academicos' => 'programas_academicos'`— y la migración corrió
     * en 400 ms sin hacer absolutamente nada.
     */
    private const TABLAS = [
        'programas_academicos' => 'programas_academicos',
        'convenio_programas_academicos' => 'convenio_programas_academicos',
        'cuenta_bancaria_programa_academico' => 'cuenta_bancaria_programa_academico',
        'plan_cobro_programa_academico' => 'plan_cobro_programa_academico',
        'vacante_programas_academicos' => 'vacante_programas_academicos',
        'descuentos_admision' => 'descuentos_admision',
        'aspirante_descuento_admision' => 'aspirante_descuento_admision',
    ];

    /** Tabla (ya con su nombre NUEVO) => [columna vieja => columna nueva]. */
    private const COLUMNAS = [
        'convenio_programas_academicos' => ['programa_academico_id' => 'programa_academico_id'],
        'cuenta_bancaria_programa_academico' => ['programa_academico_id' => 'programa_academico_id'],
        'plan_cobro_programa_academico' => ['programa_academico_id' => 'programa_academico_id'],
        'vacante_programas_academicos' => ['programa_academico_id' => 'programa_academico_id'],
        'expediente_documentos' => ['programa_academico_id' => 'programa_academico_id'],
        'oferta' => ['programa_academico_id' => 'programa_academico_id'],
        'planes_estudio' => ['programa_academico_id' => 'programa_academico_id'],
        'colocaciones' => ['relacionado_con_programa_academico' => 'relacionado_con_programa_academico'],
        'titulo_modalidad' => ['fecha_terminacion_programa_academico' => 'fecha_terminacion_programa_academico'],
        'aspirante_descuento_admision' => ['descuento_admision_id' => 'descuento_admision_id'],
    ];

    /**
     * Discriminadores: columnas cuyo VALOR es la palabra, no un texto libre.
     *
     * [tabla, columna, valor viejo, valor nuevo]
     */
    private const DISCRIMINADORES = [
        ['evento_destinos', 'tipo', 'programa_academico', 'programa_academico'],
        ['avisos_destinos', 'tipo', 'programa_academico', 'programa_academico'],
        ['aplicacion_destinos', 'tipo', 'programa_academico', 'programa_academico'],
        ['formulario_asignacion', 'ambito_tipo', 'programa_academico', 'programa_academico'],
        ['emisor_asignaciones', 'ambito', 'programa_academico', 'programa_academico'],
        ['reglas_comision', 'ambito', 'programa_academico', 'programa_academico'],
        ['planes_cobro', 'ambito', 'programa_academico', 'programa_academico'],
        ['documentos_requeridos', 'ambito', 'programa_academico', 'programa_academico'],
    ];

    /**
     * Claves guardadas dentro de JSON.
     *
     * Son listas de claves de columna o de campo que la escuela configuró: qué
     * columnas trae un reporte, qué campos lleva el gafete, qué datos van en el
     * encabezado del historial. Si no se migran, la escuela abre su vista
     * guardada y le falta una columna, sin que nada falle.
     *
     * [tabla, columnas a tocar]
     */
    private const JSON_CON_CLAVES = [
        ['vistas_reporte', ['columnas', 'filtros', 'orden']],
        ['ejecuciones_reporte', ['columnas', 'filtros', 'orden']],
        ['programaciones_reporte', ['columnas', 'filtros', 'orden']],
        ['credenciales_rol', ['campos_anverso', 'campos_reverso']],
        ['disenos_historial', ['campos_alumno', 'campos_encabezado', 'columnas']],
        ['menus_rol', ['ocultos', 'orden']],
        ['tarjetas_rol', ['ocultas', 'orden']],
    ];

    /**
     * Tablas de OTROS dos renombrados que van en la misma migración.
     *
     * Ojo con «captación»: la palabra significa DOS cosas distintas en este
     * sistema. `App\Models\Captacion\*` es el CRM de captación, y eso sí se
     * renombra; pero `descuentos_admision`, `aspirante_descuento_admision` y `descuento_admision_id` son
     * los DESCUENTOS de admisión, que no tienen nada que ver y se quedan como
     * están. Un reemplazo ciego de la palabra rompería esa función.
     */
    private const TABLAS_MAS = [
        'recursos_digitales' => 'recursos_digitales',
    ];

    /** Permisos: su nombre vive en `permissions` y lo consulta el código. */
    private const PERMISOS = [
        'ver-recursos-digitales' => 'ver-recursos-digitales',
        'gestionar-recursos-digitales' => 'gestionar-recursos-digitales',
        'gestionar-captacion' => 'gestionar-captacion',
    ];

    /** Claves de módulo: lo que enciende o apaga una sección. */
    private const MODULOS = [
        'recursos_digitales' => 'recursos_digitales',
    ];

    /**
     * Claves de menú y de tarjeta guardadas por rol.
     *
     * Si no se migran, un rol que tenía escondida «RecursosDigitales» se queda con una
     * clave que ya no existe: la entrada reaparece y nadie sabe por qué.
     */
    private const CLAVES_GUARDADAS = [
        'programas_academicos' => 'programas-academicos',
        'recursos_digitales' => 'recursos-digitales',
        'recursos-digitales-alumno' => 'recursos-digitales-alumno',
        'recursos-digitales-admin' => 'recursos-digitales-admin',
        'recursos-digitales-listado' => 'recursos-digitales-listado',
        'captacion' => 'captacion',
    ];

    public function up(): void
    {
        foreach (self::TABLAS_MAS as $vieja => $nueva) {
            if (Schema::hasTable($vieja) && ! Schema::hasTable($nueva)) {
                Schema::rename($vieja, $nueva);
            }
        }

        foreach (self::PERMISOS as $viejo => $nuevo) {
            if (Schema::hasTable('permissions')) {
                DB::table('permissions')->where('name', $viejo)->update(['name' => $nuevo]);
            }
        }

        foreach (self::MODULOS as $vieja => $nueva) {
            if (Schema::hasTable('modulos')) {
                DB::table('modulos')->where('clave', $vieja)->update(['clave' => $nueva]);
            }
        }

        $this->clavesGuardadas(self::CLAVES_GUARDADAS);

        /*
         * El origen «Personal de captación» nombra al mismo equipo que ahora se
         * llama captación, así que se renombra con él.
         *
         * Ojo: es la TERCERA cosa distinta que se llamaba «captación» en este
         * sistema —el CRM, los descuentos de admisión, y este origen—. Se
         * cambia la clave y el nombre, y nada apunta a la clave: los aspirantes
         * llegan por `origen_id`.
         */
        /*
         * El formato de la matrícula y las dimensiones de su consecutivo.
         *
         * `{CARRERA}` es un token que la escuela escribió en su plantilla, y
         * `programa_academico` una dimensión guardada del contador. Sin migrarlos, la
         * siguiente matrícula sale con el token sin sustituir y el consecutivo
         * arranca de cero por una dimensión que ya no existe.
         */
        if (Schema::hasTable('reglas_matricula')) {
            DB::table('reglas_matricula')
                ->where('plantilla', 'like', '%{CARRERA%')
                ->update(['plantilla' => DB::raw("replace(`plantilla`, '{CARRERA', '{PROGRAMA')")]);

            DB::table('reglas_matricula')
                ->where('consecutivo_dimensiones', 'like', '%"programa_academico"%')
                ->update(['consecutivo_dimensiones' => DB::raw(
                    'replace(`consecutivo_dimensiones`, '.DB::getPdo()->quote('"programa_academico"').', '.DB::getPdo()->quote('"programa_academico"').')'
                )]);
        }

        if (Schema::hasTable('origenes_aspirante')) {
            DB::table('origenes_aspirante')
                ->where('clave', 'captacion')
                ->update(['clave' => 'captacion', 'nombre' => 'Personal de captación']);
        }

        foreach (self::TABLAS as $vieja => $nueva) {
            if (Schema::hasTable($vieja) && ! Schema::hasTable($nueva)) {
                Schema::rename($vieja, $nueva);
            }
        }

        foreach (self::COLUMNAS as $tabla => $columnas) {
            if (! Schema::hasTable($tabla)) {
                continue;
            }

            foreach ($columnas as $vieja => $nueva) {
                if (Schema::hasColumn($tabla, $vieja) && ! Schema::hasColumn($tabla, $nueva)) {
                    Schema::table($tabla, fn ($t) => $t->renameColumn($vieja, $nueva));
                }
            }
        }

        foreach (self::DISCRIMINADORES as [$tabla, $columna, $viejo, $nuevo]) {
            if (Schema::hasTable($tabla) && Schema::hasColumn($tabla, $columna)) {
                DB::table($tabla)->where($columna, $viejo)->update([$columna => $nuevo]);
            }
        }

        foreach (self::JSON_CON_CLAVES as [$tabla, $columnas]) {
            if (! Schema::hasTable($tabla)) {
                continue;
            }

            foreach ($columnas as $columna) {
                if (! Schema::hasColumn($tabla, $columna)) {
                    continue;
                }

                /*
                 * Se reemplaza la palabra ENTRECOMILLADA, no la palabra suelta.
                 * Así se cambia `"programa_academico"` —que es una clave— y no una frase
                 * que casualmente diga programa_academico dentro de un texto guardado en
                 * el mismo JSON.
                 */
                foreach ([
                    ['"programa_academico"', '"programa_academico"'],
                    ['"programas_academicos"', '"programas_academicos"'],
                    ['"programa_academico_id"', '"programa_academico_id"'],
                ] as [$viejo, $nuevo]) {
                    DB::table($tabla)
                        ->where($columna, 'like', '%'.$viejo.'%')
                        ->update([$columna => DB::raw("replace(`{$columna}`, ".DB::getPdo()->quote($viejo).', '.DB::getPdo()->quote($nuevo).')')]);
                }
            }
        }
    }

    /**
     * Reemplaza claves DENTRO de los JSON de menú y panel por rol.
     *
     * Se busca la clave entrecomillada y no la palabra suelta: así se cambia
     * `"recursos_digitales"` —que es una clave— y no un texto que la mencione.
     *
     * @param  array<string, string>  $mapa
     */
    private function clavesGuardadas(array $mapa): void
    {
        foreach ([['menus_rol', ['estructura', 'ocultos']], ['tarjetas_rol', ['activas']]] as [$tabla, $columnas]) {
            if (! Schema::hasTable($tabla)) {
                continue;
            }

            foreach ($columnas as $columna) {
                if (! Schema::hasColumn($tabla, $columna)) {
                    continue;
                }

                foreach ($mapa as $viejo => $nuevo) {
                    DB::table($tabla)
                        ->where($columna, 'like', '%"'.$viejo.'"%')
                        ->update([$columna => DB::raw(
                            "replace(`{$columna}`, ".DB::getPdo()->quote('"'.$viejo.'"').', '.DB::getPdo()->quote('"'.$nuevo.'"').')'
                        )]);
                }
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('origenes_aspirante')) {
            DB::table('origenes_aspirante')
                ->where('clave', 'captacion')
                ->update(['clave' => 'captacion', 'nombre' => 'Personal de captación']);
        }

        $this->clavesGuardadas(array_flip(self::CLAVES_GUARDADAS));

        foreach (self::MODULOS as $vieja => $nueva) {
            if (Schema::hasTable('modulos')) {
                DB::table('modulos')->where('clave', $nueva)->update(['clave' => $vieja]);
            }
        }

        foreach (self::PERMISOS as $viejo => $nuevo) {
            if (Schema::hasTable('permissions')) {
                DB::table('permissions')->where('name', $nuevo)->update(['name' => $viejo]);
            }
        }

        foreach (self::TABLAS_MAS as $vieja => $nueva) {
            if (Schema::hasTable($nueva) && ! Schema::hasTable($vieja)) {
                Schema::rename($nueva, $vieja);
            }
        }

        foreach (self::JSON_CON_CLAVES as [$tabla, $columnas]) {
            if (! Schema::hasTable($tabla)) {
                continue;
            }

            foreach ($columnas as $columna) {
                if (! Schema::hasColumn($tabla, $columna)) {
                    continue;
                }

                foreach ([
                    ['"programa_academico_id"', '"programa_academico_id"'],
                    ['"programas_academicos"', '"programas_academicos"'],
                    ['"programa_academico"', '"programa_academico"'],
                ] as [$viejo, $nuevo]) {
                    DB::table($tabla)
                        ->where($columna, 'like', '%'.$viejo.'%')
                        ->update([$columna => DB::raw("replace(`{$columna}`, ".DB::getPdo()->quote($viejo).', '.DB::getPdo()->quote($nuevo).')')]);
                }
            }
        }

        foreach (self::DISCRIMINADORES as [$tabla, $columna, $viejo, $nuevo]) {
            if (Schema::hasTable($tabla) && Schema::hasColumn($tabla, $columna)) {
                DB::table($tabla)->where($columna, $nuevo)->update([$columna => $viejo]);
            }
        }

        foreach (self::COLUMNAS as $tabla => $columnas) {
            if (! Schema::hasTable($tabla)) {
                continue;
            }

            foreach ($columnas as $vieja => $nueva) {
                if (Schema::hasColumn($tabla, $nueva) && ! Schema::hasColumn($tabla, $vieja)) {
                    Schema::table($tabla, fn ($t) => $t->renameColumn($nueva, $vieja));
                }
            }
        }

        foreach (array_reverse(self::TABLAS, true) as $vieja => $nueva) {
            if (Schema::hasTable($nueva) && ! Schema::hasTable($vieja)) {
                Schema::rename($nueva, $vieja);
            }
        }
    }
};
