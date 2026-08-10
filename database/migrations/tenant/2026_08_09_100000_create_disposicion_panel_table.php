<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cómo acomodó CADA PERSONA las tarjetas de su panel, para cada uno de sus
 * perfiles.
 *
 * ── Por qué la llave es (usuario, rol) y no sólo el usuario ────────────────
 * El panel ya cambia según el perfil con el que se opera: `tarjetas_rol` decide
 * qué tarjetas enciende cada rol y los permisos filtran el resto. Alguien que
 * es coordinadora por la mañana y docente por la tarde ve dos paneles distintos,
 * y acomodar uno no debería descolocar el otro. Con la llave puesta sólo en el
 * usuario, cambiar de perfil arrastraría un orden pensado para tarjetas que en
 * el otro ni salen.
 *
 * ── Por qué esta tabla NO lleva `auditoria()` ──────────────────────────────
 * Es la única del proyecto que se guarda reemplazando entera: al soltar una
 * tarjeta se borra la disposición del perfil y se escribe la nueva. Con borrado
 * lógico, cada arrastre dejaría una fila muerta para siempre y la tabla crecería
 * sin tope. Y no hay nada que auditar: quién movió su propia tarjeta no le
 * importa a nadie, y el dato no es reconstruible ni reclamable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disposicion_panel', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->constrained('usuarios')->cascadeOnDelete();

            // Nulo = la disposición de quien todavía no eligió perfil. Se borra
            // con el rol porque una disposición de un rol que ya no existe no
            // se puede volver a aplicar nunca.
            $table->foreignId('rol_id')->nullable()->constrained('roles')->cascadeOnDelete();

            // La clave de la tarjeta, no una llave foránea: las tarjetas son
            // clases registradas en el contenedor, no filas. Si mañana se retira
            // una, su renglón aquí simplemente deja de encontrar pareja y se
            // ignora —ver `DisposicionDelPanel`.
            $table->string('clave', 50);

            $table->unsignedSmallInteger('orden');

            // 2 o 4 de las cuatro columnas del panel: el tamaño normal y el
            // doble. Se acota además en el servidor al guardar.
            $table->unsignedTinyInteger('ancho');

            $table->timestamps();

            $table->unique(['usuario_id', 'rol_id', 'clave']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disposicion_panel');
    }
};
