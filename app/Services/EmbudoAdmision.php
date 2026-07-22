<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Admisiones\Aspirante;
use App\Models\Admisiones\EtapaCrm;
use App\Models\Identidad\Usuario;
use App\Models\Promocion\SeguimientoAspirante;
use App\Models\Promocion\TipoSeguimiento;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * El embudo de admisión: cuántos prospectos hay en cada etapa, quién les da
 * seguimiento y qué contacto toca hoy.
 *
 * Los conteos se agregan en SQL, no recorriendo aspirantes en PHP: es la
 * pantalla que abre promoción todas las mañanas y una escuela con mil
 * prospectos la volvería inservible.
 */
class EmbudoAdmision
{
    /**
     * Acota la consulta a lo que el usuario alcanza.
     *
     * Un promotor ve SUS prospectos; quien gestiona promoción los ve todos.
     * El alcance sale de la asignación (`aspirante_asesor`), no del permiso:
     * el permiso dice qué puede hacer, la asignación dice sobre quién — la
     * misma regla de dos capas que ya gobierna al docente.
     */
    public function acotar(Builder $consulta, Usuario $usuario): Builder
    {
        if ($usuario->can('gestionar-promocion')) {
            return $consulta;
        }

        $personaId = $usuario->persona_id;

        return $consulta->whereHas(
            'asesores',
            fn ($q) => $q->where('asesores.persona_id', $personaId)
        );
    }

    /**
     * Conteo por etapa, en una sola consulta.
     *
     * Incluye las etapas VACÍAS: un embudo que oculta la etapa donde no hay
     * nadie esconde justo el dato que interesa —dónde se están cayendo—.
     *
     * @return array<int, array<string, mixed>>
     */
    public function porEtapa(Usuario $usuario): array
    {
        $conteos = $this->acotar(Aspirante::query(), $usuario)
            ->select('etapa_crm_id', DB::raw('count(*) as total'))
            ->groupBy('etapa_crm_id')
            ->pluck('total', 'etapa_crm_id');

        return EtapaCrm::query()
            ->orderBy('orden')
            ->get()
            ->map(fn (EtapaCrm $e) => [
                'id' => $e->id,
                'clave' => $e->clave,
                'nombre' => $e->nombre,
                'orden' => $e->orden,
                'total' => (int) ($conteos[$e->id] ?? 0),
            ])
            ->all();
    }

    /**
     * De dónde están llegando. Es lo que responde "¿sirve la campaña?" y lo que
     * separa al que se registró solo del que capturó un promotor.
     *
     * @return array<int, array<string, mixed>>
     */
    public function porOrigen(Usuario $usuario): array
    {
        return $this->acotar(Aspirante::query(), $usuario)
            ->leftJoin('origenes_aspirante as o', 'o.id', '=', 'aspirantes.origen_id')
            ->select(
                DB::raw('coalesce(o.nombre, "Sin origen") as nombre'),
                DB::raw('coalesce(o.autogestivo, 0) as autogestivo'),
                DB::raw('count(*) as total')
            )
            ->groupBy('o.nombre', 'o.autogestivo')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($fila) => [
                'nombre' => $fila->nombre,
                'autogestivo' => (bool) $fila->autogestivo,
                'total' => (int) $fila->total,
            ])
            ->all();
    }

    /**
     * Los prospectos cuyo próximo contacto ya venció o es hoy.
     *
     * Se toma el ÚLTIMO seguimiento con fecha de cada aspirante, no cualquiera:
     * si se marcó "llamar el lunes" y el lunes se registró otra llamada con
     * "llamar el viernes", el lunes ya no debe aparecer como pendiente.
     *
     * @return array<int, array<string, mixed>>
     */
    public function pendientesDeContacto(Usuario $usuario, ?string $fecha = null): array
    {
        $fecha ??= now()->toDateString();

        $ultimos = DB::table('seguimientos_aspirante')
            ->whereNull('deleted_at')
            ->whereNotNull('proximo_contacto')
            ->groupBy('aspirante_id')
            ->select('aspirante_id', DB::raw('max(id) as ultimo_id'));

        $ids = DB::table('seguimientos_aspirante as s')
            ->joinSub($ultimos, 'u', 'u.ultimo_id', '=', 's.id')
            ->whereDate('s.proximo_contacto', '<=', $fecha)
            ->pluck('s.aspirante_id');

        return $this->acotar(Aspirante::query(), $usuario)
            ->with(['persona:id,nombre,primer_apellido,segundo_apellido', 'etapa:id,nombre'])
            ->whereIn('aspirantes.id', $ids)
            ->get()
            ->map(function (Aspirante $a) {
                $ultimo = $a->seguimientos()->whereNotNull('proximo_contacto')->first();

                return [
                    'id' => $a->id,
                    'nombre' => $a->persona?->nombreCompleto(),
                    'etapa' => $a->etapa?->nombre,
                    'proximo_contacto' => $ultimo?->proximo_contacto?->toDateString(),
                    'dias' => $ultimo?->proximo_contacto
                        ? (int) $ultimo->proximo_contacto->diffInDays(now()->startOfDay(), false)
                        : 0,
                ];
            })
            ->sortByDesc('dias')
            ->values()
            ->all();
    }

    /**
     * Registra un contacto y, si se pide, mueve al prospecto de etapa.
     *
     * Las dos cosas van juntas y en una transacción a propósito: mover a un
     * prospecto de etapa sin decir por qué deja un embudo que nadie puede
     * auditar, y es el reclamo clásico de "¿quién lo pasó a documentación si
     * nunca contestó?".
     */
    public function registrarSeguimiento(
        Aspirante $aspirante,
        array $datos,
        ?int $personaId,
    ): SeguimientoAspirante {
        $tipo = $datos['tipo_id'] === null ? null : TipoSeguimiento::find($datos['tipo_id']);

        if ($tipo?->exige_proximo_contacto && empty($datos['proximo_contacto'])) {
            throw new RuntimeException(
                "«{$tipo->nombre}» exige decir cuándo es el siguiente contacto: "
                .'un contacto sin próximo paso es un prospecto que nadie vuelve a marcar.'
            );
        }

        return DB::transaction(function () use ($aspirante, $datos, $personaId, $tipo) {
            $seguimiento = SeguimientoAspirante::create([
                'aspirante_id' => $aspirante->id,
                'tipo_id' => $tipo?->id,
                'persona_id' => $personaId,
                // La etapa se congela como estaba ANTES de mover: es lo que
                // permite medir cuánto tardó en avanzar.
                'etapa_crm_id' => $aspirante->etapa_crm_id,
                'nota' => $datos['nota'],
                'proximo_contacto' => $datos['proximo_contacto'] ?? null,
                'momento' => now(),
            ]);

            if (! empty($datos['etapa_destino_id']) && (int) $datos['etapa_destino_id'] !== $aspirante->etapa_crm_id) {
                $aspirante->update(['etapa_crm_id' => (int) $datos['etapa_destino_id']]);
            }

            return $seguimiento;
        });
    }
}
