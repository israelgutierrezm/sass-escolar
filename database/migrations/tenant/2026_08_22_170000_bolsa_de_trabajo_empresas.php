<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Módulo 11 · Bolsa de trabajo — los empleadores.
 *
 * Primera rebanada: los catálogos que describen a una empresa y la empresa
 * misma con sus contactos.
 *
 * ── UN solo lugar para «con quién se habla en esta empresa» ────────────────
 * La spec pone un `persona_contacto_id` en `empresas` Y ADEMÁS una tabla
 * `empresa_contactos` con «contactos adicionales». Eso deja dos sitios donde
 * buscar al reclutador y la pregunta inevitable de si el principal aparece
 * también en la tabla. Aquí hay una sola tabla, con `es_principal` para
 * distinguir al de siempre y `persona_id` OPCIONAL para el que además tiene
 * cuenta en el sistema —un reclutador que algún día publique sus vacantes—.
 *
 * Es la misma clase de decisión que se tomó con el módulo 13: dos
 * representaciones de la misma cosa acaban divergiendo. Queda anotada en
 * `docs/decisiones.md`.
 *
 * ── La empresa se APAGA, no se borra ──────────────────────────────────────
 * `situaciones_empresa` incluye «vetada», que es lo que se necesita de verdad:
 * una empresa con la que la escuela no quiere volver a trabajar, pero cuyas
 * colocaciones históricas siguen contando para los reportes de acreditación.
 * Borrarla se llevaría esa historia.
 */
return new class extends Migration
{
    private const SECTORES = [
        ['agropecuario', 'Agropecuario', 10],
        ['industria', 'Industria y manufactura', 20],
        ['construccion', 'Construcción', 30],
        ['comercio', 'Comercio', 40],
        ['servicios', 'Servicios', 50],
        ['tecnologia', 'Tecnologías de la información', 60],
        ['salud', 'Salud', 70],
        ['educacion', 'Educación', 80],
        ['gobierno', 'Gobierno', 90],
        ['otro', 'Otro', 100],
    ];

    /** Los cortes de la estratificación de la Secretaría de Economía. */
    private const TAMANOS = [
        ['micro', 'Micro (hasta 10)', 10],
        ['pequena', 'Pequeña (11 a 50)', 20],
        ['mediana', 'Mediana (51 a 250)', 30],
        ['grande', 'Grande (más de 250)', 40],
    ];

    private const SITUACIONES = [
        ['en_revision', 'En revisión', 10],
        ['activa', 'Activa', 20],
        ['vetada', 'Vetada', 30],
    ];

    public function up(): void
    {
        foreach (['sectores_economicos' => self::SECTORES, 'tamanos_empresa' => self::TAMANOS, 'situaciones_empresa' => self::SITUACIONES] as $tabla => $semilla) {
            if (! Schema::hasTable($tabla)) {
                Schema::create($tabla, function (Blueprint $table) {
                    $table->id();
                    $table->string('clave', 50)->unique();
                    $table->string('nombre', 120);
                    $table->unsignedSmallInteger('orden')->default(0);
                    $table->boolean('activo')->default(true);
                    $table->auditoria();
                });
            }

            foreach ($semilla as [$clave, $nombre, $orden]) {
                DB::table($tabla)->updateOrInsert(
                    ['clave' => $clave],
                    ['nombre' => $nombre, 'orden' => $orden, 'activo' => true, 'created_at' => now(), 'updated_at' => now()],
                );
            }
        }

        if (! Schema::hasTable('empresas')) {
            Schema::create('empresas', function (Blueprint $table) {
                $table->id();
                $table->string('razon_social', 255);

                /*
                 * El RFC identifica a la empresa, pero NO se exige: una escuela
                 * registra empleadores que le llaman por teléfono antes de tener
                 * un solo papel suyo. Único cuando está puesto, para no acabar
                 * con la misma empresa capturada tres veces.
                 */
                $table->string('rfc', 13)->nullable()->unique();
                $table->foreignId('sector_id')->nullable()->constrained('sectores_economicos')->nullOnDelete();
                $table->foreignId('tamano_id')->nullable()->constrained('tamanos_empresa')->nullOnDelete();
                $table->string('sitio_web', 255)->nullable();
                $table->foreignId('situacion_id')->constrained('situaciones_empresa');
                $table->string('notas', 500)->nullable();
                $table->auditoria();
            });
        }

        if (Schema::hasTable('empresa_contactos')) {
            return;
        }

        Schema::create('empresa_contactos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();

            /*
             * Opcional: el contacto que además tiene cuenta en el sistema. La
             * mayoría son sólo un nombre y un teléfono, y obligarlos a ser
             * `persona` llenaría el padrón de la escuela con gente que no
             * estudia ni trabaja ahí.
             */
            $table->foreignId('persona_id')->nullable()->constrained('personas')->nullOnDelete();

            $table->string('nombre', 200);
            $table->string('puesto', 120)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('telefono', 30)->nullable();
            $table->boolean('es_principal')->default(false);
            $table->auditoria();

            $table->index(['empresa_id', 'es_principal']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('empresa_contactos');
        Schema::dropIfExists('empresas');
        Schema::dropIfExists('situaciones_empresa');
        Schema::dropIfExists('tamanos_empresa');
        Schema::dropIfExists('sectores_economicos');
    }
};
