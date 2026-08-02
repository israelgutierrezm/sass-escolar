<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El calendario de la escuela: avisos, feriados, recesos y eventos.
 *
 * ── Dos tablas y no una ────────────────────────────────────────────────────
 * El evento dice QUÉ pasa y CUÁNDO; los destinos dicen A QUIÉN. Se separan
 * porque un mismo aviso puede ir a varios públicos a la vez —«a los del campus
 * norte y además al grupo A»— y con columnas fijas (campus_id, carrera_id…)
 * cada evento quedaría atado a una sola combinación.
 *
 * Los destinos se SUMAN: basta encajar en uno para ver el evento. Cruzarlos
 * dejaría casi todos los avisos sin público.
 *
 * ── Sin llaves foráneas en el destino ──────────────────────────────────────
 * `destino_id` apunta a tablas distintas según `tipo` —campus, carreras,
 * grupos, personas…—, así que no puede llevar una FK. Es el precio de que la
 * segmentación crezca sin migrar: agregar «por turno» mañana es una fila más en
 * el enum, no una columna nueva.
 *
 * A cambio, el destino puede quedar apuntando a algo borrado. Se resuelve al
 * leer: lo que no existe simplemente no alcanza a nadie, y la pantalla lo
 * muestra como destino desconocido en vez de reventar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eventos_calendario', function (Blueprint $table) {
            $table->id();

            $table->string('tipo', 30);
            $table->string('titulo', 180);
            $table->text('descripcion')->nullable();

            /*
             * Fecha y hora separadas del «todo el día».
             *
             * Un feriado no tiene hora y una ceremonia sí. Guardar siempre
             * datetime y marcar con un booleano cuáles ignoran la hora evita
             * dos columnas que se contradicen entre sí.
             */
            $table->dateTime('inicia_en');
            $table->dateTime('termina_en')->nullable();
            $table->boolean('todo_el_dia')->default(true);

            // Se guarda aunque el tipo ya lo implique: una escuela puede
            // declarar un evento suyo como día sin clases sin que sea feriado.
            $table->boolean('no_laborable')->default(false);

            // En borrador no lo ve nadie: se arma el calendario del año y se
            // publica cuando está listo.
            $table->boolean('publicado')->default(true);

            $table->auditoria();

            // La agenda siempre se pide por rango de fechas.
            $table->index(['inicia_en', 'publicado']);
        });

        Schema::create('evento_destinos', function (Blueprint $table) {
            $table->id();

            $table->foreignId('evento_id')->constrained('eventos_calendario')->cascadeOnDelete();

            $table->string('tipo', 20);
            $table->unsignedBigInteger('destino_id')->nullable();

            $table->timestamps();

            // Resolver «qué eventos me tocan» entra por aquí.
            $table->index(['tipo', 'destino_id']);
            $table->unique(['evento_id', 'tipo', 'destino_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evento_destinos');
        Schema::dropIfExists('eventos_calendario');
    }
};
