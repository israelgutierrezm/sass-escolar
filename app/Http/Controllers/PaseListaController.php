<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AutorizaMateriaPropia;
use App\Models\Asistencia\AsistenciaClase;
use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\ControlEscolar\Inscripcion;
use App\Models\Identidad\Usuario;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Pase de lista de una materia.
 *
 * La tabla `asistencia_clase` existía desde el módulo de asistencia y el portal
 * del alumno ya la leía, pero nadie podía ESCRIBIRLA: había permiso
 * (`pasar-lista`) y no había ni controlador ni pantalla. Esto es esa mitad.
 *
 * Se guarda la lista COMPLETA de una sesión de una sola vez, no alumno por
 * alumno: pasar lista es un acto único sobre el grupo, y guardar de a uno
 * dejaría sesiones a medias si el docente se distrae a la mitad.
 */
class PaseListaController extends Controller
{
    use AutorizaMateriaPropia;

    /**
     * Los estados posibles de una asistencia.
     *
     * Salen de las constantes del MODELO y no de una lista escrita aquí: eran
     * dos declaraciones de lo mismo y ya habían divergido —el modelo decía
     * `'ausente'` donde esto escribe `'falta'`, y su `scopeFaltas()` no
     * encontraba nada—. Con una sola declaración no puede repetirse.
     */
    private const ESTATUS = [
        AsistenciaClase::PRESENTE,
        AsistenciaClase::RETARDO,
        AsistenciaClase::FALTA,
        AsistenciaClase::JUSTIFICADA,
    ];

    public function guardar(Request $request, AsignaturaGrupo $asignaturaGrupo): RedirectResponse
    {
        $this->autorizar($request, $asignaturaGrupo);

        $datos = $request->validate([
            'fecha' => ['required', 'date', 'before_or_equal:today'],
            'modalidad' => ['required', Rule::in(['unica', 'teorica', 'practica'])],
            'asistencias' => ['required', 'array', 'min:1'],
            'asistencias.*.inscripcion_id' => ['required', 'integer'],
            'asistencias.*.estatus' => ['required', Rule::in(self::ESTATUS)],
            'asistencias.*.observacion' => ['nullable', 'string', 'max:300'],
        ], [
            'fecha.before_or_equal' => 'No se puede pasar lista de una clase que todavía no ocurre.',
        ], ['asistencias' => 'lista']);

        // Solo se registran alumnos de ESTA materia. Un id ajeno en el arreglo
        // no debe crear un renglón de asistencia en un grupo que no es suyo.
        $suyas = Inscripcion::query()
            ->where('asignatura_grupo_id', $asignaturaGrupo->id)
            ->pluck('id')
            ->flip();

        /** @var Usuario $usuario */
        $usuario = $request->user();
        $guardadas = 0;

        DB::transaction(function () use ($datos, $suyas, $usuario, &$guardadas) {
            foreach ($datos['asistencias'] as $fila) {
                if (! $suyas->has((int) $fila['inscripcion_id'])) {
                    continue;
                }

                /*
                 * Repasar lista del mismo día CORRIGE el registro, no lo
                 * duplica: el unique es (inscripción, fecha, modalidad).
                 *
                 * Se busca CON las borradas y se revive la que aparezca. El
                 * `updateOrCreate` normal no ve las que tienen `deleted_at`
                 * —el scope global las esconde—, así que intentaba insertar
                 * encima de una fila que el unique de la base sí ve, y la
                 * lista entera moría con un 1062. Le pasa a cualquiera que
                 * borre una asistencia y vuelva a pasar lista ese día.
                 */
                AsistenciaClase::actualizarOReviver(
                    [
                        'inscripcion_id' => $fila['inscripcion_id'],
                        'fecha' => $datos['fecha'],
                        'modalidad' => $datos['modalidad'],
                    ],
                    [
                        'estatus' => $fila['estatus'],
                        'observacion' => $fila['observacion'] ?? null,
                        // La llave foránea apunta a `personas`, no a `usuarios`:
                        // quien pasa lista es el docente como PERSONA, que es lo
                        // que sigue teniendo sentido si su cuenta desaparece.
                        'registrada_por' => $usuario->persona_id,
                    ],
                );

                $guardadas++;
            }
        });

        $cual = $datos['modalidad'] === 'unica' ? '' : " ({$datos['modalidad']})";

        return back()->with('exito', "Lista del {$datos['fecha']}{$cual} guardada: {$guardadas} alumno(s).");
    }

    /** Activa o desactiva el segundo pase de lista de esta materia. */
    public function alternarDoble(Request $request, AsignaturaGrupo $asignaturaGrupo): RedirectResponse
    {
        $this->autorizar($request, $asignaturaGrupo);

        $activar = $request->boolean('doble_pase_lista');

        // Apagarlo con registros de práctica ya tomados los dejaría invisibles
        // y sin forma de corregirlos: el dato existe pero la pantalla ya no lo
        // muestra. Mejor decirlo que esconderlo.
        if (! $activar) {
            $registrosPractica = AsistenciaClase::query()
                ->where('modalidad', 'practica')
                ->whereIn('inscripcion_id', Inscripcion::where('asignatura_grupo_id', $asignaturaGrupo->id)->pluck('id'))
                ->count();

            if ($registrosPractica > 0) {
                return back()->with(
                    'error',
                    "No se puede quitar el segundo pase de lista: ya hay {$registrosPractica} registro(s) de práctica. "
                    .'Bórralos primero si de verdad quieres dejar uno solo.',
                );
            }
        }

        $asignaturaGrupo->update(['doble_pase_lista' => $activar]);

        return back()->with(
            'exito',
            $activar
                ? 'Esta materia pasa lista por separado en teoría y práctica.'
                : 'Esta materia pasa lista una sola vez por sesión.',
        );
    }

    /** Solo se pasa lista en una materia propia; control escolar entra a todas. */
    private function autorizar(Request $request, AsignaturaGrupo $asignaturaGrupo): void
    {
        $this->autorizarMateriaPropia($request, $asignaturaGrupo);
    }
}
