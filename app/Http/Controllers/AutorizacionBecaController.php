<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AcotaPorCampus;
use App\Models\Finanzas\Beca;
use App\Models\Finanzas\BecaAlumnoAutorizacion;
use App\Models\Finanzas\NivelAutorizacionBeca;
use App\Models\Identidad\Rol;
use App\Models\Identidad\Usuario;
use App\Services\AutorizacionDeBecas;
use App\Services\EvaluadorBecas;
use App\Services\GeneradorAdeudos;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * La escala de firmas de una beca: quién la configura y quién la firma.
 *
 * Son DOS puertas distintas y por eso hay dos permisos: `gestionar-planes-cobro`
 * decide qué escala existe —es configurar el cobro—, y `autorizar-becas` firma.
 * Quien define la política no tiene por qué poder aprobar cada caso, y quien
 * aprueba no tiene por qué poder bajarse el umbral que le toca.
 */
class AutorizacionBecaController extends Controller
{
    use AcotaPorCampus;

    public function __construct(
        private readonly AutorizacionDeBecas $autorizacion,
        private readonly GeneradorAdeudos $generador,
        private readonly EvaluadorBecas $evaluador,
    ) {}

    /** La cola de quien firma: lo que espera a alguno de SUS roles. */
    public function pendientes(Request $peticion): Response
    {
        $usuario = $peticion->user();

        $pendientes = $usuario instanceof Usuario
            ? $this->autorizacion->pendientesDe($usuario)
            : collect();

        // El id viaja en el POST de firmar, así que la lista no es la defensa;
        // aun así se acota, para no enseñar el nombre de un alumno de otro
        // campus.
        $campus = $this->alcanceCampus($peticion);

        if ($campus !== null) {
            $pendientes = $pendientes->filter(
                fn (BecaAlumnoAutorizacion $a) => in_array($a->becaAlumno?->matricula?->oferta?->campus_id, $campus, true)
            )->values();
        }

        return Inertia::render('Finanzas/Becas/Autorizaciones', [
            'pendientes' => $pendientes->map(fn (BecaAlumnoAutorizacion $a) => [
                'id' => $a->id,
                'nivel' => $a->nivel?->nombre,
                'rol' => $a->nivel?->rol?->nombre ?: $a->nivel?->rol?->name,
                'beca' => $a->becaAlumno?->beca?->nombre,
                'valor' => $a->becaAlumno?->beca !== null ? $this->comoSeLee($a->becaAlumno->beca) : null,
                'alumno' => $a->becaAlumno?->matricula?->persona?->nombreCompleto(),
                'matricula' => $a->becaAlumno?->matricula?->matricula,
                'programa_academico' => $a->becaAlumno?->matricula?->oferta?->programaAcademico?->nombre,
                'ciclo' => $a->becaAlumno?->ciclo?->nombre,
                'solicitada' => $a->created_at?->format('d/m/Y'),
                // Cuántas firmas faltan además de ésta: decide si firmar la
                // enciende o sólo la adelanta.
                'faltan' => BecaAlumnoAutorizacion::query()
                    ->where('beca_alumno_id', $a->beca_alumno_id)
                    ->whereNull('autorizada_en')
                    ->count(),
                'beca_id' => $a->becaAlumno?->beca_id,
            ])->values(),
        ]);
    }

    public function firmar(Request $peticion, BecaAlumnoAutorizacion $autorizacion): RedirectResponse
    {
        $usuario = $peticion->user();
        abort_unless($usuario instanceof Usuario, 403);

        $datos = $peticion->validate(['motivo' => ['nullable', 'string', 'max:255']]);

        $autorizacion->load(['nivel.rol', 'becaAlumno.matricula.oferta:id,campus_id']);

        // El alcance por campus se vuelve a resolver aquí: el id llega en la
        // petición y filtrar la cola no impide escribir el de otro.
        $matricula = $autorizacion->becaAlumno?->matricula;

        if ($matricula !== null) {
            $this->autorizarMatricula($peticion, $matricula);
        }

        $r = $this->autorizacion->firmar($usuario, $autorizacion, $datos['motivo'] ?? null, $this->generador, $this->evaluador);

        if (! $r['firmada']) {
            return back(303)->with('error', $r['motivo']);
        }

        if (! $r['activada']) {
            return back(303)->with('exito', 'Nivel firmado. La beca sigue en espera de las firmas que faltan.');
        }

        return back(303)->with(
            'exito',
            'Autorización completa: la beca ya descuenta.'
            .($r['cargos'] > 0 ? " Se recalcularon {$r['cargos']} cargo(s) pendientes." : '')
        );
    }

    /** El catálogo de niveles. Configurarlo es configurar el cobro. */
    public function niveles(): Response
    {
        return Inertia::render('Finanzas/Becas/Niveles', [
            'niveles' => NivelAutorizacionBeca::query()
                ->with('rol:id,name,nombre')
                ->orderBy('modo')
                ->orderBy('orden')
                ->get()
                ->map(fn (NivelAutorizacionBeca $n) => [
                    'id' => $n->id,
                    'nombre' => $n->nombre,
                    'rol_id' => $n->rol_id,
                    'rol' => $n->rol?->nombre ?: $n->rol?->name,
                    'modo' => $n->modo,
                    'desde' => (float) $n->desde,
                    'umbral' => $n->umbral(),
                    'orden' => $n->orden,
                    'activo' => $n->activo,
                    'pendientes' => BecaAlumnoAutorizacion::query()
                        ->where('nivel_id', $n->id)
                        ->whereNull('autorizada_en')
                        ->count(),
                ]),
            /*
             * Sólo los roles que pueden firmar: ofrecer los demás sería
             * configurar una escala que nadie atiende, y la beca se quedaría
             * esperando para siempre a alguien que no puede ni entrar.
             *
             * Se pregunta con `concede()` y NO con un `whereHas('permissions')`:
             * un rol funcional HEREDA los permisos de su faceta, así que la
             * consulta directa dejaría fuera a casi todos —en una escuela
             * normal el permiso vive en la faceta administrativa— y la lista
             * saldría casi vacía sin error ninguno.
             */
            'roles' => Rol::query()
                ->orderBy('nombre')
                ->get()
                ->filter(fn (Rol $r) => $r->concede('autorizar-becas'))
                ->map(fn (Rol $r) => ['id' => $r->id, 'nombre' => $r->nombre ?: $r->name])
                ->values(),
        ]);
    }

    public function guardarNivel(Request $peticion): RedirectResponse
    {
        NivelAutorizacionBeca::create($this->validarNivel($peticion));

        return back(303)->with('exito', 'Nivel de autorización creado.');
    }

    public function actualizarNivel(Request $peticion, NivelAutorizacionBeca $nivel): RedirectResponse
    {
        $datos = $this->validarNivel($peticion);

        /*
         * Un nivel se APAGA, no se borra, y no se puede apagar mientras haya
         * becas esperándolo: se quedarían colgadas de una firma que ya no se le
         * va a pedir a nadie, y no hay pantalla desde donde destrabarlas.
         */
        if (! $datos['activo'] && $nivel->activo) {
            $motivo = $this->autorizacion->motivoParaNoApagar($nivel);

            if ($motivo !== null) {
                return back(303)->with('error', $motivo);
            }
        }

        $nivel->update($datos);

        return back(303)->with('exito', 'Nivel actualizado.');
    }

    /** @return array<string, mixed> */
    private function validarNivel(Request $peticion): array
    {
        $datos = $peticion->validate([
            'nombre' => ['required', 'string', 'max:100'],
            'rol_id' => ['required', 'integer', Rule::exists('roles', 'id')],
            'modo' => ['required', Rule::in([Beca::MODO_PORCENTAJE, Beca::MODO_MONTO_FIJO])],
            'desde' => ['required', 'numeric', 'min:0'],
            'orden' => ['required', 'integer', 'min:1', 'max:99'],
            'activo' => ['required', 'boolean'],
        ], [
            'rol_id.exists' => 'Ese rol ya no existe.',
        ]);

        // Validar no convierte: `numeric` deja «0.4» como cadena y `boolean`
        // devuelve «0», que en PHP es verdadero.
        $datos['desde'] = (float) $datos['desde'];
        $datos['orden'] = (int) $datos['orden'];
        $datos['activo'] = $peticion->boolean('activo');

        return $datos;
    }

    private function comoSeLee(Beca $beca): string
    {
        return $beca->modo === Beca::MODO_PORCENTAJE
            ? rtrim(rtrim(number_format((float) $beca->valor * 100, 2), '0'), '.').' %'
            : '$'.number_format((float) $beca->valor, 2);
    }
}
