<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sesiones que sólo puede leer quien las escribió.
 *
 * El permiso `ver-bitacoras-tutoria` ya separa a quién se le confía la bitácora
 * de quién sólo reparte tutorías. Esto es el grado siguiente, y hace falta
 * porque el mismo tutor lleva casos ordinarios y alguno delicado: una sesión
 * sobre violencia en casa o sobre salud mental no debería viajar al mismo sitio
 * que «revisamos el avance del ensayo», aunque quien mire tenga el permiso.
 *
 * Confidencial NO significa invisible: la sesión sigue apareciendo con su
 * fecha, su motivo y su modalidad. Lo que se reserva es el contenido. Que
 * ocurrió una sesión es parte del seguimiento —y de la constancia de que el
 * tutor hizo su trabajo—; lo que se dijo en ella, no siempre.
 *
 * Arranca en `false` porque lo confidencial debe ser una decisión consciente:
 * si todo lo fuera por omisión, la bitácora dejaría de servir para coordinar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sesiones_tutoria', function (Blueprint $table) {
            $table->boolean('confidencial')->default(false)->after('asistio');
        });
    }

    public function down(): void
    {
        Schema::table('sesiones_tutoria', function (Blueprint $table) {
            $table->dropColumn('confidencial');
        });
    }
};
