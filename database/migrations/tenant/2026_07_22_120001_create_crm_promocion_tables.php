<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El CRM de promoción: de dónde llega un prospecto, en qué punto del embudo
 * está, quién le da seguimiento y qué comisión devenga al inscribirse.
 *
 * HUECO QUE SE CIERRA AQUÍ: `etapas_crm` estaba sembrada desde la Fase 1 con
 * seis etapas y **nadie la usaba** — `aspirantes` nunca tuvo columna de etapa.
 * O sea que el embudo existía como catálogo y no como dato: no se podía saber
 * en qué punto iba nadie, ni cuántos se caían entre una etapa y otra, que es
 * justamente para lo que sirve un CRM.
 *
 * `origen` era un varchar libre. Pasa a catálogo por la regla del proyecto
 * —todo lo enumerable es tabla— y porque de él dependen dos cosas que no
 * funcionan con texto a mano: reportar cuántos llegaron por cada vía, y
 * distinguir al que se registró solo desde la web (que es la entrega D) del que
 * capturó un promotor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('origenes_aspirante', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 50)->unique();
            $table->string('nombre', 150);
            // Marca la vía por la que el prospecto se registró SOLO, sin que
            // nadie lo capturara. Es lo que permite responder "¿cuántos
            // llegaron por la página?" sin adivinar por el nombre del origen.
            $table->boolean('autogestivo')->default(false);
            $table->boolean('activo')->default(true);
            $table->auditoria();
        });

        Schema::create('tipos_seguimiento', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 50)->unique(); // llamada, whatsapp, correo, visita...
            $table->string('nombre', 150);
            // Si al registrarlo hay que decir cuándo es el siguiente contacto.
            // Una llamada sin próximo paso es como se pierde un prospecto.
            $table->boolean('exige_proximo_contacto')->default(false);
            $table->boolean('activo')->default(true);
            $table->auditoria();
        });

        Schema::table('aspirantes', function (Blueprint $table) {
            $table->foreignId('etapa_crm_id')->nullable()->after('situacion_id')
                ->constrained('etapas_crm');
            $table->unsignedBigInteger('origen_id')->nullable()->after('origen');

            $table->index(['etapa_crm_id', 'situacion_id']);
        });

        // El pivote de asesores ya existía, pero sin decir CUÁL de ellos
        // responde por el prospecto. Sin titular no se sabe a quién se le paga
        // la comisión cuando hay dos asesores encima del mismo aspirante.
        Schema::table('aspirante_asesor', function (Blueprint $table) {
            $table->boolean('titular')->default(false)->after('persona_id');
        });

        /*
         * La bitácora de contacto: llamadas, mensajes, visitas. Es el corazón
         * de un CRM y lo que convierte una lista de nombres en un seguimiento.
         *
         * Append-only en la práctica: un contacto ocurrió, y corregir la nota
         * después no cambia que ocurrió. Por eso no hay pantalla de edición.
         */
        Schema::create('seguimientos_aspirante', function (Blueprint $table) {
            $table->id();
            $table->foreignId('aspirante_id')->constrained('aspirantes')->cascadeOnDelete();
            $table->foreignId('tipo_id')->nullable()->constrained('tipos_seguimiento');
            // Quién contactó. Va a PERSONAS y no a usuarios: quien dio
            // seguimiento sigue siendo quien fue aunque su cuenta desaparezca.
            $table->foreignId('persona_id')->nullable()->constrained('personas');
            // En qué etapa estaba el prospecto cuando ocurrió. Congelado, no
            // leído en vivo: es lo que permite medir cuánto tardó en avanzar.
            $table->foreignId('etapa_crm_id')->nullable()->constrained('etapas_crm');
            $table->text('nota');
            $table->date('proximo_contacto')->nullable();
            $table->timestamp('momento');
            $table->auditoria();

            $table->index(['aspirante_id', 'momento']);
            // El tablero de "qué me toca hoy" se consulta por esta columna.
            $table->index('proximo_contacto');
        });

        /*
         * Comisiones. DECISIÓN DEL CLIENTE: el promotor devenga cuando el
         * aspirante se INSCRIBE, no cuando lo captura. Se paga por resultado;
         * devengar al registrar premia capturar nombres.
         */
        Schema::create('reglas_comision', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 150);
            // Mismo patrón de precedencia que los planes de cobro y las razones
            // sociales: global / carrera / oferta, gana la más específica.
            $table->string('aplica_a_tipo', 20)->default('global');
            $table->unsignedBigInteger('aplica_a_id')->nullable();
            $table->string('modo', 20); // monto_fijo / porcentaje
            $table->decimal('valor', 10, 4);
            // Sobre qué se calcula el porcentaje. Sin esto, "10%" no dice de
            // qué: de la inscripción, de la colegiatura, del total del año.
            $table->foreignId('concepto_id')->nullable()->constrained('conceptos_pago');
            $table->date('vigente_desde');
            $table->date('vigente_hasta')->nullable();
            $table->boolean('activo')->default(true);
            $table->auditoria();

            $table->index(['aplica_a_tipo', 'aplica_a_id']);
        });

        Schema::create('comisiones', function (Blueprint $table) {
            $table->id();
            // A quién se le debe. FK a `asesores`, cuya PK es persona_id.
            $table->foreignId('persona_id')->constrained('asesores', 'persona_id');
            $table->foreignId('aspirante_id')->nullable()->constrained('aspirantes');
            $table->foreignId('matricula_oferta_id')->nullable()->constrained('matricula_oferta');
            $table->foreignId('regla_id')->nullable()->constrained('reglas_comision')->nullOnDelete();

            // El monto se CONGELA al devengar. Si mañana la escuela cambia la
            // regla, lo ya ganado no se recalcula: era el trato vigente cuando
            // ese alumno se inscribió.
            $table->decimal('monto', 10, 2);
            $table->string('estatus', 20)->default('devengada'); // devengada/pagada/cancelada
            $table->timestamp('devengada_en');
            $table->timestamp('pagada_en')->nullable();
            $table->string('motivo_cancelacion', 255)->nullable();
            $table->auditoria();

            $table->index(['persona_id', 'estatus']);
            // Una matrícula devenga UNA comisión: si la conversión se reintenta
            // o alguien la vuelve a matricular, no se paga dos veces.
            $table->unique(['matricula_oferta_id', 'persona_id'], 'comisiones_matricula_asesor_unique');
        });

        $this->sembrarCatalogos();
    }

    /**
     * Los catálogos se siembran aquí y NO solo en el seeder porque la columna
     * `etapa_crm_id` necesita un valor de arranque para los aspirantes que ya
     * existen: sin etapa quedarían fuera del embudo, invisibles en el tablero.
     */
    private function sembrarCatalogos(): void
    {
        $ahora = now();

        $origenes = [
            ['clave' => 'promocion', 'nombre' => 'Personal de promoción', 'autogestivo' => false],
            ['clave' => 'sitio_web', 'nombre' => 'Sitio web de la escuela', 'autogestivo' => true],
            ['clave' => 'referido', 'nombre' => 'Referido por un alumno o egresado', 'autogestivo' => false],
            ['clave' => 'redes_sociales', 'nombre' => 'Redes sociales', 'autogestivo' => false],
            ['clave' => 'feria', 'nombre' => 'Feria o evento', 'autogestivo' => false],
            ['clave' => 'visita', 'nombre' => 'Visita al plantel', 'autogestivo' => false],
            ['clave' => 'otro', 'nombre' => 'Otro', 'autogestivo' => false],
        ];

        foreach ($origenes as $fila) {
            DB::table('origenes_aspirante')->insert($fila + [
                'activo' => true, 'created_at' => $ahora, 'updated_at' => $ahora,
            ]);
        }

        $tipos = [
            ['clave' => 'llamada', 'nombre' => 'Llamada telefónica', 'exige_proximo_contacto' => true],
            ['clave' => 'whatsapp', 'nombre' => 'WhatsApp', 'exige_proximo_contacto' => false],
            ['clave' => 'correo', 'nombre' => 'Correo electrónico', 'exige_proximo_contacto' => false],
            ['clave' => 'visita', 'nombre' => 'Visita al plantel', 'exige_proximo_contacto' => true],
            ['clave' => 'cita', 'nombre' => 'Cita agendada', 'exige_proximo_contacto' => true],
            ['clave' => 'nota', 'nombre' => 'Nota interna', 'exige_proximo_contacto' => false],
        ];

        foreach ($tipos as $fila) {
            DB::table('tipos_seguimiento')->insert($fila + [
                'activo' => true, 'created_at' => $ahora, 'updated_at' => $ahora,
            ]);
        }

        // Los aspirantes que ya existían entran al embudo por la primera etapa:
        // dejarlos sin etapa los volvería invisibles en el tablero, que es
        // peor que colocarlos en un punto discutible.
        $primeraEtapa = DB::table('etapas_crm')->orderBy('orden')->value('id');

        if ($primeraEtapa !== null) {
            DB::table('aspirantes')->whereNull('etapa_crm_id')->update(['etapa_crm_id' => $primeraEtapa]);
        }

        // El origen de texto libre que hubiera se mapea por clave; lo que no
        // coincida queda en NULL y se ve como "sin origen" en la pantalla.
        DB::statement(
            'UPDATE aspirantes a
             JOIN origenes_aspirante o ON o.clave = a.origen
             SET a.origen_id = o.id
             WHERE a.origen_id IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('comisiones');
        Schema::dropIfExists('reglas_comision');
        Schema::dropIfExists('seguimientos_aspirante');

        Schema::table('aspirante_asesor', function (Blueprint $table) {
            $table->dropColumn('titular');
        });

        Schema::table('aspirantes', function (Blueprint $table) {
            $table->dropForeign(['etapa_crm_id']);
            $table->dropIndex(['etapa_crm_id', 'situacion_id']);
            $table->dropColumn(['etapa_crm_id', 'origen_id']);
        });

        Schema::dropIfExists('tipos_seguimiento');
        Schema::dropIfExists('origenes_aspirante');
    }
};
