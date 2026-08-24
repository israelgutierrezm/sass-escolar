<?php

declare(strict_types=1);

namespace Database\Seeders\Tenant;

use App\Models\Disciplina\TipoIncidencia;
use App\Models\Disciplina\TipoSancion;
use Illuminate\Database\Seeder;

/**
 * Catálogos TENANT-CONFIG de disciplina. Idempotente por clave.
 *
 * Se siembran UNOS POCOS y comunes, no un régimen disciplinario completo: cada
 * escuela edita los suyos desde la pantalla. Lo que importa es que las banderas
 * de comportamiento —`nivel` en la incidencia, `tiene_vigencia` en la sanción—
 * lleguen sembradas con valores que tengan sentido.
 */
class CatalogosDisciplinaSeeder extends Seeder
{
    public function run(): void
    {
        $incidencias = [
            ['clave' => 'retardo', 'nombre' => 'Retardo', 'nivel' => 1, 'orden' => 1],
            ['clave' => 'sin_material', 'nombre' => 'Sin material de trabajo', 'nivel' => 1, 'orden' => 2],
            ['clave' => 'falta_respeto', 'nombre' => 'Falta de respeto', 'nivel' => 2, 'orden' => 3],
            ['clave' => 'dano_instalaciones', 'nombre' => 'Daño a las instalaciones', 'nivel' => 3, 'orden' => 4],
        ];

        foreach ($incidencias as $fila) {
            TipoIncidencia::query()->updateOrCreate(
                ['clave' => $fila['clave']],
                ['nombre' => $fila['nombre'], 'nivel' => $fila['nivel'], 'orden' => $fila['orden']],
            );
        }

        $sanciones = [
            ['clave' => 'amonestacion', 'nombre' => 'Amonestación', 'tiene_vigencia' => false, 'orden' => 1],
            ['clave' => 'reporte', 'nombre' => 'Reporte a expediente', 'tiene_vigencia' => false, 'orden' => 2],
            ['clave' => 'suspension', 'nombre' => 'Suspensión', 'tiene_vigencia' => true, 'orden' => 3],
        ];

        foreach ($sanciones as $fila) {
            TipoSancion::query()->updateOrCreate(
                ['clave' => $fila['clave']],
                ['nombre' => $fila['nombre'], 'tiene_vigencia' => $fila['tiene_vigencia'], 'orden' => $fila['orden']],
            );
        }
    }
}
