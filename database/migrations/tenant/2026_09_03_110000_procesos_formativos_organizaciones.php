<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2: con quién se puede hacer un proceso formativo.
 *
 * El padrón de organizaciones receptoras, sus contactos, hasta dónde alcanza
 * cada una, sus convenios y las plazas que ofrecen.
 *
 * ── Padrón propio, y NO `empresas` ─────────────────────────────────────────
 * `empresas` es el padrón de EMPLEADORES de la bolsa de trabajo: vive detrás
 * del módulo `bolsa_trabajo` —una escuela que lo apague se quedaría sin sus
 * organizaciones receptoras— y su situación incluye «vetada», que es un
 * concepto de contratación. Una receptora de servicio social suele ser una
 * dependencia de gobierno, un hospital, una escuela o una asociación civil, que
 * no son empleadores de ese padrón. Es el mismo argumento que este proyecto ya
 * escribió para los convenios de descuento.
 *
 * Lo que SÍ se copia es la FORMA de `convenios` de Movilidad: fechas,
 * situación, documento privado y —lo importante— vencido ≠ suspendido.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->situacionesDeConvenio();
        $this->organizaciones();
        $this->contactos();
        $this->alcances();
        $this->convenios();
        $this->plazas();
    }

    public function down(): void
    {
        // De la más dependiente a la menos.
        Schema::dropIfExists('plaza_programas');
        Schema::dropIfExists('plazas_proceso');
        Schema::dropIfExists('convenios_formativos');
        Schema::dropIfExists('organizacion_alcances');
        Schema::dropIfExists('organizacion_contactos');
        Schema::dropIfExists('organizaciones_receptoras');
        Schema::dropIfExists('situaciones_convenio_formativo');
    }

    /**
     * El catálogo que faltaba: en qué punto está un convenio.
     *
     * `ampara_asignaciones` es la bandera, y no la clave: una escuela que
     * agregue «en renovación» decide ella misma si en ese punto se le puede
     * seguir mandando gente. Preguntar por `clave === 'vigente'` se equivoca
     * justo ahí.
     */
    private function situacionesDeConvenio(): void
    {
        if (Schema::hasTable('situaciones_convenio_formativo')) {
            return;
        }

        Schema::create('situaciones_convenio_formativo', function (Blueprint $t) {
            $t->id();
            $t->string('clave', 50)->unique();
            $t->string('nombre', 150);
            $t->string('descripcion', 255)->nullable();
            $t->boolean('ampara_asignaciones')->default(true);
            $t->unsignedSmallInteger('orden')->default(0);
            $t->boolean('activo')->default(true);
            $t->auditoria();
        });
    }

    private function organizaciones(): void
    {
        if (Schema::hasTable('organizaciones_receptoras')) {
            return;
        }

        Schema::create('organizaciones_receptoras', function (Blueprint $t) {
            $t->id();
            $t->string('razon_social', 255);

            /*
             * Con qué nombre la conoce todo el mundo.
             *
             * La razón social de un hospital es «Servicios de Salud del Estado
             * de…» y en la calle es «Hospital General». En la constancia va la
             * razón social; en el buscador hace falta la otra, o quien captura
             * no encuentra la que ya está dada de alta y la vuelve a crear.
             */
            $t->string('nombre_comercial', 255)->nullable();

            /*
             * Opcional pero ÚNICO.
             *
             * Una escuela da de alta receptoras que le llaman antes de tener un
             * papel suyo; pero la misma capturada dos veces reparte sus
             * expedientes entre los duplicados y ningún reporte cuadra. MySQL
             * admite varios NULL en un único, que es justo lo que hace falta.
             */
            $t->string('rfc', 13)->nullable()->unique();

            $t->foreignId('sector_id')->nullable()->constrained('sectores_organizacion')->nullOnDelete();
            $t->foreignId('tipo_id')->nullable()->constrained('tipos_organizacion')->nullOnDelete();
            $t->foreignId('situacion_id')->constrained('situaciones_organizacion');

            // Domicilio en texto: no se normaliza porque no se consulta por él.
            // Normalizarlo pediría catálogos de colonia y municipio para nada.
            $t->string('calle', 255)->nullable();
            $t->string('colonia', 150)->nullable();
            $t->string('municipio', 150)->nullable();
            $t->unsignedBigInteger('entidad_federativa_id')->nullable();
            $t->string('codigo_postal', 10)->nullable();

            $t->string('representante', 255)->nullable();
            $t->string('sitio_web', 255)->nullable();
            $t->string('telefono', 30)->nullable();
            $t->string('correo', 150)->nullable();

            /*
             * Cuánta gente admite EN TOTAL, si la organización lo declara.
             *
             * Nullable a propósito: casi ninguna lo dice, y un cero significaría
             * «no recibe a nadie», que es una afirmación distinta de «no nos lo
             * han dicho». El cupo que de verdad se controla es el de cada PLAZA.
             */
            $t->unsignedInteger('cupo_total')->nullable();

            $t->string('notas', 1000)->nullable();
            $t->auditoria();

            $t->index(['situacion_id', 'razon_social']);
        });
    }

    /**
     * Con quién se habla, en UN solo lugar.
     *
     * Y no un `persona_contacto_id` en la organización más una tabla aparte:
     * serían dos sitios donde buscar al mismo responsable y la duda de si el
     * principal aparece también en la tabla. Es la lección que dejó el padrón
     * de empleadores de la bolsa.
     */
    private function contactos(): void
    {
        if (Schema::hasTable('organizacion_contactos')) {
            return;
        }

        Schema::create('organizacion_contactos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organizacion_id')->constrained('organizaciones_receptoras')->cascadeOnDelete();
            $t->string('nombre', 255);
            $t->string('cargo', 150)->nullable();
            $t->string('correo', 150)->nullable();
            $t->string('telefono', 30)->nullable();

            $t->boolean('es_principal')->default(false);

            /*
             * Si además SUPERVISA alumnos.
             *
             * Es otra pregunta que ser el contacto: quien firma el convenio en
             * una dependencia rara vez es quien está al lado del practicante
             * todos los días, y el expediente apunta al segundo.
             */
            $t->boolean('es_supervisor')->default(false);

            /*
             * Su cuenta en Acadion, SI la tiene.
             *
             * Nullable y sin obligar: exigir que el supervisor externo sea una
             * `persona` llenaría el padrón de la escuela con gente que ni
             * estudia ni trabaja ahí. El portal del supervisor llega en la fase
             * 8 y es esta columna la que lo hará posible sin cambiar nada más.
             */
            $t->foreignId('persona_id')->nullable()->constrained('personas')->nullOnDelete();

            $t->auditoria();

            $t->index(['organizacion_id', 'es_principal']);
        });
    }

    /**
     * Hasta dónde alcanza una organización.
     *
     * ── Sin filas, alcanza a TODO ──────────────────────────────────────────
     * La mayoría de las receptoras sirven para cualquier programa y cualquier
     * campus, y exigir al menos una fila obligaría a palomear veinte programas
     * cada vez. Es la misma decisión que los convenios de movilidad y que las
     * vacantes de la bolsa: un hueco se lee como captura incompleta, así que la
     * pantalla lo dice con palabras.
     *
     * Las tres columnas son nullable a la vez porque las tres acotan por
     * separado: «prácticas de Enfermería» (tipo + programa, cualquier campus) y
     * «lo que sea del campus Norte» (sólo campus) son alcances legítimos.
     */
    private function alcances(): void
    {
        if (Schema::hasTable('organizacion_alcances')) {
            return;
        }

        Schema::create('organizacion_alcances', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organizacion_id')->constrained('organizaciones_receptoras')->cascadeOnDelete();
            $t->foreignId('campus_id')->nullable()->constrained('campus')->cascadeOnDelete();
            $t->foreignId('programa_academico_id')->nullable()->constrained('programas_academicos')->cascadeOnDelete();
            $t->foreignId('tipo_proceso_id')->nullable()->constrained('tipos_proceso_formativo')->cascadeOnDelete();
            $t->auditoria();

            $t->index(['organizacion_id']);
        });
    }

    /**
     * El acuerdo firmado, VERSIONADO.
     *
     * ── Renovar CREA otra fila; la vieja no se edita ───────────────────────
     * Un convenio es un papel fechado: cambiarle las fechas al renovarlo
     * borraría bajo qué acuerdo estuvo cada alumno que ya pasó por ahí. La
     * nueva apunta a la anterior con `convenio_anterior_id`, y las dos se
     * conservan. Mismo criterio que el acta de corrección y que la nota de
     * crédito.
     *
     * `version` se escribe UNA vez al crear —anterior + 1— y no se recalcula:
     * la cadena es inmutable, así que no puede divergir, y sin ella pintar «v3»
     * en un listado de doscientos convenios exigiría recorrer la cadena por
     * cada renglón.
     */
    private function convenios(): void
    {
        if (Schema::hasTable('convenios_formativos')) {
            return;
        }

        Schema::create('convenios_formativos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organizacion_id')->constrained('organizaciones_receptoras')->cascadeOnDelete();
            $t->foreignId('tipo_convenio_id')->nullable()->constrained('tipos_convenio_formativo')->nullOnDelete();
            $t->string('folio', 100);
            $t->unsignedSmallInteger('version')->default(1);
            $t->foreignId('convenio_anterior_id')->nullable()->constrained('convenios_formativos')->nullOnDelete();

            $t->date('vigente_desde');

            /*
             * Nullable: hay convenios marco sin fecha de término.
             *
             * Y `estaVencido()` sale de aquí mientras que la SITUACIÓN dice si
             * está suspendido o en firma. Son dos preguntas: sin la fecha, un
             * convenio caducado seguiría amparando asignaciones nuevas; sin la
             * situación, uno suspendido a mitad de su vigencia las seguiría
             * amparando también.
             */
            $t->date('vigente_hasta')->nullable();

            $t->foreignId('situacion_id')->constrained('situaciones_convenio_formativo');

            // Al disco PRIVADO. Un convenio trae nombres, firmas y a veces
            // domicilios: nunca a `public/`.
            $t->string('documento_ruta', 500)->nullable();

            $t->string('notas', 1000)->nullable();
            $t->auditoria();

            /*
             * El folio no se repite DENTRO de una organización y de una
             * versión. Entre organizaciones sí puede: cada una numera como
             * quiere, y un único global obligaría a inventar prefijos.
             */
            $t->unique(['organizacion_id', 'folio', 'version'], 'convenio_folio_version_unico');

            // Lo consulta la alerta de vencimiento, que barre por fecha.
            $t->index(['vigente_hasta']);
        });
    }

    /**
     * Lo que una organización ofrece.
     *
     * ── El CUPO se protege con la BASE, no con un `SELECT` previo ──────────
     * Dos alumnos aceptando la última plaza a la vez pasan los dos un conteo
     * hecho antes de escribir. La asignación (fase 4) toma `lockForUpdate()`
     * sobre la plaza dentro de su transacción, y aquí abajo el CHECK es la red
     * que impide el estado imposible aunque alguien escriba por otro camino.
     * Es la lección del apartado de licencia de las clases en línea.
     */
    private function plazas(): void
    {
        if (! Schema::hasTable('plazas_proceso')) {
            Schema::create('plazas_proceso', function (Blueprint $t) {
                $t->id();
                $t->foreignId('organizacion_id')->constrained('organizaciones_receptoras')->cascadeOnDelete();
                $t->foreignId('tipo_proceso_id')->constrained('tipos_proceso_formativo');
                $t->foreignId('modalidad_id')->nullable()->constrained('modalidades_proceso')->nullOnDelete();

                $t->string('nombre', 255);
                $t->text('descripcion')->nullable();
                $t->text('actividades')->nullable();
                $t->string('ubicacion', 255)->nullable();
                $t->string('horario', 255)->nullable();

                $t->unsignedInteger('cupo')->default(1);
                $t->unsignedInteger('cupo_ocupado')->default(0);

                $t->date('fecha_inicio')->nullable();
                $t->date('fecha_cierre')->nullable();
                $t->unsignedInteger('duracion_estimada_horas')->nullable();

                /*
                 * Nullable y NO cero: «no da apoyo» y «no nos lo han dicho» son
                 * afirmaciones distintas, y un cero por omisión convertiría la
                 * segunda en la primera en la pantalla del alumno.
                 */
                $t->decimal('apoyo_economico', 10, 2)->nullable();

                $t->text('requisitos')->nullable();
                $t->string('responsable', 255)->nullable();
                $t->boolean('abierta')->default(true);
                $t->auditoria();

                $t->index(['organizacion_id', 'abierta']);
                $t->index(['tipo_proceso_id', 'abierta']);
            });

            /*
             * El CHECK va aparte del `Schema::create` y con su propia
             * comprobación: una migración que puede fallar a la mitad comprueba
             * ANTES DE ACTUAR y por PIEZA, no por bloque. Es la lección del
             * CHECK de movilidad, que quedó sin crear para siempre porque vivía
             * dentro del `if (! hasTable)`.
             */
        }

        $tieneCheck = collect(\Illuminate\Support\Facades\DB::select(
            "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plazas_proceso'
               AND CONSTRAINT_TYPE = 'CHECK' AND CONSTRAINT_NAME = 'plaza_cupo_no_rebasado'"
        ))->isNotEmpty();

        if (! $tieneCheck) {
            \Illuminate\Support\Facades\DB::statement(
                'ALTER TABLE plazas_proceso ADD CONSTRAINT plaza_cupo_no_rebasado CHECK (cupo_ocupado <= cupo)'
            );
        }

        if (Schema::hasTable('plaza_programas')) {
            return;
        }

        /*
         * A qué programas se ofrece. Sin filas, a todos — misma regla que el
         * alcance de la organización y que las vacantes de la bolsa.
         */
        Schema::create('plaza_programas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('plaza_id')->constrained('plazas_proceso')->cascadeOnDelete();
            $t->foreignId('programa_academico_id')->constrained('programas_academicos')->cascadeOnDelete();
            $t->auditoria();

            $t->index(['plaza_id']);
        });
    }
};
