<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El ciclo de vida del ACCESO de un supervisor externo, en su contacto.
 *
 * ── Por qué aquí y no en una tabla nueva ──────────────────────────────────
 * El expediente ya apunta a su supervisor por `contacto_supervisor_id`, y el
 * contacto ya trae `persona_id` opcional —dejado a propósito «para que, cuando
 * llegue su portal, sea esta columna la que lo haga posible sin cambiar nada
 * más»—. El acceso es UNO por contacto, así que sus fechas viven en el
 * contacto: una tabla aparte sería un segundo sitio donde buscar lo mismo, la
 * lección que este módulo ya escribió al no partir `organizacion_contactos`.
 *
 * ── Qué NO se reinventa ───────────────────────────────────────────────────
 * La cuenta la crea `AprovisionadorAcceso` (Usuario con `acceso_configurado`
 * en falso); la activa el flujo `password.reset` que ya existe; el rol lo apaga
 * `persona_rol.activo`. Lo único que falta es la VIGENCIA —desde cuándo y hasta
 * cuándo vale— y la marca de revocación, que no caben en ninguna de esas
 * piezas.
 *
 * `acceso_hasta` NULL = sin fecha de término (vale hasta que se revoque). Que
 * sea NULL es un hecho con significado —«sin vencimiento»— y no un descuido.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Comprobar antes de actuar, POR COLUMNA: un reintento tras un fallo
        // parcial no debe chocar contra su propio trabajo.
        Schema::table('organizacion_contactos', function (Blueprint $tabla): void {
            if (! Schema::hasColumn('organizacion_contactos', 'invitado_en')) {
                $tabla->timestamp('invitado_en')->nullable()->after('persona_id');
            }

            if (! Schema::hasColumn('organizacion_contactos', 'acceso_desde')) {
                $tabla->date('acceso_desde')->nullable()->after('invitado_en');
            }

            if (! Schema::hasColumn('organizacion_contactos', 'acceso_hasta')) {
                $tabla->date('acceso_hasta')->nullable()->after('acceso_desde');
            }

            if (! Schema::hasColumn('organizacion_contactos', 'acceso_revocado_en')) {
                $tabla->timestamp('acceso_revocado_en')->nullable()->after('acceso_hasta');
            }

            if (! Schema::hasColumn('organizacion_contactos', 'acceso_revocado_por')) {
                // Un usuario del tenant; sin FK cruzada, como el resto de las
                // referencias a quién hizo qué en este proyecto.
                $tabla->unsignedBigInteger('acceso_revocado_por')->nullable()->after('acceso_revocado_en');
            }
        });
    }

    public function down(): void
    {
        Schema::table('organizacion_contactos', function (Blueprint $tabla): void {
            foreach (['invitado_en', 'acceso_desde', 'acceso_hasta', 'acceso_revocado_en', 'acceso_revocado_por'] as $col) {
                if (Schema::hasColumn('organizacion_contactos', $col)) {
                    $tabla->dropColumn($col);
                }
            }
        });
    }
};
