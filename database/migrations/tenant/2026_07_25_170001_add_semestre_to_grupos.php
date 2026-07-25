<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Semestre opcional del grupo.
 *
 * No es obligatorio —hay grupos que agrupan materias de varios semestres (el
 * tronco común)—, pero cuando lo tiene sirve de default: al abrir materias, la
 * pantalla filtra por ese semestre en vez de mostrar los cincuenta reactivos de
 * un plan completo. Es una comodidad de captura, no una regla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grupos', function (Blueprint $tabla) {
            $tabla->unsignedTinyInteger('semestre')->nullable()->after('plan_id');
        });
    }

    public function down(): void
    {
        Schema::table('grupos', function (Blueprint $tabla) {
            $tabla->dropColumn('semestre');
        });
    }
};
