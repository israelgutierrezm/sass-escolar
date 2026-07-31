<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Configuración del web service de Títulos Electrónicos de la SEP, por escuela
 * (tenant). Es una config ÚNICA (una fila).
 *
 * La SEP entrega DOS juegos de credenciales —pruebas y producción— y cada juego
 * apunta a un endpoint distinto. Aquí se guardan los dos, cifrados, y un
 * interruptor (`etapa_activa`) elige cuál está vigente. Con esa etapa se sella
 * cada lote de titulación al crearse, y antes de enviarlo al WS se valida que la
 * etapa del lote siga coincidiendo con la activa: así un lote armado en producción
 * nunca se manda por error al endpoint de pruebas ni viceversa.
 *
 * Las contraseñas van CIFRADAS (cast `encrypted` en el modelo) y NUNCA se
 * devuelven completas al frontend ni se escriben en logs: solo enmascaradas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('titulacion_ws_config', function (Blueprint $table) {
            $table->id();

            // Etapa vigente: con cuál juego de credenciales/endpoint se opera hoy.
            $table->string('etapa_activa', 12)->default('pruebas'); // pruebas | produccion

            // Credenciales del WS (solo usuario + contraseña, por contrato SEP).
            // La contraseña va cifrada (text porque el valor cifrado es largo).
            $table->string('usuario_pruebas', 150)->nullable();
            $table->text('password_pruebas')->nullable();
            $table->string('usuario_produccion', 150)->nullable();
            $table->text('password_produccion')->nullable();

            // Estado de la última prueba de conexión.
            $table->string('conexion_estado', 12)->nullable(); // ok | error
            $table->text('conexion_mensaje')->nullable();
            $table->timestamp('conexion_probada_en')->nullable();

            $table->auditoria();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('titulacion_ws_config');
    }
};
