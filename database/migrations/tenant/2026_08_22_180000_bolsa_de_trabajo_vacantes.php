<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Módulo 11 · Bolsa de trabajo — las vacantes.
 *
 * ── Sin carreras señaladas, la vacante es para TODAS ──────────────────────
 * `vacante_carreras` acota el perfil, y estar vacía significa «abierta a
 * cualquiera». La alternativa —exigir al menos una— obligaría a palomear las
 * veinte carreras de la escuela cada vez que un empleador busca «recién
 * egresados de lo que sea», que es la mitad de las vacantes reales. Se dice en
 * la pantalla para que nadie lo deduzca.
 *
 * ── El salario es opcional, y por eso hay dos columnas y no una ───────────
 * Casi ninguna vacante mexicana lo publica; las que sí, publican un rango. Con
 * una sola columna habría que elegir entre mentir con el mínimo o inventar un
 * promedio, y filtrar «de 15 a 25 mil» dejaría de ser posible.
 *
 * ── `habilidades` se siembra con lo transversal, no con lo técnico ────────
 * Las técnicas dependen de lo que enseña cada escuela —no es lo mismo un
 * bachillerato que una ingeniería— y sembrarlas sería adivinar. Lo que sí
 * comparte todo el mundo son las de trato y herramienta básica; con eso el campo
 * sirve desde el primer día y la escuela agrega las suyas.
 */
return new class extends Migration
{
    private const MODALIDADES = [
        ['presencial', 'Presencial', 10],
        ['hibrido', 'Híbrido', 20],
        ['remoto', 'Remoto', 30],
    ];

    private const JORNADAS = [
        ['tiempo_completo', 'Tiempo completo', 10],
        ['medio_tiempo', 'Medio tiempo', 20],
        ['por_horas', 'Por horas', 30],
        ['practicas', 'Prácticas profesionales', 40],
        ['servicio_social', 'Servicio social', 50],
    ];

    private const SITUACIONES = [
        ['abierta', 'Abierta', 10],
        ['pausada', 'Pausada', 20],
        ['cerrada', 'Cerrada', 30],
    ];

    private const HABILIDADES = [
        ['ingles', 'Inglés', 10],
        ['ofimatica', 'Ofimática', 20],
        ['excel_avanzado', 'Excel avanzado', 30],
        ['atencion_cliente', 'Atención a clientes', 40],
        ['trabajo_equipo', 'Trabajo en equipo', 50],
        ['comunicacion', 'Comunicación', 60],
        ['liderazgo', 'Liderazgo', 70],
        ['licencia_conducir', 'Licencia de conducir', 80],
    ];

    public function up(): void
    {
        $catalogos = [
            'modalidades_trabajo' => self::MODALIDADES,
            'tipos_jornada' => self::JORNADAS,
            'situaciones_vacante' => self::SITUACIONES,
            'habilidades' => self::HABILIDADES,
        ];

        foreach ($catalogos as $tabla => $semilla) {
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

        if (! Schema::hasTable('vacantes')) {
            Schema::create('vacantes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
                $table->string('titulo', 200);
                $table->text('descripcion');
                $table->foreignId('modalidad_id')->nullable()->constrained('modalidades_trabajo')->nullOnDelete();
                $table->foreignId('tipo_jornada_id')->nullable()->constrained('tipos_jornada')->nullOnDelete();

                $table->decimal('salario_min', 12, 2)->nullable();
                $table->decimal('salario_max', 12, 2)->nullable();

                // Si la difunde un campus concreto. Null = toda la escuela.
                $table->foreignId('campus_id')->nullable()->constrained('campus')->nullOnDelete();

                $table->unsignedSmallInteger('vacantes_disponibles')->default(1);
                $table->string('ubicacion', 200)->nullable();
                $table->date('fecha_publicacion');
                $table->date('fecha_cierre')->nullable();
                $table->foreignId('situacion_id')->constrained('situaciones_vacante');
                $table->auditoria();

                /*
                 * Lo que se consulta es «qué hay abierto», y para eso el índice
                 * tiene que empezar por la situación. Sin él, el tablero del
                 * alumno recorre el histórico completo de la escuela.
                 */
                $table->index(['situacion_id', 'fecha_cierre']);
            });
        }

        if (! Schema::hasTable('vacante_carreras')) {
            Schema::create('vacante_carreras', function (Blueprint $table) {
                $table->id();
                $table->foreignId('vacante_id')->constrained('vacantes')->cascadeOnDelete();
                $table->foreignId('carrera_id')->constrained('carreras')->cascadeOnDelete();
                $table->auditoria();

                $table->unique(['vacante_id', 'carrera_id']);
            });
        }

        if (Schema::hasTable('vacante_habilidades')) {
            return;
        }

        Schema::create('vacante_habilidades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vacante_id')->constrained('vacantes')->cascadeOnDelete();
            $table->foreignId('habilidad_id')->constrained('habilidades')->cascadeOnDelete();

            // Distinguir lo indispensable de lo que suma: sin esto, una vacante
            // con ocho habilidades parece exigirlas todas y nadie se postula.
            $table->boolean('indispensable')->default(false);
            $table->auditoria();

            $table->unique(['vacante_id', 'habilidad_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vacante_habilidades');
        Schema::dropIfExists('vacante_carreras');
        Schema::dropIfExists('vacantes');
        Schema::dropIfExists('habilidades');
        Schema::dropIfExists('situaciones_vacante');
        Schema::dropIfExists('tipos_jornada');
        Schema::dropIfExists('modalidades_trabajo');
    }
};
