<?php

declare(strict_types=1);

namespace App\Services\Plataforma;

use App\Models\Plataforma\Modulo;
use Illuminate\Support\Facades\DB;

/**
 * Qué módulos tiene encendidos esta escuela.
 *
 * ── Por qué apagado es lo que se supone cuando no hay fila ─────────────────
 * `modulos_activos` se llena cuando alguien enciende algo, no al crear la
 * escuela, así que la ausencia de fila es el estado normal de un módulo que
 * nadie ha prendido. Suponer «encendido» ahí convertiría cualquier módulo nuevo
 * en algo que aparece solo en todas las escuelas el día que se despliega. Se
 * falla cerrado, y el que deba nacer prendido lo dice su migración.
 *
 * Se resuelve TODO el mapa en una consulta y se recuerda durante la petición:
 * el middleware pregunta por una clave, pero el menú lateral pregunta por varias
 * en la misma pantalla y no tiene sentido ir a la base una vez por cada una.
 */
class ModulosDeLaEscuela
{
    /** @var array<string, bool>|null */
    private ?array $mapa = null;

    public function activo(string $clave): bool
    {
        return $this->mapa()[$clave] ?? false;
    }

    /**
     * Enciende o apaga un módulo.
     *
     * `updateOrInsert` y no un modelo Eloquent porque `modulos_activos` tiene
     * como llave primaria el `modulo_id` y no un autoincremental: un `create`
     * sobre una fila existente reventaría por clave duplicada en vez de
     * actualizarla.
     */
    public function cambiar(string $clave, bool $activo): void
    {
        $moduloId = Modulo::query()->where('clave', $clave)->value('id');

        if ($moduloId === null) {
            return;
        }

        DB::table('modulos_activos')->updateOrInsert(
            ['modulo_id' => $moduloId],
            ['activo' => $activo, 'updated_at' => now(), 'created_at' => now()],
        );

        $this->mapa = null;
    }

    /**
     * El catálogo completo con su estado, para la pantalla de administración.
     *
     * @return array<int, array{clave: string, nombre: string, activo: bool}>
     */
    public function catalogo(): array
    {
        $estados = $this->mapa();

        return Modulo::query()
            ->orderBy('nombre')
            ->get(['clave', 'nombre'])
            ->map(fn (Modulo $m) => [
                'clave' => $m->clave,
                'nombre' => $m->nombre,
                'activo' => $estados[$m->clave] ?? false,
            ])
            ->all();
    }

    /** @return array<string, bool> */
    private function mapa(): array
    {
        return $this->mapa ??= DB::table('modulos')
            ->leftJoin('modulos_activos', 'modulos_activos.modulo_id', '=', 'modulos.id')
            ->whereNull('modulos.deleted_at')
            ->pluck('modulos_activos.activo', 'modulos.clave')
            ->map(fn ($activo) => (bool) $activo)
            ->all();
    }
}
