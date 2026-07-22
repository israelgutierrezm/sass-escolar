<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Se retira el test Cleaver del embudo de admisión.
 *
 * La spec lo previó (`reactivos_cleaver`, `cleaver_aspirante`,
 * `aspirantes.cleaver_completo`) y el proyecto lo migró, pero **el banco de
 * reactivos nunca se sembró** —era del legacy y no debía inventarse— así que
 * el test jamás pudo aplicarse. El cliente confirma que aquí no se usa.
 *
 * Se elimina en vez de dejarlo apagado porque una tabla vacía que nadie va a
 * llenar es una promesa falsa: aparece en el esquema, alguien la lee y supone
 * que el sistema evalúa psicométricamente a sus aspirantes. Y `cleaver_completo`
 * era peor: una bandera del progreso del embudo que nunca iba a ponerse en
 * true, o sea un paso que ningún aspirante podía completar.
 *
 * `down()` reconstruye las tres, vacías, que es exactamente el estado que
 * tenían: la vuelta atrás no puede inventar un banco de reactivos que nunca
 * existió, y es honesto decirlo aquí.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('cleaver_aspirante');
        Schema::dropIfExists('reactivos_cleaver');

        if (Schema::hasColumn('aspirantes', 'cleaver_completo')) {
            Schema::table('aspirantes', function (Blueprint $table) {
                $table->dropColumn('cleaver_completo');
            });
        }
    }

    public function down(): void
    {
        Schema::table('aspirantes', function (Blueprint $table) {
            $table->boolean('cleaver_completo')->default(false)->after('info_personal_completa');
        });

        Schema::create('reactivos_cleaver', function (Blueprint $table) {
            $table->id();
            $table->smallInteger('grupo');
            $table->string('texto', 255);
            $table->string('factor', 1);
            $table->auditoria();
        });

        Schema::create('cleaver_aspirante', function (Blueprint $table) {
            $table->id();
            $table->foreignId('aspirante_id')->constrained('aspirantes')->cascadeOnDelete();
            $table->foreignId('reactivo_id')->constrained('reactivos_cleaver');
            $table->boolean('mas')->default(false);
            $table->boolean('menos')->default(false);
            $table->auditoria();
        });
    }
};
