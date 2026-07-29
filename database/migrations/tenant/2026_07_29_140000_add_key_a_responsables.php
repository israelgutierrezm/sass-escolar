<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Llave privada (.key) del responsable, para firmar más rápido: cargándola una
 * vez, el firmado solo pide la contraseña.
 *
 * Se guarda el .key TAL CUAL (ya viene protegido por su contraseña) y ADEMÁS
 * cifrado en reposo con la app (Crypt). La contraseña NUNCA se almacena: se pide
 * al firmar para abrir la llave. `key_encriptado` es longtext porque el blob
 * cifrado crece respecto al original.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('responsables', function (Blueprint $table) {
            $table->longText('key_encriptado')->nullable()->after('cer_pem');
        });
    }

    public function down(): void
    {
        Schema::table('responsables', function (Blueprint $table) {
            $table->dropColumn('key_encriptado');
        });
    }
};
