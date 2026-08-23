<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Módulo 11 · Bolsa de trabajo — las postulaciones y su seguimiento.
 *
 * ── Quién capturó dice por dónde llegó ────────────────────────────────────
 * `capturada_por` en null significa que la persona se postuló SOLA desde su
 * portal; con valor, que alguien de vinculación la registró en ventanilla. Una
 * columna `origen` con dos valores diría menos por el mismo espacio: así se sabe
 * además QUIÉN la capturó, que es lo que se pregunta cuando algo sale mal.
 *
 * ── Una persona no se postula dos veces a la misma vacante ────────────────
 * Único sobre (vacante, persona). Sin él, el mismo alumno acaba tres veces en la
 * lista del reclutador —una por cada vez que le dio al botón sin ver que ya
 * estaba— y los conteos de postulantes dejan de significar algo.
 *
 * ── La bitácora existe para MEDIR, no para auditar ────────────────────────
 * Una fila por cambio de etapa, con la fecha. Es lo que permite contestar
 * «cuánto tarda un egresado en colocarse» y «en qué etapa se atoran», que es el
 * indicador que piden las acreditadoras. Guardar sólo la etapa actual daría la
 * foto y perdería la película.
 *
 * ── El CV es de la POSTULACIÓN, no del expediente ─────────────────────────
 * Cuelga de aquí y no de `documentos_alumno` porque no es un papel que la
 * escuela le exija: es lo que esa persona mandó a esa vacante, y cambia de una
 * a otra. Va al disco privado, servido por ruta autenticada.
 */
return new class extends Migration
{
    private const ETAPAS = [
        ['postulado', 'Postulado', 10],
        ['en_revision', 'En revisión', 20],
        ['entrevista', 'Entrevista', 30],
        ['oferta', 'Con oferta', 40],
        ['contratado', 'Contratado', 50],
        ['rechazado', 'Rechazado', 60],
        ['desistio', 'Desistió', 70],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('etapas_postulacion')) {
            Schema::create('etapas_postulacion', function (Blueprint $table) {
                $table->id();
                $table->string('clave', 50)->unique();
                $table->string('nombre', 120);
                $table->unsignedSmallInteger('orden')->default(0);
                $table->boolean('activo')->default(true);
                $table->auditoria();
            });
        }

        foreach (self::ETAPAS as [$clave, $nombre, $orden]) {
            DB::table('etapas_postulacion')->updateOrInsert(
                ['clave' => $clave],
                ['nombre' => $nombre, 'orden' => $orden, 'activo' => true, 'created_at' => now(), 'updated_at' => now()],
            );
        }

        if (! Schema::hasTable('postulaciones')) {
            Schema::create('postulaciones', function (Blueprint $table) {
                $table->id();
                $table->foreignId('vacante_id')->constrained('vacantes')->cascadeOnDelete();
                $table->foreignId('persona_id')->constrained('personas')->cascadeOnDelete();

                /*
                 * Con qué perfil académico se postula. Nullable porque un
                 * egresado de hace diez años puede seguir usando la bolsa y su
                 * matrícula ya no dice nada de lo que hace hoy.
                 */
                $table->foreignId('matricula_oferta_id')->nullable()->constrained('matricula_oferta')->nullOnDelete();

                $table->string('cv_ruta', 500)->nullable();
                $table->text('carta_presentacion')->nullable();
                $table->foreignId('etapa_id')->constrained('etapas_postulacion');
                $table->timestamp('fecha_postulacion');

                // Null = se postuló sola desde su portal. Ver el docblock.
                $table->foreignId('capturada_por')->nullable()->constrained('personas')->nullOnDelete();

                $table->auditoria();

                $table->unique(['vacante_id', 'persona_id']);

                // Lo que se consulta es «quiénes se postularon a esta vacante y
                // en qué van»; el índice tiene que empezar por la vacante.
                $table->index(['vacante_id', 'etapa_id']);
            });
        }

        if (Schema::hasTable('postulacion_bitacora')) {
            return;
        }

        Schema::create('postulacion_bitacora', function (Blueprint $table) {
            $table->id();
            $table->foreignId('postulacion_id')->constrained('postulaciones')->cascadeOnDelete();

            // De dónde a dónde. La de origen va nullable porque el primer
            // renglón —el alta— no viene de ninguna etapa anterior.
            $table->foreignId('etapa_origen_id')->nullable()->constrained('etapas_postulacion')->nullOnDelete();
            $table->foreignId('etapa_destino_id')->constrained('etapas_postulacion');

            $table->foreignId('movida_por')->nullable()->constrained('personas')->nullOnDelete();
            $table->string('nota', 500)->nullable();
            $table->timestamp('momento');
            $table->auditoria();

            $table->index(['postulacion_id', 'momento']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('postulacion_bitacora');
        Schema::dropIfExists('postulaciones');
        Schema::dropIfExists('etapas_postulacion');
    }
};
