<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vistas guardadas de un reporte, y favoritos.
 *
 * ── Es la respuesta REAL al punto 5 del cliente ──────────────────────────
 * «La SEP a veces pide los mismos reportes de formas personalizadas». Eso es,
 * casi siempre, el MISMO reporte con otras columnas, otro orden y otros
 * filtros. Una vista guardada lo resuelve sin constructor y sin SQL
 * configurable: se guarda la CONFIGURACIÓN, no una consulta nueva.
 *
 * ── Una vista guarda FILTROS y COLUMNAS, jamás FILAS ─────────────────────
 * Es la regla de seguridad que la sostiene. Al ejecutarla se rehace el
 * pipeline entero —permiso, faceta, módulo y alcance por campus— con los de
 * QUIEN LA EJECUTA. Por eso compartir una vista no comparte datos: el
 * coordinador del campus norte que abre una vista de dirección general ve el
 * norte, no lo que veía el dueño.
 *
 * ── JSON y no tablas hijas ───────────────────────────────────────────────
 * Mismo criterio que `credenciales_rol.campos_anverso` y
 * `disenos_historial.columnas`: se lee y se escribe SIEMPRE completa, nunca se
 * ordena ni se filtra por una columna suelta. Una tabla hija pagaría un JOIN y
 * una migración por cada atributo nuevo a cambio de nada. Y la lista de
 * columnas es ORDENADA: en un reporte, el orden es la mitad del diseño.
 *
 * ── No se congela con el uso ─────────────────────────────────────────────
 * Al revés que `formularios` y que `esquema_evaluacion`: un reporte se
 * EJECUTA, no se responde. No queda nada guardado que pueda acabar mintiendo
 * si la vista cambia.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('vistas_reporte')) {
            Schema::create('vistas_reporte', function (Blueprint $tabla) {
                $tabla->id();
                // Clave de una clase, sin foránea: apunta a código.
                $tabla->string('reporte', 80);
                $tabla->string('nombre', 120);
                $tabla->string('descripcion', 255)->nullable();

                $tabla->json('columnas')->nullable();
                $tabla->json('filtros')->nullable();
                $tabla->string('orden_por', 60)->nullable();
                $tabla->string('orden_dir', 4)->default('asc');

                /*
                 * El DUEÑO. Null = de la escuela, o sea que la guardó alguien
                 * con permiso de organizar y la ve todo el mundo que pueda
                 * ejecutar el reporte.
                 */
                $tabla->foreignId('persona_id')->nullable()->constrained('personas')->nullOnDelete();
                // Compartida a un rol concreto: «las que usa control escolar».
                $tabla->foreignId('rol_id')->nullable()->constrained('roles')->nullOnDelete();

                // Predeterminada del DUEÑO: la que se abre sola al entrar.
                $tabla->boolean('predeterminada')->default(false);
                $tabla->auditoria();

                $tabla->index(['reporte', 'persona_id']);
            });
        }

        if (! Schema::hasTable('reportes_favoritos')) {
            Schema::create('reportes_favoritos', function (Blueprint $tabla) {
                $tabla->id();
                $tabla->string('reporte', 80);
                $tabla->foreignId('persona_id')->constrained('personas')->cascadeOnDelete();
                $tabla->auditoria();

                /*
                 * Uno por persona y reporte.
                 *
                 * Ojo: MySQL trata cada `deleted_at` en null como distinto, así
                 * que este índice NO impide dos filas vivas iguales. Quien lo
                 * garantiza es el `firstOrCreate` del controlador; el índice es
                 * la red para las ya dadas de baja.
                 */
                $tabla->unique(['persona_id', 'reporte', 'deleted_at'], 'favorito_por_persona');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('reportes_favoritos');
        Schema::dropIfExists('vistas_reporte');
    }
};
