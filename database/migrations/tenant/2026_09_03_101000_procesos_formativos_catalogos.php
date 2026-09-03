<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los catálogos del módulo de servicio social y prácticas.
 *
 * Función pedida por el cliente; la spec sólo trae la ATESTACIÓN para el título
 * (`titulo_servicio_social`, dos columnas para el XML), nunca un módulo de
 * gestión. Así que esto es dominio nuevo y se diseña con los patrones del
 * proyecto — como Disciplina y Movimientos escolares.
 *
 * ── Todo catálogo, y con BANDERAS de comportamiento ────────────────────────
 * Ninguna escuela pide lo mismo: un tecnológico exige residencia con 80 % de
 * créditos, una normal exige dos años de servicio social, una privada 480 horas
 * de prácticas desde séptimo. Por eso el TIPO es una fila y no un enum, y lo que
 * el código consulta son sus banderas —`exige_organizacion`, `cuenta_horas`— y
 * NUNCA su clave: preguntar por `clave === 'servicio_social'` funciona hoy y
 * deja de funcionar en silencio el día que la escuela edite su catálogo. Es la
 * lección de `entra_a_nomina` y `cuenta_como_egresado`.
 *
 * ── Y por eso el tipo se llama «estancia profesional» ──────────────────────
 * `estancias` ya existe en Movilidad y es OTRA cosa: el periodo efectivo de un
 * intercambio académico, del que cuelga la revalidación de materias. Dos
 * verdades sobre la misma palabra se acaban confundiendo, así que aquí se
 * nombra completo.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->tiposDeProceso();
        $this->catalogosDeOrganizacion();
        $this->catalogosDeConvenio();
        $this->modalidades();
        $this->tiposDeInforme();
    }

    public function down(): void
    {
        Schema::dropIfExists('tipos_informe_proceso');
        Schema::dropIfExists('modalidades_proceso');
        Schema::dropIfExists('tipos_convenio_formativo');
        Schema::dropIfExists('situaciones_organizacion');
        Schema::dropIfExists('tipos_organizacion');
        Schema::dropIfExists('sectores_organizacion');
        Schema::dropIfExists('tipos_proceso_formativo');
    }

    private function tiposDeProceso(): void
    {
        if (Schema::hasTable('tipos_proceso_formativo')) {
            return;
        }

        Schema::create('tipos_proceso_formativo', function (Blueprint $t) {
            $t->id();
            $t->string('clave', 50)->unique();
            $t->string('nombre', 150);
            $t->string('descripcion', 255)->nullable();

            /*
             * Un proyecto comunitario que la escuela organiza no tiene una
             * organización receptora. Obligarla convertiría en trámite falso
             * capturar «la propia escuela» como si fuera un tercero.
             */
            $t->boolean('exige_organizacion')->default(true);

            /*
             * Si el alumno elige de un catálogo publicado. Hay procesos donde
             * la plaza no existe como tal: el alumno llega con su carta.
             */
            $t->boolean('exige_plaza')->default(false);

            // Si puede proponer una organización que todavía no está en el padrón.
            $t->boolean('permite_organizacion_propuesta')->default(true);

            /*
             * Si lleva bitácora de horas.
             *
             * Una «experiencia profesional» que se acredita con constancia
             * laboral no se registra hora por hora, y pedirle una bitácora
             * dejaría el expediente esperando algo que nadie va a capturar.
             */
            $t->boolean('cuenta_horas')->default(true);

            $t->unsignedSmallInteger('orden')->default(0);
            $t->boolean('activo')->default(true);
            $t->auditoria();
        });
    }

    private function catalogosDeOrganizacion(): void
    {
        foreach ([
            'sectores_organizacion' => 'A qué se dedica: salud, educación, gobierno…',
            'tipos_organizacion' => 'Qué es: dependencia, asociación civil, empresa…',
        ] as $tabla => $para) {
            if (Schema::hasTable($tabla)) {
                continue;
            }

            Schema::create($tabla, function (Blueprint $t) {
                $t->id();
                $t->string('clave', 50)->unique();
                $t->string('nombre', 150);
                $t->string('descripcion', 255)->nullable();
                $t->unsignedSmallInteger('orden')->default(0);
                $t->boolean('activo')->default(true);
                $t->auditoria();
            });
        }

        if (Schema::hasTable('situaciones_organizacion')) {
            return;
        }

        Schema::create('situaciones_organizacion', function (Blueprint $t) {
            $t->id();
            $t->string('clave', 50)->unique();
            $t->string('nombre', 150);
            $t->string('descripcion', 255)->nullable();

            /*
             * La bandera, no la clave: es lo que decide si se le puede mandar
             * un alumno. «En revisión» y «suspendida» no reciben; «activa» y
             * «con convenio» sí. Una escuela que agregue «en trámite» decide
             * ella misma de qué lado cae.
             */
            $t->boolean('acepta_asignaciones')->default(true);

            $t->unsignedSmallInteger('orden')->default(0);
            $t->boolean('activo')->default(true);
            $t->auditoria();
        });
    }

    private function catalogosDeConvenio(): void
    {
        if (Schema::hasTable('tipos_convenio_formativo')) {
            return;
        }

        Schema::create('tipos_convenio_formativo', function (Blueprint $t) {
            $t->id();
            $t->string('clave', 50)->unique();
            $t->string('nombre', 150);
            $t->string('descripcion', 255)->nullable();
            $t->unsignedSmallInteger('orden')->default(0);
            $t->boolean('activo')->default(true);
            $t->auditoria();
        });
    }

    private function modalidades(): void
    {
        if (Schema::hasTable('modalidades_proceso')) {
            return;
        }

        Schema::create('modalidades_proceso', function (Blueprint $t) {
            $t->id();
            $t->string('clave', 50)->unique();
            $t->string('nombre', 150);
            $t->string('descripcion', 255)->nullable();

            /*
             * Si la modalidad ocurre fuera de una sede. Lo lee la bitácora de
             * horas para decidir si tiene sentido pedir ubicación —a alguien en
             * remoto no se le pide dónde está—.
             */
            $t->boolean('es_a_distancia')->default(false);

            $t->unsignedSmallInteger('orden')->default(0);
            $t->boolean('activo')->default(true);
            $t->auditoria();
        });
    }

    private function tiposDeInforme(): void
    {
        if (Schema::hasTable('tipos_informe_proceso')) {
            return;
        }

        Schema::create('tipos_informe_proceso', function (Blueprint $t) {
            $t->id();
            $t->string('clave', 50)->unique();
            $t->string('nombre', 150);
            $t->string('descripcion', 255)->nullable();

            /*
             * Si es el informe que CIERRA el proceso.
             *
             * Bandera y no clave, por lo de siempre. Y es la que la liberación
             * consulta: sin ella habría que preguntar por `clave === 'final'` en
             * el sitio donde más caro sale equivocarse.
             */
            $t->boolean('es_final')->default(false);

            $t->unsignedSmallInteger('orden')->default(0);
            $t->boolean('activo')->default(true);
            $t->auditoria();
        });
    }
};
