<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qué se conmemora hoy.
 *
 * ── Por qué una tabla y no una API ─────────────────────────────────────────
 * No hay un servicio en español lo bastante confiable —los que existen mezclan
 * criterios, traducen mal o cambian de dominio— y esto es contenido que se lee
 * en la pantalla de cada alumno: una fecha equivocada es peor que ninguna. Se
 * cataloga y se responde por ello.
 *
 * ── Sin año ────────────────────────────────────────────────────────────────
 * Una efeméride se repite cada año, así que se guarda `mes` y `dia`, no una
 * fecha. El AÑO del hecho —1810, 1910— va aparte y es informativo: sirve para
 * decir «hace 216 años» sin tener que restar de cabeza.
 *
 * ── Editable por la escuela ────────────────────────────────────────────────
 * Vive en el tenant y no en la base central porque cada escuela tiene las
 * suyas: el aniversario del plantel, la fiesta del santo patrono, la semana
 * cultural. El seeder pone las nacionales e internacionales; lo demás lo
 * agrega quien conoce su calendario.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('efemerides', function (Blueprint $table) {
            $table->id();

            $table->unsignedTinyInteger('mes');
            $table->unsignedTinyInteger('dia');

            $table->string('titulo', 180);
            $table->text('descripcion')->nullable();

            // civica | internacional | escolar
            $table->string('tipo', 20)->default('civica');

            /** El año del hecho, si lo tiene: 1810, 1910. Null para los «días de». */
            $table->unsignedSmallInteger('anio_origen')->nullable();

            $table->boolean('activa')->default(true);

            $table->auditoria();

            // La consulta es siempre «¿qué hay el día X del mes Y?».
            $table->index(['mes', 'dia', 'activa']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('efemerides');
    }
};
