<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La VIGENCIA de una autorización y su REVOCACIÓN.
 *
 * ── El hueco que cierra ─────────────────────────────────────────────────────
 * Hasta hoy `fecha_limite` era el plazo para CONTESTAR, y una autorización
 * concedida contaba para siempre. Faltaban dos cosas distintas:
 *
 *  - `vigencia_hasta` (nullable): hasta cuándo VALE lo concedido. NULL = sin
 *    caducidad —un consentimiento permanente, como el uso de imagen—; con fecha
 *    —una salida— deja de contar al pasar ese día, sin que nadie haga nada.
 *    Es OTRA cosa que `fecha_limite`: se puede contestar a tiempo algo que vale
 *    sólo un día.
 *  - `revocada_en` (nullable): cuándo la familia la RETIRÓ. Distinguirla de una
 *    negada importa: negar es no haber concedido nunca; revocar es quitar algo
 *    que se concedió, y el docblock del modelo ya prometía que «un consentimiento
 *    de uso de imagen se revoca» sin que hubiera con qué.
 *
 * El «quién» de la revocación lo lleva `updated_by` (auditoría): quien retira es
 * quien edita, así que una columna aparte sería un segundo dato de lo mismo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('autorizaciones', function (Blueprint $tabla): void {
            if (! Schema::hasColumn('autorizaciones', 'vigencia_hasta')) {
                $tabla->date('vigencia_hasta')->nullable()->after('fecha_limite');
            }

            if (! Schema::hasColumn('autorizaciones', 'revocada_en')) {
                $tabla->timestamp('revocada_en')->nullable()->after('fecha_respuesta');
            }
        });
    }

    public function down(): void
    {
        Schema::table('autorizaciones', function (Blueprint $tabla): void {
            foreach (['vigencia_hasta', 'revocada_en'] as $col) {
                if (Schema::hasColumn('autorizaciones', $col)) {
                    $tabla->dropColumn($col);
                }
            }
        });
    }
};
