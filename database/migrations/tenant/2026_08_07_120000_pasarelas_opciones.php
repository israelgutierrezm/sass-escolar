<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qué ofrece cada pasarela: meses sin intereses, efectivo en tienda, SPEI.
 *
 * ── Por qué no van con las credenciales ────────────────────────────────────
 * Las credenciales están cifradas porque son secretos; esto no lo es —que la
 * escuela acepte OXXO no hay que esconderlo— y meterlo en el mismo blob
 * obligaría a descifrar para leer una configuración que se consulta en cada
 * cobro. Además se pierde la posibilidad de mirarla en la base cuando algo no
 * cuadra, que es justo cuando hace falta.
 *
 * ── Y por qué no por ambiente ──────────────────────────────────────────────
 * Las credenciales sí se separan entre pruebas y producción, porque son
 * distintas. Esto es una decisión de negocio —«aquí damos 6 meses sin
 * intereses»— y no cambia porque se esté probando. Duplicarla invitaría a
 * probar con una configuración y cobrar con otra.
 *
 * `null` significa «lo que traiga por omisión el catálogo», así que las
 * pasarelas ya configuradas siguen aceptando lo mismo que antes de esta
 * migración.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pasarelas_pago', function (Blueprint $table) {
            $table->json('opciones')->nullable()->after('credenciales_produccion');
        });
    }

    public function down(): void
    {
        Schema::table('pasarelas_pago', function (Blueprint $table) {
            $table->dropColumn('opciones');
        });
    }
};
