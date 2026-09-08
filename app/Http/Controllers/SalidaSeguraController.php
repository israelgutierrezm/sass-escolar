<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\AvisoParaElUsuario;
use App\Models\Identidad\AutorizadoRecoger;
use App\Models\Identidad\Parentesco;
use App\Models\Identidad\Persona;
use App\Models\Identidad\TutorAlumno;
use App\Models\Identidad\Usuario;
use App\Services\Familia\PuedeRecoger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Salida segura, rebanada 1: quién puede recoger a un alumno.
 *
 * Ver `docs/plan-salida-segura.md`. La FAMILIA agrega y retira TERCEROS de sus
 * hijos; la ESCUELA registra los BLOQUEOS de custodia. Dos actores, dos puertas:
 * un familiar no declara una restricción legal sobre otro, y la escuela no
 * inventa a la abuela que la familia autoriza.
 */
class SalidaSeguraController extends Controller
{
    public function __construct(private readonly PuedeRecoger $reglas) {}

    // ── Lado de la FAMILIA ──────────────────────────────────────────────────

    /** La familia autoriza a un tercero a recoger a SU hijo. */
    public function agregarTercero(Request $peticion, Persona $hijo): RedirectResponse
    {
        $this->exigirQueSeaSuHijo($peticion, $hijo);

        $datos = $peticion->validate([
            'nombre' => ['required', 'string', 'max:180'],
            'identificacion' => ['nullable', 'string', 'max:120'],
            'parentesco_id' => ['nullable', 'integer', 'exists:parentescos,id'],
            'vigencia_desde' => ['nullable', 'date'],
            'vigencia_hasta' => ['nullable', 'date', 'after_or_equal:vigencia_desde'],
        ]);

        AutorizadoRecoger::create(array_merge($datos, [
            'alumno_persona_id' => $hijo->id,
            'permitido' => true,
        ]));

        return back(303)->with('exito', 'Autorizaste a '.$datos['nombre'].' a recoger a tu hijo.');
    }

    /**
     * La familia retira a un tercero que autorizó.
     *
     * Sólo a un TERCERO suyo: no un bloqueo de custodia (eso lo pone y quita la
     * escuela), y no de un alumno que no es su hijo.
     */
    public function quitarTercero(Request $peticion, AutorizadoRecoger $autorizado): RedirectResponse
    {
        $this->exigirQueSeaSuHijo($peticion, $autorizado->alumno_persona_id);

        AvisoParaElUsuario::aMenosQue(
            $autorizado->permitido === true,
            404,
            'Eso no es un autorizado tuyo.',
        );

        $autorizado->delete();

        return back(303)->with('exito', 'Retiraste la autorización.');
    }

    // ── Lado de la ESCUELA (custodia) ───────────────────────────────────────

    /** El panel del administrador: buscar un alumno y ver quién lo recoge. */
    public function panel(): Response
    {
        return Inertia::render('Plataforma/SalidaSegura', [
            'parentescos' => Parentesco::query()->orderBy('nombre')->get(['id', 'nombre']),
        ]);
    }

    /** La ficha de un alumno: lista efectiva, terceros y bloqueos. */
    public function alumno(Persona $alumno): Response
    {
        return Inertia::render('Plataforma/SalidaSegura', [
            'parentescos' => Parentesco::query()->orderBy('nombre')->get(['id', 'nombre']),
            'alumno' => [
                'id' => $alumno->id,
                'nombre' => $alumno->nombreCompleto(),
            ],
            'efectiva' => $this->reglas->listaEfectiva($alumno->id),
            'terceros' => AutorizadoRecoger::query()
                ->where('alumno_persona_id', $alumno->id)->autoriza()
                ->with('parentesco:id,nombre')->orderBy('nombre')->get()
                ->map(fn (AutorizadoRecoger $a) => [
                    'id' => $a->id,
                    'nombre' => $a->nombre,
                    'identificacion' => $a->identificacion,
                    'parentesco' => $a->parentesco?->nombre,
                    'vigencia_hasta' => $a->vigencia_hasta?->toDateString(),
                    'vigente' => $a->vigente(),
                ])->values(),
            // Los tutores, para poder BLOQUEAR a uno (custodia).
            'tutores' => TutorAlumno::query()
                ->where('alumno_persona_id', $alumno->id)
                ->with('tutor:id,nombre,primer_apellido,segundo_apellido')->get()
                ->map(fn (TutorAlumno $v) => [
                    'persona_id' => $v->tutor_persona_id,
                    'nombre' => $v->tutor?->nombreCompleto(),
                    'parentesco' => Parentesco::nombreDe($v->parentesco_id),
                ])->values(),
            'bloqueos' => AutorizadoRecoger::query()
                ->where('alumno_persona_id', $alumno->id)->bloquea()
                ->with('persona:id,nombre,primer_apellido,segundo_apellido')->get()
                ->map(fn (AutorizadoRecoger $b) => [
                    'id' => $b->id,
                    'nombre' => $b->persona?->nombreCompleto() ?? $b->nombre,
                    'motivo' => $b->motivo,
                    'vigencia_hasta' => $b->vigencia_hasta?->toDateString(),
                ])->values(),
        ]);
    }

    /** La escuela BLOQUEA a alguien (custodia): no recoge aunque sea tutor. */
    public function bloquear(Request $peticion, Persona $alumno): RedirectResponse
    {
        $datos = $peticion->validate([
            'persona_id' => ['required', 'integer', 'exists:personas,id'],
            'motivo' => ['required', 'string', 'max:500'],
            'vigencia_hasta' => ['nullable', 'date', 'after_or_equal:today'],
        ], [
            'motivo.required' => 'El bloqueo de custodia necesita su motivo: sin él nadie puede explicar por qué se rechaza a esa persona.',
        ]);

        $nombre = Persona::query()->find($datos['persona_id'])?->nombreCompleto() ?? 'Sin nombre';

        AutorizadoRecoger::create([
            'alumno_persona_id' => $alumno->id,
            'persona_id' => $datos['persona_id'],
            'nombre' => $nombre,
            'permitido' => false,
            'motivo' => $datos['motivo'],
            'vigencia_hasta' => $datos['vigencia_hasta'] ?? null,
        ]);

        return back(303)->with('exito', 'Se registró el bloqueo de custodia.');
    }

    /** La escuela retira un bloqueo. */
    public function desbloquear(AutorizadoRecoger $bloqueo): RedirectResponse
    {
        AvisoParaElUsuario::aMenosQue($bloqueo->permitido === false, 404, 'Eso no es un bloqueo.');

        $bloqueo->delete();

        return back(303)->with('exito', 'Se retiró el bloqueo.');
    }

    /**
     * @param  Persona|int  $hijo
     */
    private function exigirQueSeaSuHijo(Request $peticion, $hijo): void
    {
        /** @var Usuario $usuario */
        $usuario = $peticion->user();
        $hijoId = $hijo instanceof Persona ? $hijo->id : (int) $hijo;

        $suyo = $usuario->persona_id !== null
            && TutorAlumno::query()
                ->where('alumno_persona_id', $hijoId)
                ->where('tutor_persona_id', $usuario->persona_id)
                ->exists();

        // 404 y no 403: un id ajeno no debe confirmar siquiera que ese alumno
        // existe. Misma salvaguarda que el resto del portal de la familia.
        abort_unless($suyo, 404);
    }
}
