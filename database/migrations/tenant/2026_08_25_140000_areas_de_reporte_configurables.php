<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las áreas de reporte, renombrables, y dónde vive cada reporte.
 *
 * Es el pedido del cliente: «poder mover reportes de área o renombrar las áreas
 * en un apartado de configuración dentro de reportes».
 *
 * ── El área es una CARPETA, no un permiso ────────────────────────────────
 * Y por eso NO lleva columna `permiso` ni `modulo`. Quién puede ver un reporte
 * lo decide su FUENTE. Si el área filtrara, arrastrar un reporte de finanzas a
 * un área llamada «Dirección» le concedería a alguien acceso a la cartera con un
 * gesto de acomodo — y nadie relacionaría las dos cosas.
 *
 * ── `clave` no se edita; `nombre` sí ─────────────────────────────────────
 * Es la misma separación que en los roles protegidos: la clave la conoce el
 * código —cada reporte declara en cuál nace— y el nombre es lo que la escuela
 * cambia. Una escuela que llame «Servicios escolares» a lo que aquí se llama
 * «control-escolar» tiene que poder hacerlo sin que se rompa nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('areas_reporte')) {
            Schema::create('areas_reporte', function (Blueprint $tabla) {
                $tabla->id();
                // La conoce el código: cada reporte declara su área sugerida.
                $tabla->string('clave', 50)->unique();
                // Esto es lo que se renombra.
                $tabla->string('nombre', 80);
                $tabla->string('descripcion', 255)->nullable();
                $tabla->unsignedSmallInteger('orden')->default(0);
                $tabla->boolean('activo')->default(true);
                $tabla->auditoria();
            });
        }

        if (! Schema::hasTable('ubicaciones_reporte')) {
            Schema::create('ubicaciones_reporte', function (Blueprint $tabla) {
                $tabla->id();

                /*
                 * `reporte` es la CLAVE de una clase, sin foránea: apunta a
                 * código, no a una fila. Un reporte que se retire deja su
                 * ubicación huérfana en vez de impedir el despliegue, y el
                 * registro simplemente no la encuentra.
                 */
                $tabla->string('reporte', 80);
                $tabla->foreignId('area_id')->constrained('areas_reporte')->cascadeOnDelete();
                // Renombre local: null = el título que declara la clase.
                $tabla->string('nombre', 120)->nullable();
                $tabla->unsignedSmallInteger('orden')->default(0);
                $tabla->boolean('activo')->default(true);
                $tabla->auditoria();

                /*
                 * Un reporte vive en UN área a la vez: con dos, «muévelo de
                 * área» no significaría nada y aparecería duplicado en el
                 * índice.
                 *
                 * Ojo: en MySQL un índice único trata cada NULL como distinto,
                 * así que esto NO impide dos filas vivas con el mismo reporte
                 * —ambas con `deleted_at` en null—. Quien de verdad lo garantiza
                 * es el `updateOrCreate` del controlador; el índice es la red
                 * para las filas ya dadas de baja.
                 */
                $tabla->unique(['reporte', 'deleted_at'], 'ubicacion_por_reporte');
                $tabla->index(['area_id', 'orden']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ubicaciones_reporte');
        Schema::dropIfExists('areas_reporte');
    }
};
