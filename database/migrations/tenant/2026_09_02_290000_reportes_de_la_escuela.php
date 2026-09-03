<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El constructor de reportes: un PRESET sobre una fuente que ya existe.
 *
 * ── Esto NO es SQL configurable, y la diferencia es toda la rebanada ───────
 * Un reporte de la escuela es exactamente lo que ya es un reporte del código:
 * una FUENTE declarada por un programador + un nombre + unas columnas + unos
 * filtros fijos. Lo que la SEP cambia entre una petición y la siguiente son
 * columnas, encabezado, orden y formato —todo dato—; la consulta («egresados de
 * este plan con su promedio») es la misma en las cinco versiones que pida.
 *
 * Un campo de SQL libre se descartó por escrito antes de empezar: `stancl`
 * aísla por BASE DE DATOS, no por permisos de MySQL, así que esa caja convierte
 * cualquier cuenta con ese permiso en lectura completa del tenant —`usuarios`,
 * `personas`, los certificados de sello digital— y ninguna lista negra de
 * palabras la cierra.
 *
 * ── Y por eso NO hay tablas espejo del registro ────────────────────────────
 * `fuente` es una cadena SIN llave foránea: las fuentes viven en el código, y
 * copiarlas a una tabla con un comando de sincronización sería la segunda
 * verdad que este proyecto ya pagó con `acta.formato_folio` declarado dos
 * veces, replicada por cada campo de cada fuente. Lo que la escuela sí renombra
 * —las áreas— ya lo cubre `ubicaciones_reporte`.
 *
 * ── El permiso, el módulo y la faceta los pone la FUENTE ───────────────────
 * No se guardan aquí. `RegistroReportes::para()` los resuelve mirando la fuente
 * del reporte, así que un reporte de la escuela no puede abrir una puerta que
 * su fuente tenga cerrada — que es la única forma de que esto sea seguro.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('reportes_escuela')) {
            return;
        }

        Schema::create('reportes_escuela', function (Blueprint $tabla) {
            $tabla->id();
            /*
             * Estable: la guardan la ubicación, las vistas guardadas y la
             * bitácora. Se genera con prefijo para que no pueda pisar la de un
             * reporte del código — si una escuela llamara al suyo
             * «alumnos-inscritos», sombrearía al de verdad y nadie sabría por
             * qué cambió.
             */
            $tabla->string('clave', 80)->unique();
            $tabla->string('nombre', 150);
            /*
             * Obligatoria, como en los reportes del código: tiene que decir qué
             * contesta Y QUÉ NO. Es lo que evita que alguien lo lleve a una
             * junta creyendo que dice otra cosa.
             */
            $tabla->string('descripcion', 500);
            // La clave de la fuente. SIN llave foránea: vive en el código.
            $tabla->string('fuente', 60);
            $tabla->string('area_sugerida', 60)->default('general');
            $tabla->json('columnas');
            $tabla->json('filtros_fijos');
            /*
             * Los que hay que elegir para poder correrlo.
             *
             * No es adorno: un reporte sobre una fuente grande sin acotar barre
             * la escuela entera, y por eso los reportes del codigo que lo
             * necesitan lo declaran. Sin esta columna, un reporte armado desde
             * pantalla seria el unico que no puede pedirlo — y seria justo el
             * que lo arma quien menos sabe del tamano de la tabla.
             */
            $tabla->json('filtros_obligatorios');
            $tabla->string('orden_por', 60)->nullable();
            $tabla->string('orden_dir', 4)->nullable();
            /*
             * Sin publicar no aparece en el catálogo de nadie. Un reporte se
             * arma en varios ratos, y uno a medias en la lista se corre y se
             * lleva a una junta.
             */
            $tabla->boolean('publicado')->default(false);
            $tabla->auditoria();

            $tabla->index(['publicado', 'fuente']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reportes_escuela');
    }
};
