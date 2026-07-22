<?php

declare(strict_types=1);

use App\Models\Identidad\Rol;
use App\Support\CatalogoPermisos;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;

/**
 * Retira los permisos que un rol tenía FUERA de su faceta.
 *
 * La regla —un permiso pertenece al oficio que lo ejerce— se aplicó al catálogo
 * y a la pantalla, pero **lo ya concedido siguió concedido**: una regla nueva no
 * deshace sola lo que se guardó bajo la anterior. En la escuela de prueba,
 * dirección general había recibido `ver-mis-materias`, `editar-mi-expediente` y
 * `llenar-mi-solicitud` desde la pantalla, que es exactamente el caso que el
 * cliente reportó.
 *
 * No es cosmético: esos permisos abren pantallas cuyo alcance NO sale del
 * permiso sino de una asignación —estar en `docentes`, tener un aspirante—. Un
 * administrativo con `ver-mis-materias` no ve «sus» materias, porque no tiene:
 * ve una pantalla vacía, o las de todos si algún filtro fallara. Quitarlos
 * arregla un permiso que no podía ejercerse bien de ninguna manera.
 *
 * También mueve `coordinador_academia` de la faceta DOCENTE a ADMINISTRATIVO.
 * Estaba mal colgado desde el principio y solo se notó al aplicar la regla:
 * todos sus permisos son de gestión (catálogo académico, abrir grupos, ver
 * docentes) y ninguno es de impartir clase. Quien coordina y además da clase
 * tiene los dos roles y conmuta.
 *
 * Idempotente: correrla dos veces no quita nada de más.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Primero se recoloca el rol: su ámbito cambia y con él qué le sobra.
        $administrativo = Rol::query()->where('name', 'administrativo')->value('id');
        $coordinador = Rol::query()->where('name', 'coordinador_academia')->first();

        if ($administrativo !== null && $coordinador !== null && $coordinador->rol_padre_id !== $administrativo) {
            $coordinador->update(['rol_padre_id' => $administrativo]);
        }

        foreach (Rol::query()->get() as $rol) {
            $ambito = $rol->ambitoDePermisos();

            $sobran = $rol->permissions
                ->pluck('name')
                ->reject(fn (string $p) => CatalogoPermisos::correspondeA($p, $ambito));

            if ($sobran->isEmpty()) {
                continue;
            }

            // `revokePermissionTo` en vez de `syncPermissions`: se quita lo que
            // sobra sin volver a escribir lo demás, así no se pierde nada que
            // la escuela hubiera configurado a mano y sí corresponda.
            foreach ($sobran as $permiso) {
                $rol->revokePermissionTo($permiso);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * No se restauran: eran concesiones que la regla considera inválidas y que
     * además no funcionaban. Volver atrás es re-sembrar con `PermisoSeeder`.
     */
    public function down(): void
    {
        // Nada que deshacer a propósito. Ver la nota de arriba.
    }
};
