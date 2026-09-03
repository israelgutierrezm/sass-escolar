<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 3: qué exige cada programa, y con versión histórica.
 *
 * ── Por qué es una TABLA y no `CatalogoAjustes` ────────────────────────────
 * Un ajuste es de la escuela entera y tiene UN valor. Aquí hace falta un valor
 * por combinación de campus, nivel, programa, plan, modalidad y generación —un
 * tecnológico exige 500 horas de residencia con 80 % de créditos, una normal
 * exige dos años de servicio social— y hace falta conservar el histórico. Un
 * ajuste no puede hacer ninguna de las dos cosas.
 *
 * ── El ALCANCE y el CONTENIDO van en tablas distintas ──────────────────────
 * `reglas_proceso` dice A QUIÉN aplica; `reglas_proceso_versiones` dice QUÉ
 * exige. Separarlas es lo que permite cambiar el requisito sin volver a
 * declarar a quién alcanza — y, sobre todo, conservar lo que decía antes.
 *
 * ── Y la versión se CONGELA en el expediente ───────────────────────────────
 * `expedientes_proceso.regla_version_id` (fase 4) se escribe al abrirlo y no se
 * vuelve a mirar: cambiar la configuración mañana NO altera un expediente en
 * curso ni uno liberado. Mismo criterio que `esquema_evaluacion` materializado,
 * el emisor congelado en la factura y `factura_iedu`.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->reglas();
        $this->versiones();
        $this->documentos();
        $this->materiasPrevias();
        $this->situacionesPermitidas();
    }

    public function down(): void
    {
        Schema::dropIfExists('regla_situaciones_permitidas');
        Schema::dropIfExists('regla_materias_previas');
        Schema::dropIfExists('regla_documentos');
        Schema::dropIfExists('reglas_proceso_versiones');
        Schema::dropIfExists('reglas_proceso');
    }

    /**
     * El ALCANCE: a quién aplica esta regla.
     *
     * Todos los ejes menos el tipo son nullable, y null significa «cualquiera».
     * Gana la más específica, con la jerarquía escrita en `ResolutorDeRegla`:
     * plan → programa → nivel → campus → generación → modalidad.
     */
    private function reglas(): void
    {
        if (Schema::hasTable('reglas_proceso')) {
            return;
        }

        Schema::create('reglas_proceso', function (Blueprint $t) {
            $t->id();
            $t->string('nombre', 150);

            // Una regla SIEMPRE es de un tipo: «lo que exige el servicio
            // social» y «lo que exigen las prácticas» no se mezclan.
            $t->foreignId('tipo_proceso_id')->constrained('tipos_proceso_formativo');

            $t->foreignId('campus_id')->nullable()->constrained('campus')->cascadeOnDelete();
            $t->foreignId('nivel_estudios_id')->nullable()->constrained('niveles_estudio')->cascadeOnDelete();
            $t->foreignId('programa_academico_id')->nullable()->constrained('programas_academicos')->cascadeOnDelete();
            $t->foreignId('plan_id')->nullable()->constrained('planes_estudio')->cascadeOnDelete();
            $t->string('modalidad', 30)->nullable();

            /*
             * La generación se compara como TEXTO.
             *
             * `matricula_oferta.generacion` es un `varchar` y en la práctica es
             * «2024» o «2024-A». Con años de cuatro dígitos el orden de texto y
             * el numérico coinciden; con sufijos también, porque el año va
             * delante. Se dice aquí para que nadie lo tome por un entero.
             */
            $t->string('generacion_desde', 100)->nullable();
            $t->string('generacion_hasta', 100)->nullable();

            $t->boolean('activa')->default(true);
            $t->string('notas', 1000)->nullable();
            $t->auditoria();

            $t->index(['tipo_proceso_id', 'activa']);
        });
    }

    /**
     * El CONTENIDO, versionado.
     *
     * Cada cambio de requisito crea una versión nueva; la anterior se conserva
     * porque hay expedientes que la citan.
     */
    private function versiones(): void
    {
        if (Schema::hasTable('reglas_proceso_versiones')) {
            return;
        }

        Schema::create('reglas_proceso_versiones', function (Blueprint $t) {
            $t->id();
            $t->foreignId('regla_id')->constrained('reglas_proceso')->cascadeOnDelete();
            $t->unsignedSmallInteger('version')->default(1);
            $t->date('vigente_desde');

            /*
             * Obligatorio u optativo PARA ESE PROGRAMA.
             *
             * No es lo mismo que «existe»: una escuela puede ofrecer prácticas
             * optativas en una licenciatura y obligatorias en otra, y el
             * requisito de titulación depende de esto.
             */
            $t->boolean('obligatorio')->default(true);

            $t->unsignedInteger('horas_requeridas')->nullable();

            /*
             * Cuántas horas de menos se toleran al liberar.
             *
             * Existe porque 480 horas exactas casi nunca salen: la bitácora
             * cierra en 478 y la escuela no va a mandar a alguien dos horas
             * más. Sin esto, la tolerancia se aplicaría a ojo y sin registro.
             */
            $t->unsignedInteger('tolerancia_horas')->default(0);

            $t->decimal('porcentaje_creditos_minimo', 5, 2)->nullable();
            $t->unsignedSmallInteger('periodo_minimo')->nullable();

            /*
             * La ventana en que se puede SOLICITAR.
             *
             * Va en la versión y no en el alcance: es una regla del proceso, y
             * mover la ventana de un año al siguiente crea una versión — que es
             * lo correcto, porque deja escrito cuál fue la ventana de cada año.
             * Nullable las dos = siempre abierta.
             */
            $t->date('solicitud_desde')->nullable();
            $t->date('solicitud_hasta')->nullable();

            $t->unsignedSmallInteger('plazo_maximo_dias')->nullable();
            $t->unsignedSmallInteger('max_horas_dia')->nullable();
            $t->unsignedSmallInteger('max_horas_semana')->nullable();

            $t->boolean('exige_seguro')->default(false);
            $t->boolean('exige_convenio_vigente')->default(false);
            $t->boolean('exige_no_adeudo')->default(false);
            $t->boolean('exige_aprobacion_coordinador')->default(true);

            $t->unsignedSmallInteger('informes_parciales')->default(0);
            $t->unsignedSmallInteger('periodicidad_informe_dias')->nullable();
            $t->boolean('exige_informe_final')->default(true);
            $t->boolean('exige_evaluacion_supervisor')->default(true);
            $t->boolean('exige_evaluacion_estudiante')->default(false);

            $t->boolean('exige_carta_aceptacion')->default(false);
            $t->boolean('exige_carta_termino')->default(false);
            $t->boolean('emite_constancia')->default(true);

            /*
             * Si este proceso cuenta para titularse.
             *
             * Lo LEE la interfaz `RequisitoFormativo` (fase 6), que titulación
             * podrá consultar sin duplicar lógica. Aquí llega con su lector
             * previsto y sin cablear nada del flujo de titulación, que es lo
             * que el pedido exige.
             */
            $t->boolean('cuenta_para_titulacion')->default(true);

            $t->string('notas', 1000)->nullable();
            $t->auditoria();

            $t->unique(['regla_id', 'version'], 'regla_version_unica');
            $t->index(['regla_id', 'vigente_desde']);
        });
    }

    /**
     * Los papeles que pide, y CUÁNDO.
     *
     * `momento` separa lo que se entrega al solicitar de lo que se entrega
     * durante y de lo que hace falta para liberar: pedirlo todo al principio
     * frenaría la solicitud por una carta de término que aún no existe.
     */
    private function documentos(): void
    {
        if (Schema::hasTable('regla_documentos')) {
            return;
        }

        Schema::create('regla_documentos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('version_id')->constrained('reglas_proceso_versiones')->cascadeOnDelete();

            // El catálogo de papeles YA existe y se reusa con un ámbito nuevo:
            // es el mismo documento que ya sabe tener vigencia y estados.
            $t->foreignId('documento_id')->constrained('documentos_requeridos');

            $t->string('momento', 20)->default('solicitud');
            $t->boolean('obligatorio')->default(true);

            /*
             * Cuántos días vale el papel.
             *
             * Un comprobante de domicilio de hace tres años no sirve, y sin
             * esto la vigencia se revisaría a ojo. Null = no caduca.
             */
            $t->unsignedSmallInteger('dias_vigencia')->nullable();

            $t->auditoria();

            $t->unique(['version_id', 'documento_id', 'momento'], 'regla_documento_unico');
        });
    }

    /** Las materias que hay que haber aprobado antes. */
    private function materiasPrevias(): void
    {
        if (Schema::hasTable('regla_materias_previas')) {
            return;
        }

        Schema::create('regla_materias_previas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('version_id')->constrained('reglas_proceso_versiones')->cascadeOnDelete();
            $t->foreignId('plan_materia_id')->constrained('plan_materias')->cascadeOnDelete();
            $t->auditoria();

            $t->unique(['version_id', 'plan_materia_id'], 'regla_materia_unica');
        });
    }

    /**
     * En qué situación académica se admite.
     *
     * SIN filas, se admite cualquiera. Con filas, sólo las señaladas: una
     * escuela que no quiera mandar a un condicionado lo dice aquí, y no hay que
     * cablear qué situaciones son «buenas» — eso lo decide cada escuela en su
     * catálogo.
     */
    private function situacionesPermitidas(): void
    {
        if (Schema::hasTable('regla_situaciones_permitidas')) {
            return;
        }

        Schema::create('regla_situaciones_permitidas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('version_id')->constrained('reglas_proceso_versiones')->cascadeOnDelete();
            $t->foreignId('situacion_alumno_id')->constrained('situaciones_alumno')->cascadeOnDelete();
            $t->auditoria();

            $t->unique(['version_id', 'situacion_alumno_id'], 'regla_situacion_unica');
        });
    }
};
