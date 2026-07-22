<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Mete al embudo a los aspirantes que se quedaron fuera.
 *
 * El alta manual nunca escribía `etapa_crm_id` —solo lo hacía el formulario
 * público a través de `RegistradorProspecto`—, así que todo prospecto
 * capturado por personal quedaba con la etapa en null: no salía en ninguna
 * columna del embudo, ni en el conteo por etapa, ni ahora en el filtro por
 * etapa. Para promoción, sencillamente no existía.
 *
 * El controlador ya lo asigna al crear; esto es el otro frente, el de los
 * registros que se guardaron bajo la regla vieja. Una regla nueva no deshace
 * sola lo que ya está en la base — es la misma lección que dejó el acotamiento
 * de permisos por faceta.
 *
 * Idempotente: solo toca los que están en null.
 */
return new class extends Migration
{
    public function up(): void
    {
        $primera = DB::table('etapas_crm')->orderBy('orden')->value('id');

        if ($primera === null) {
            return; // escuela sin embudo configurado: no hay dónde meterlos
        }

        DB::table('aspirantes')->whereNull('etapa_crm_id')->update(['etapa_crm_id' => $primera]);
    }

    /**
     * No se revierte: dejarlos otra vez en null los volvería invisibles, que
     * es justo el defecto que esto corrige.
     */
    public function down(): void
    {
        // Nada que deshacer a propósito. Ver la nota de arriba.
    }
};
