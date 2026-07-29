<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ciclo de vida del responsable y guardado opcional del certificado.
 *
 * `activo`: un responsable no se borra cuando su cert vence o cambia la persona
 * —sus firmas quedan ligadas a él para siempre—; se DESACTIVA y se conserva
 * como historial. El límite (1 certificación / 2 titulación) aplica a los
 * ACTIVOS. `cer_pem`: si el usuario pide «Guardar mi .cer», se almacena el
 * certificado (dato público) para no volver a subirlo al firmar; el .key
 * (privado, cifrado) se resolverá cuando se implemente el firmado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('responsables', function (Blueprint $table) {
            $table->boolean('activo')->default(true)->after('titulo_profesional_id');
            $table->text('cer_pem')->nullable()->after('cer_vigencia_fin');
        });
    }

    public function down(): void
    {
        Schema::table('responsables', function (Blueprint $table) {
            $table->dropColumn(['activo', 'cer_pem']);
        });
    }
};
