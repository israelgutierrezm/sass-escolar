<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Repara los grupos cuyo nivel se guardó del catálogo equivocado.
 *
 * Hay dos tablas `niveles_estudio`: la de Landlord son los niveles
 * estandarizados por la SEP (ids 1..7) y la del tenant son los niveles que ESTA
 * escuela oferta (ids propios). `carreras.nivel_estudios_id` apunta a la del
 * tenant, así que el grupo tiene que apuntar ahí también.
 *
 * El alta de grupos llenaba el desplegable con el catálogo de Landlord: las
 * etiquetas se veían bien («Licenciatura») pero el id guardado no cruzaba con
 * ninguna carrera, y todo lo que depende del nivel —el filtro de carreras del
 * formulario y los candidatos de la inscripción masiva— salía vacío sin decir
 * por qué.
 *
 * La reparación busca el nivel correcto por el plan del grupo cuando lo tiene, y
 * si no, traduce por NOMBRE contra el catálogo de Landlord. Lo que no se pueda
 * traducir se deja como está: un id sin sentido es visible y corregible a mano,
 * y adivinar un nivel es peor que dejarlo señalado.
 */
return new class extends Migration
{
    public function up(): void
    {
        $validos = DB::table('niveles_estudio')->pluck('id')->all();

        if ($validos === []) {
            return;
        }

        // 1. Con plan: el nivel sale de la carrera del plan, que es la verdad.
        DB::statement('
            UPDATE grupos g
            JOIN planes_estudio p ON p.id = g.plan_id
            JOIN carreras c ON c.id = p.carrera_id
            SET g.nivel_estudios_id = c.nivel_estudios_id
            WHERE g.nivel_estudios_id NOT IN ('.implode(',', $validos).')
        ');

        // 2. Sin plan: traducir por nombre contra el catálogo de Landlord.
        $porNombre = DB::table('niveles_estudio')->pluck('id', 'nombre');

        $sospechosos = DB::table('grupos')
            ->whereNotIn('nivel_estudios_id', $validos)
            ->pluck('nivel_estudios_id', 'id');

        foreach ($sospechosos as $grupoId => $nivelAjeno) {
            $nombre = DB::connection(config('tenancy.database.central_connection', 'mysql'))
                ->table('niveles_estudio')
                ->where('id', $nivelAjeno)
                ->value('nombre');

            if ($nombre === null || ! isset($porNombre[$nombre])) {
                continue;
            }

            DB::table('grupos')->where('id', $grupoId)->update(['nivel_estudios_id' => $porNombre[$nombre]]);
        }
    }

    public function down(): void
    {
        // Sin vuelta atrás: revertir sería volver a poner un id inválido.
    }
};
