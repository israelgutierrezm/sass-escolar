<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El correo es la credencial de acceso: dos usuarios con el mismo correo cruzan
 * sesiones en el login. Hasta ahora `usuarios.email` solo estaba indexado, no
 * era único, y el alta de aspirante no lo verificaba, así que se colaban
 * duplicados (p. ej. un aspirante capturado con el correo del administrador).
 *
 * Antes de poner el índice único se limpian los duplicados que ya existan:
 * se conserva la cuenta con acceso configurado (o la más antigua) y a las demás
 * se les quita el correo —siguen existiendo como cuenta de censo, pero ya no
 * cargan un correo ajeno—, también en su registro de `personas` para no
 * repropagarlo. En MySQL un índice único permite múltiples NULL, así que las
 * cuentas sin correo conviven sin problema.
 */
return new class extends Migration
{
    public function up(): void
    {
        $duplicados = DB::table('usuarios')
            ->whereNotNull('email')
            ->select('email', DB::raw('count(*) as total'))
            ->groupBy('email')
            ->having('total', '>', 1)
            ->pluck('email');

        foreach ($duplicados as $email) {
            $cuentas = DB::table('usuarios')
                ->where('email', $email)
                ->orderByDesc('acceso_configurado') // la que ya tiene acceso manda
                ->orderBy('id')                     // a igualdad, la más antigua
                ->get(['id', 'persona_id']);

            // La primera se queda con el correo; a las demás se les quita.
            foreach ($cuentas->slice(1) as $cuenta) {
                DB::table('usuarios')->where('id', $cuenta->id)->update(['email' => null]);
                DB::table('personas')->where('id', $cuenta->persona_id)->where('email', $email)->update(['email' => null]);
            }
        }

        Schema::table('usuarios', function (Blueprint $tabla) {
            // El correo dejó de indexarse a secas: ahora es único (entre no-nulos).
            $tabla->dropIndex(['email']);
            $tabla->unique('email');
        });
    }

    public function down(): void
    {
        Schema::table('usuarios', function (Blueprint $tabla) {
            $tabla->dropUnique(['email']);
            $tabla->index('email');
        });
    }
};
