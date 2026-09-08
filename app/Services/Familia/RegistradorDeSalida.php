<?php

declare(strict_types=1);

namespace App\Services\Familia;

use App\Enums\DestinoEvento;
use App\Enums\PrioridadAviso;
use App\Exceptions\AvisoParaElUsuario;
use App\Models\Identidad\Persona;
use App\Models\Identidad\SalidaAlumno;
use App\Models\Identidad\Usuario;
use App\Models\Plataforma\Aviso;
use Illuminate\Support\Facades\DB;

/**
 * Registra la ENTREGA de un alumno en la puerta: valida, anota y avisa.
 *
 * ── Valida contra el estado ACTUAL, no contra el papel ─────────────────────
 * Se pregunta a {@see PuedeRecoger} al momento de entregar, así que un QR de
 * alguien a quien la escuela bloqueó por custodia después se rechaza aunque el
 * código siga circulando. La pantalla del guardia no autoriza —eso lo decide el
 * servidor—.
 *
 * ── El aviso a los responsables ────────────────────────────────────────────
 * Va por el canal de AVISOS del portal, no por correo: es el mismo criterio del
 * resto del sistema. Se dirige a la familia del alumno (destino Alumno + el
 * modificador Familiares), que es lo que hace que le llegue a quien responde por
 * él sin exponer nada a nadie más.
 */
class RegistradorDeSalida
{
    public function __construct(private readonly PuedeRecoger $reglas) {}

    /**
     * @param  array{token?: ?string, persona_id?: ?int}  $datos
     *
     * @throws AvisoParaElUsuario 422 si no está autorizado o faltan datos
     */
    public function registrar(Persona $alumno, array $datos, Usuario $guardia): SalidaAlumno
    {
        $token = $datos['token'] ?? null;
        $personaId = $datos['persona_id'] ?? null;

        AvisoParaElUsuario::si(
            $token === null && $personaId === null,
            422,
            'Falta el QR o a quién se le entrega al alumno.',
        );

        if ($token !== null) {
            $v = $this->reglas->validarPorToken($alumno->id, (string) $token);
            $autorizado = $v['autorizado'];
            $recogidoPor = $autorizado?->persona_id;
            $nombre = $autorizado?->nombre ?? 'Desconocido';
            $como = SalidaAlumno::POR_QR;
        } else {
            $v = $this->reglas->validar($alumno->id, (int) $personaId);
            $autorizado = null;
            $recogidoPor = (int) $personaId;
            $nombre = Persona::query()->find($personaId)?->nombreCompleto() ?? 'Desconocido';
            $como = $v['razon'] === PuedeRecoger::TUTOR ? SalidaAlumno::POR_TUTOR : SalidaAlumno::A_MANO;
        }

        AvisoParaElUsuario::aMenosQue(
            $v['permitido'] === true,
            422,
            $v['razon'] === PuedeRecoger::BLOQUEO
                ? 'BLOQUEADO por custodia: '.($v['motivo'] ?? 'no puede recoger a este alumno.')
                : 'Esa persona no está autorizada a recoger a este alumno.',
        );

        return DB::transaction(function () use ($alumno, $recogidoPor, $nombre, $autorizado, $como) {
            $salida = SalidaAlumno::create([
                'alumno_persona_id' => $alumno->id,
                'recogido_por_persona_id' => $recogidoPor,
                'recogido_nombre' => $nombre,
                'autorizado_id' => $autorizado?->id,
                'como' => $como,
            ]);

            $this->avisar($alumno, $nombre);

            return $salida;
        });
    }

    private function avisar(Persona $alumno, string $quien): void
    {
        $aviso = Aviso::create([
            'titulo' => 'Salida registrada: '.$alumno->nombreCompleto(),
            'cuerpo' => $quien.' recogió a '.$alumno->nombreCompleto().' a las '.now()->format('H:i').'.',
            'prioridad' => PrioridadAviso::Importante,
            'publicado' => true,
            'publicado_desde' => now(),
            'vigente_hasta' => now()->addDays(3),
        ]);

        // Al alumno y, con el modificador, a su familia: es a quien responde por
        // él. Familiares no vale solo —lo rechaza `AlMenosUnDestinoReal`—, por eso
        // va junto al destino Alumno.
        $aviso->destinos()->create(['tipo' => DestinoEvento::Alumno, 'destino_id' => $alumno->id]);
        $aviso->destinos()->create(['tipo' => DestinoEvento::Familiares, 'destino_id' => null]);
    }
}
