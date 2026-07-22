<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\ControlEscolar\ExcepcionCaptura;
use App\Models\ControlEscolar\VentanaCaptura;
use Illuminate\Support\Collection;

/**
 * Decide si un parcial está abierto a captura, y por qué no cuando no lo está.
 *
 * La resolución tiene tres escalones, en este orden:
 *
 *  1. **Sin ventanas configuradas en el ciclo, todo está abierto.** Es
 *     deliberado: una escuela que no quiere gestionar calendario de captura no
 *     debería tener que configurar nada, y los ciclos que ya existían siguen
 *     comportándose igual que antes de que esto existiera.
 *  2. La ventana del parcial, si está activa y la fecha cae dentro.
 *  3. Una excepción vigente para esa materia —y para ese docente, si la
 *     excepción se concedió a alguien en particular—.
 *
 * El "por qué no" importa tanto como el "no": un docente que ve la columna
 * bloqueada sin explicación va a llamar a control escolar.
 */
class CalendarioCaptura
{
    /**
     * Veredicto por parcial para una materia-grupo.
     *
     * La llave es el parcial (o la cadena vacía para los rubros sin parcial,
     * porque un arreglo PHP no admite null como llave).
     *
     * @return array<string, array{abierto: bool, motivo: string|null, ventana: string|null, por_excepcion: bool}>
     */
    public function estadoPorParcial(AsignaturaGrupo $materiaGrupo, ?int $personaId = null, ?string $fecha = null): array
    {
        $cicloId = $materiaGrupo->grupo?->ciclo_id;
        $ventanas = $this->ventanasDel($cicloId);

        $parciales = $this->parcialesDe($materiaGrupo);

        // Escalón 1: el ciclo no gestiona calendario de captura.
        if ($ventanas->isEmpty()) {
            return $parciales
                ->mapWithKeys(fn (?int $p) => [$this->llave($p) => [
                    'abierto' => true,
                    'motivo' => null,
                    'ventana' => null,
                    'por_excepcion' => false,
                ]])
                ->all();
        }

        $excepciones = $this->excepcionesDe($materiaGrupo, $personaId, $fecha);

        return $parciales
            ->mapWithKeys(function (?int $parcial) use ($ventanas, $excepciones, $fecha) {
                $ventana = $ventanas->get($this->llave($parcial));

                // Un corte sin ventana propia, en un ciclo que sí las gestiona,
                // se deja abierto: la escuela configuró unas y no otras, y
                // bloquear lo no configurado sería adivinar su intención.
                if ($ventana === null) {
                    return [$this->llave($parcial) => [
                        'abierto' => true,
                        'motivo' => null,
                        'ventana' => null,
                        'por_excepcion' => false,
                    ]];
                }

                if ($ventana->estaAbierta($fecha)) {
                    return [$this->llave($parcial) => [
                        'abierto' => true,
                        'motivo' => null,
                        'ventana' => $this->rango($ventana),
                        'por_excepcion' => false,
                    ]];
                }

                $excepcion = $excepciones->get($ventana->id);

                if ($excepcion !== null) {
                    return [$this->llave($parcial) => [
                        'abierto' => true,
                        'motivo' => sprintf('Abierto por excepción hasta el %s.', $excepcion->hasta->format('d/m/Y')),
                        'ventana' => $this->rango($ventana),
                        'por_excepcion' => true,
                    ]];
                }

                return [$this->llave($parcial) => [
                    'abierto' => false,
                    'motivo' => $this->motivoDeCierre($ventana, $fecha),
                    'ventana' => $this->rango($ventana),
                    'por_excepcion' => false,
                ]];
            })
            ->all();
    }

    /** ¿Se puede capturar este parcial ahora mismo? */
    public function puedeCapturar(AsignaturaGrupo $materiaGrupo, ?int $parcial, ?int $personaId = null, ?string $fecha = null): bool
    {
        $estado = $this->estadoPorParcial($materiaGrupo, $personaId, $fecha);

        return $estado[$this->llave($parcial)]['abierto'] ?? true;
    }

    /**
     * Parciales cerrados, con su explicación. Para armar el mensaje de error
     * cuando alguien intenta guardar lo que no debería.
     *
     * @return array<int, string>
     */
    public function cerrados(AsignaturaGrupo $materiaGrupo, ?int $personaId = null, ?string $fecha = null): array
    {
        return collect($this->estadoPorParcial($materiaGrupo, $personaId, $fecha))
            ->reject(fn (array $estado) => $estado['abierto'])
            ->map(fn (array $estado) => $estado['motivo'])
            ->values()
            ->all();
    }

    /**
     * Ventanas del ciclo indexadas por parcial.
     *
     * @return Collection<string, VentanaCaptura>
     */
    private function ventanasDel(?int $cicloId): Collection
    {
        if ($cicloId === null) {
            return collect();
        }

        return VentanaCaptura::query()
            ->where('ciclo_id', $cicloId)
            ->get()
            ->keyBy(fn (VentanaCaptura $v) => $this->llave($v->parcial));
    }

    /**
     * Excepciones vigentes que alcanzan a esta persona, por ventana.
     *
     * @return Collection<int, ExcepcionCaptura>
     */
    private function excepcionesDe(AsignaturaGrupo $materiaGrupo, ?int $personaId, ?string $fecha): Collection
    {
        return ExcepcionCaptura::query()
            ->where('asignatura_grupo_id', $materiaGrupo->id)
            ->get()
            ->filter(fn (ExcepcionCaptura $e) => $e->sigueVigente($fecha) && $e->alcanzaA($personaId))
            ->keyBy('ventana_id');
    }

    /**
     * Los cortes que esta materia realmente evalúa, tomados de su esquema. Si
     * no tiene esquema todavía, no hay nada que capturar.
     *
     * @return Collection<int, int|null>
     */
    private function parcialesDe(AsignaturaGrupo $materiaGrupo): Collection
    {
        $materiaGrupo->loadMissing('planMateria.esquemaEvaluacion');

        return ($materiaGrupo->planMateria?->esquemaEvaluacion ?? collect())
            ->pluck('parcial')
            ->unique()
            ->values();
    }

    private function motivoDeCierre(VentanaCaptura $ventana, ?string $fecha): string
    {
        if (! $ventana->activa) {
            return sprintf('La captura de %s está desactivada.', $ventana->etiqueta());
        }

        $fecha ??= now()->toDateString();

        if ($fecha < $ventana->desde->toDateString()) {
            return sprintf('La captura de %s abre el %s.', $ventana->etiqueta(), $ventana->desde->format('d/m/Y'));
        }

        return sprintf('La captura de %s cerró el %s.', $ventana->etiqueta(), $ventana->hasta->format('d/m/Y'));
    }

    private function rango(VentanaCaptura $ventana): string
    {
        return $ventana->desde->format('d/m/Y').' – '.$ventana->hasta->format('d/m/Y');
    }

    /** Un arreglo PHP no admite null como llave; el corte sin parcial usa ''. */
    private function llave(?int $parcial): string
    {
        return $parcial === null ? '' : (string) $parcial;
    }
}
