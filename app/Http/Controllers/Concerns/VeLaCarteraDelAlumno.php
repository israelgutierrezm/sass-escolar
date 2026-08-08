<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Exceptions\AvisoParaElUsuario;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Identidad\TutorAlumno;
use App\Support\CatalogoPermisos;
use Illuminate\Http\Request;

/**
 * Quién puede ver —y ahora pagar— la cuenta de qué alumno.
 *
 * ── Por qué es un trait y no está suelto en un controlador ─────────────────
 * Esta decisión ya estuvo escrita dos veces y las dos copias se pudrieron en
 * direcciones opuestas: el listado comparaba la faceta contra `'padre'` y
 * `'tutor'`, que no son los valores reales, así que un padre de familia veía la
 * cartera COMPLETA de la escuela; el detalle escribía bien las etiquetas pero
 * exigía que la matrícula fuera de SU persona, y un padre nunca es titular de la
 * matrícula de su hijo, así que le cerraba la cuenta que venía a consultar.
 *
 * Al aparecer el cobro en línea la regla haría falta en un tercer sitio, y un
 * tercer sitio es una tercera forma de que se descomponga: pagar es una acción
 * más delicada que mirar, y la respuesta a «¿de quién es esta cuenta?» tiene que
 * ser exactamente la misma.
 *
 * Quien use este trait debe usar también `AcotaPorCampus`: la pregunta se
 * responde en dos capas —de quién es la cuenta y qué campus administra quien
 * pregunta— y las dos tienen que pasar.
 */
trait VeLaCarteraDelAlumno
{
    /** De quién es la cartera que se mira. Recorta la consulta y titula la pantalla. */
    private const ALCANCE_ESCUELA = 'escuela';

    private const ALCANCE_PROPIO = 'propio';

    private const ALCANCE_FAMILIA = 'familia';

    private const ALCANCE_NINGUNO = 'ninguno';

    /**
     * Qué matrículas puede ver quien está preguntando.
     *
     * `null` significa TODAS —el personal de la escuela—, y de ahí en adelante
     * la acotación por campus hace lo suyo. Un arreglo es la lista exacta, y uno
     * vacío es «ninguna», que es una respuesta legítima.
     *
     * ── El padre ve por VÍNCULO, no por titularidad ────────────────────────
     * Y sólo aquellos hijos cuyo vínculo trae `puede_ver_finanzas`. Ese flag ya
     * existía y ya se respeta en su portal; es la respuesta a esta pregunta, no
     * una regla nueva. Un padre al que se le dio acceso académico pero no
     * financiero no debe ver saldos por entrar desde otra pantalla.
     *
     * ── Y lo que no está enumerado, no ve ──────────────────────────────────
     * El `default` deja fuera al docente, al aspirante y al tutor educativo
     * —ninguno tiene `ver-adeudos` sembrado, así que hoy ni siquiera llegan—,
     * pero sobre todo deja fuera a la faceta que alguien agregue mañana. La
     * versión anterior fallaba abierta: lo que no reconocía, lo mostraba todo.
     *
     * @return array<int, int>|null
     */
    private function matriculasVisibles(Request $request): ?array
    {
        $usuario = $request->user();

        return match ($this->alcance($request)) {
            self::ALCANCE_ESCUELA => null,

            self::ALCANCE_PROPIO => MatriculaOferta::query()
                ->where('persona_id', $usuario->persona_id)
                ->pluck('id')->all(),

            self::ALCANCE_FAMILIA => MatriculaOferta::query()
                ->whereIn('persona_id', TutorAlumno::query()
                    ->where('tutor_persona_id', $usuario->persona_id)
                    ->where('puede_ver_finanzas', true)
                    ->select('alumno_persona_id'))
                ->pluck('id')->all(),

            default => [],
        };
    }

    /**
     * De quién es la cartera que se está mirando.
     *
     * Viaja a la pantalla además de recortar la consulta, porque el encabezado
     * tiene que decir la verdad: «Mi saldo» sobre el saldo de los hijos de uno
     * es un número correcto con la etiqueta equivocada, y ésa es la clase de
     * cosa que se lee mal justo cuando importa.
     */
    private function alcance(Request $request): string
    {
        return match ($request->user()->rolActivo?->ambitoDePermisos()) {
            CatalogoPermisos::ADMINISTRATIVO => self::ALCANCE_ESCUELA,
            CatalogoPermisos::ALUMNO => self::ALCANCE_PROPIO,
            CatalogoPermisos::PADRE => self::ALCANCE_FAMILIA,
            default => self::ALCANCE_NINGUNO,
        };
    }

    /**
     * Cierra el paso a la cuenta de un alumno ajeno.
     *
     * Que la lista se acote no basta: sin esto, se cambia el id en la URL y se
     * entra a cualquier otra. Y el administrativo acotado a campus tampoco pasa
     * a un alumno fuera del suyo — filtrar el listado sin cerrar el detalle no
     * cierra nada.
     */
    protected function exigirQuePuedaVerLaCuenta(Request $request, MatriculaOferta $matricula): void
    {
        $visibles = $this->matriculasVisibles($request);

        AvisoParaElUsuario::si(
            $visibles !== null && ! in_array($matricula->id, $visibles, true),
            403,
            'Ese estado de cuenta no está entre los que puedes consultar.',
        );

        $matricula->loadMissing('oferta:id,campus_id');
        $this->autorizarMatricula($request, $matricula);
    }
}
