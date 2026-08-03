<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Capturas de pantalla durante un examen.
 *
 * ── Lo que un navegador SÍ puede y lo que NO ───────────────────────────────
 * No existe forma de impedir una captura de pantalla desde una página web. El
 * sistema operativo la toma sin preguntarle al navegador, y contra una cámara
 * de celular no hay nada que hacer. Cualquiera que prometa bloquearla está
 * describiendo una disuasión, no un candado.
 *
 * Lo que sí se puede es (1) estorbar —tapar el examen cuando la ventana pierde
 * el foco, quitar el menú contextual, marcar la pantalla con la matrícula de
 * quien la está viendo, para que una captura filtrada delate a su autor— y
 * (2) DEJAR CONSTANCIA de las señales que el navegador sí ve: la tecla Impr
 * Pant y los atajos de captura de macOS.
 *
 * De ahí el reparto: `permite_captura` decide si se estorba, y las capturas se
 * cuentan SIEMPRE. Que estuvieran permitidas no vuelve el dato inútil —el
 * docente sigue queriendo saber quién fotografió su examen—; que estuvieran
 * prohibidas es justamente cuando más importa.
 *
 * `permite_captura` arranca en `false` porque un examen es material que no
 * debería salir del aula: lo seguro tiene que ser lo que pasa si nadie toca
 * nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('examenes', function (Blueprint $table) {
            $table->boolean('permite_captura')->default(false)->after('barajar_opciones');
        });

        Schema::table('intentos', function (Blueprint $table) {
            /*
             * El contador va aparte del detalle a propósito: la pantalla del
             * docente lista treinta intentos y sólo necesita el número. Meterlo
             * dentro del JSON obligaría a abrir y contar treinta documentos
             * para pintar una columna.
             */
            $table->unsignedSmallInteger('capturas_detectadas')->default(0)->after('orden_reactivos');

            // Cuándo y con qué señal. Sirve para distinguir un resbalón —una
            // sola, al principio— de quien se dedicó a fotografiar el examen.
            $table->json('capturas')->nullable()->after('capturas_detectadas');
        });
    }

    public function down(): void
    {
        Schema::table('examenes', function (Blueprint $table) {
            $table->dropColumn('permite_captura');
        });

        Schema::table('intentos', function (Blueprint $table) {
            $table->dropColumn(['capturas_detectadas', 'capturas']);
        });
    }
};
