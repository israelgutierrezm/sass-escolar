<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El PRERREQUISITO de una actividad: otra actividad del MISMO curso que hay que
 * completar antes de que ésta se abra al alumno.
 *
 * ── Por qué una columna auto-referencial y no un catálogo ni JSON ──────────
 * El prerrequisito es una relación entre DOS actividades del mismo curso, así
 * que su sitio natural es una foránea a la propia tabla. En `config` (JSON) no
 * se podría remapear al copiar la plantilla a un grupo —cada actividad estrena
 * id— ni la base garantizaría que apunte a algo que existe.
 *
 * ── `nullOnDelete`: borrar el prerrequisito ABRE la actividad ──────────────
 * Si se elimina de verdad la actividad que servía de llave, la que dependía de
 * ella se queda sin candado en vez de quedar bloqueada para siempre. En
 * contenido de aprendizaje, fallar ABIERTO es el lado correcto: nunca se
 * encierra a un alumno por un borrado. (Las actividades usan borrado LÓGICO, así
 * que esto sólo salta en un borrado físico; el candado también se ignora cuando
 * el prerrequisito está oculto o dado de baja, y eso lo decide el servicio.)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('actividades', 'prerequisito_id')) {
            return;
        }

        Schema::table('actividades', function (Blueprint $tabla): void {
            $tabla->foreignId('prerequisito_id')
                ->nullable()
                ->after('orden')
                ->constrained('actividades')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('actividades', 'prerequisito_id')) {
            return;
        }

        Schema::table('actividades', function (Blueprint $tabla): void {
            $tabla->dropConstrainedForeignId('prerequisito_id');
        });
    }
};
