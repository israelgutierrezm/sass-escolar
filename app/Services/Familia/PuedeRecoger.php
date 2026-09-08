<?php

declare(strict_types=1);

namespace App\Services\Familia;

use App\Models\Identidad\AutorizadoRecoger;
use App\Models\Identidad\Parentesco;
use App\Models\Identidad\TutorAlumno;

/**
 * ¿Puede esta persona recoger a este alumno, ahora?
 *
 * La regla vive en UN sitio —la preguntan la pantalla de la familia, la del
 * administrador y, en la rebanada 2, la puerta y el registro de salida—. Escrita
 * dos veces, la puerta autorizaría a alguien que la pantalla muestra bloqueado.
 * Es la lección de `estaEnVigor` y de `AlcanceDeExpedientes`.
 *
 * ── El orden importa: el BLOQUEO gana ──────────────────────────────────────
 * La custodia vence a todo, incluido ser tutor: un progenitor legalmente
 * impedido no recoge aunque siga siendo el padre. Por eso el bloqueo se mira
 * PRIMERO, y sólo después se pregunta si es tutor o si está en la lista.
 */
class PuedeRecoger
{
    public const BLOQUEO = 'bloqueo';

    public const TUTOR = 'tutor';

    public const AUTORIZADO = 'autorizado';

    public const NO_ESTA = 'no_esta';

    /**
     * @return array{permitido: bool, razon: string, motivo: ?string}
     */
    public function validar(int $alumnoPersonaId, int $quienPersonaId): array
    {
        // 1. ¿Bloqueado por custodia? Gana sobre todo lo demás.
        $bloqueo = AutorizadoRecoger::query()
            ->where('alumno_persona_id', $alumnoPersonaId)
            ->where('persona_id', $quienPersonaId)
            ->bloquea()
            ->vigentes()
            ->first();

        if ($bloqueo !== null) {
            return ['permitido' => false, 'razon' => self::BLOQUEO, 'motivo' => $bloqueo->motivo];
        }

        // 2. ¿Es tutor del alumno? Autorizado por su vínculo.
        $esTutor = TutorAlumno::query()
            ->where('alumno_persona_id', $alumnoPersonaId)
            ->where('tutor_persona_id', $quienPersonaId)
            ->exists();

        if ($esTutor) {
            return ['permitido' => true, 'razon' => self::TUTOR, 'motivo' => null];
        }

        // 3. ¿Tercero autorizado y vigente?
        $autorizado = AutorizadoRecoger::query()
            ->where('alumno_persona_id', $alumnoPersonaId)
            ->where('persona_id', $quienPersonaId)
            ->autoriza()
            ->vigentes()
            ->exists();

        if ($autorizado) {
            return ['permitido' => true, 'razon' => self::AUTORIZADO, 'motivo' => null];
        }

        return ['permitido' => false, 'razon' => self::NO_ESTA, 'motivo' => null];
    }

    /**
     * Validar por el TOKEN del QR de un tercero. Resuelve la fila y comprueba su
     * vigencia y —si ese tercero tiene cuenta— que no lo haya bloqueado una
     * custodia después: el QR se valida contra el estado ACTUAL, no contra el
     * papel. Devuelve además la fila usada, para el registro de salida.
     *
     * @return array{permitido: bool, razon: string, motivo: ?string, autorizado: ?AutorizadoRecoger}
     */
    public function validarPorToken(int $alumnoPersonaId, string $token): array
    {
        $fila = AutorizadoRecoger::query()
            ->where('alumno_persona_id', $alumnoPersonaId)
            ->where('token', $token)
            ->autoriza()
            ->first();

        if ($fila === null || ! $fila->vigente()) {
            return ['permitido' => false, 'razon' => self::NO_ESTA, 'motivo' => null, 'autorizado' => null];
        }

        // Si el tercero tiene cuenta, un bloqueo de custodia sobre ella gana
        // sobre su propia autorización: se reusa la regla de arriba.
        if ($fila->persona_id !== null) {
            return array_merge($this->validar($alumnoPersonaId, $fila->persona_id), ['autorizado' => $fila]);
        }

        return ['permitido' => true, 'razon' => self::AUTORIZADO, 'motivo' => null, 'autorizado' => $fila];
    }

    /**
     * La lista EFECTIVA de quién puede recoger a un alumno hoy: los tutores no
     * bloqueados y los terceros autorizados vigentes. Es lo que la familia
     * revisa y, en la rebanada 2, lo que el guardia coteja.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listaEfectiva(int $alumnoPersonaId): array
    {
        $bloqueados = AutorizadoRecoger::query()
            ->where('alumno_persona_id', $alumnoPersonaId)
            ->bloquea()
            ->vigentes()
            ->whereNotNull('persona_id')
            ->pluck('persona_id')
            ->all();

        $tutores = TutorAlumno::query()
            ->where('alumno_persona_id', $alumnoPersonaId)
            ->with('tutor:id,nombre,primer_apellido,segundo_apellido')
            ->get()
            ->reject(fn (TutorAlumno $v) => in_array((int) $v->tutor_persona_id, array_map('intval', $bloqueados), true))
            ->map(fn (TutorAlumno $v) => [
                'nombre' => $v->tutor?->nombreCompleto(),
                'parentesco' => Parentesco::nombreDe($v->parentesco_id),
                'origen' => self::TUTOR,
                'vigencia_hasta' => null,
            ])
            ->values();

        $terceros = AutorizadoRecoger::query()
            ->where('alumno_persona_id', $alumnoPersonaId)
            ->autoriza()
            ->vigentes()
            ->with('parentesco:id,nombre')
            ->get()
            ->map(fn (AutorizadoRecoger $a) => [
                'nombre' => $a->nombre,
                'parentesco' => $a->parentesco?->nombre,
                'origen' => self::AUTORIZADO,
                'vigencia_hasta' => $a->vigencia_hasta?->toDateString(),
            ])
            ->values();

        return $tutores->concat($terceros)->all();
    }
}
