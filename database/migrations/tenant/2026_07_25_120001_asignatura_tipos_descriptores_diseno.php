<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Asignaturas: el Tipo a cuatro fijas, los Descriptores como catálogo, y el
 * bloque de imágenes de diseño. Tres cambios que pidió el cliente, juntos
 * porque tocan la misma entidad.
 *
 * 1) TIPO a cuatro: Obligatoria, Optativa, Adicional, Complementaria, y nada
 *    más. El catálogo traía «Seminario» y «Taller» —que no son tipos en el
 *    sentido que el cliente quiere—; como no los usaba ninguna asignatura, se
 *    RENOMBRAN a los dos que faltan en vez de borrarlos y crear otros: así el
 *    id se conserva y nada que apuntara ahí se rompe.
 *
 * 2) DESCRIPTORES pasan de dos textos libres (objetivos/bibliografía) a un
 *    catálogo de selección múltiple. Las columnas de texto se conservan por si
 *    hay algo capturado, pero el formulario ya no las usa. El catálogo nace con
 *    cuatro descriptores y admite más.
 *
 * 3) DISEÑO: tres imágenes por asignatura (la de la materia, la miniatura para
 *    listados, y la portada), guardadas como ruta al disco privado igual que la
 *    foto de una persona o el logo de la institución.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1) Tipo a cuatro fijas. Se reusan los ids libres.
        $this->fijarTipo('seminario', 'adicional', 'Adicional');
        $this->fijarTipo('taller', 'complementaria', 'Complementaria');

        // 2) Catálogo de descriptores + pivote.
        Schema::create('descriptores', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->string('clave', 50);
            $tabla->string('nombre');
            $tabla->auditoria();

            $tabla->unique(['clave', 'deleted_at']);
        });

        Schema::create('asignatura_descriptor', function (Blueprint $tabla) {
            $tabla->foreignId('asignatura_id')->constrained('asignaturas')->cascadeOnDelete();
            $tabla->foreignId('descriptor_id')->constrained('descriptores')->cascadeOnDelete();
            $tabla->timestamps();

            $tabla->primary(['asignatura_id', 'descriptor_id']);
        });

        $descriptores = [
            'bienvenida' => 'Bienvenida',
            'contenido_tematico' => 'Contenido temático',
            'actividades_aprendizaje' => 'Actividades de aprendizaje',
            'criterios_evaluacion' => 'Criterios de evaluación',
        ];

        foreach ($descriptores as $clave => $nombre) {
            DB::table('descriptores')->insert([
                'clave' => $clave,
                'nombre' => $nombre,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 3) Imágenes de diseño. Rutas al disco privado, no binarios.
        Schema::table('asignaturas', function (Blueprint $tabla) {
            $tabla->string('imagen_materia_url', 500)->nullable()->after('bibliografia_desc');
            $tabla->string('imagen_miniatura_url', 500)->nullable()->after('imagen_materia_url');
            $tabla->string('foto_portada_url', 500)->nullable()->after('imagen_miniatura_url');
        });
    }

    public function down(): void
    {
        Schema::table('asignaturas', function (Blueprint $tabla) {
            $tabla->dropColumn(['imagen_materia_url', 'imagen_miniatura_url', 'foto_portada_url']);
        });

        Schema::dropIfExists('asignatura_descriptor');
        Schema::dropIfExists('descriptores');

        $this->fijarTipo('adicional', 'seminario', 'Seminario');
        $this->fijarTipo('complementaria', 'taller', 'Taller');
    }

    /** Renombra un tipo de asignatura solo si existe con la clave de origen. */
    private function fijarTipo(string $claveOrigen, string $claveDestino, string $nombre): void
    {
        DB::table('tipos_asignatura')
            ->where('clave', $claveOrigen)
            ->update(['clave' => $claveDestino, 'nombre' => $nombre]);
    }
};
