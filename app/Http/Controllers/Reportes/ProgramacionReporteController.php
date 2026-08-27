<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reportes;

use App\Exceptions\AvisoParaElUsuario;
use App\Http\Controllers\Controller;
use App\Models\Identidad\Rol;
use App\Models\Reportes\DestinatarioReporte;
use App\Models\Reportes\ProgramacionReporte;
use App\Models\Reportes\VistaReporte;
use App\Reportes\RegistroReportes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Los reportes que se mandan solos por correo.
 *
 * ── Se programa una VISTA, no un reporte ─────────────────────────────────
 * «Mándame la cartera» no es una instrucción; «mándame la cartera vencida del
 * campus norte con estas seis columnas» sí. Por eso la pantalla parte de las
 * vistas guardadas: sin ninguna, no hay nada que programar y se dice.
 *
 * ── Cada quien programa lo SUYO ──────────────────────────────────────────
 * Se ven y se editan las programaciones propias. No hace falta un permiso nuevo
 * —quien puede ver un reporte puede pedir que se lo manden— y lo que decide qué
 * sale es el ROL guardado, que se comprueba en cada corrida.
 *
 * Quien organiza la sección (`gestionar-areas-reporte`) las ve TODAS, porque es
 * quien tiene que poder contestar «¿por qué le sigue llegando esto a alguien que
 * cambió de puesto?» y apagarlo.
 */
class ProgramacionReporteController extends Controller
{
    public function __construct(private readonly RegistroReportes $registro) {}

    public function index(Request $peticion): Response
    {
        $usuario = $peticion->user();
        $todas = $usuario->can('gestionar-areas-reporte');

        $programaciones = ProgramacionReporte::query()
            ->with(['vista', 'dueno', 'rol', 'destinatarios'])
            ->when(! $todas, fn ($q) => $q->where('persona_id', $usuario->persona_id))
            ->orderByDesc('activa')
            ->orderBy('nombre')
            ->get()
            ->map(fn (ProgramacionReporte $p) => $this->renglon($p));

        return Inertia::render('Reportes/Programaciones', [
            'programaciones' => $programaciones,
            'vistas' => $this->vistasQueSePuedenProgramar($usuario),
            'roles' => $this->rolesDe($usuario),
            'personas' => $this->personasConCorreo(),
            'todasLasDeLaEscuela' => $todas,
        ]);
    }

    public function guardar(Request $peticion, ?ProgramacionReporte $programacion = null): RedirectResponse
    {
        $usuario = $peticion->user();

        $datos = $peticion->validate([
            'vista_id' => ['required', 'integer', 'exists:vistas_reporte,id'],
            'nombre' => ['required', 'string', 'max:120'],
            'rol_id' => ['required', 'integer', 'exists:roles,id'],
            'frecuencia' => ['required', 'in:diaria,semanal,mensual'],
            /*
             * El día se topa a 28 en la mensual: con 31 nunca correría en
             * febrero, y «el último día del mes» es otra regla que nadie ha
             * pedido. En la semanal va en ISO, 1 lunes … 7 domingo.
             */
            'dia' => ['nullable', 'integer', 'min:1', 'max:28'],
            'hora' => ['required', 'date_format:H:i'],
            'formato' => ['required', 'in:xlsx,csv'],
            'destinatarios' => ['required', 'array', 'min:1'],
            'destinatarios.*.tipo' => ['required', 'in:persona,rol'],
            'destinatarios.*.destino_id' => ['required', 'integer'],
        ]);

        if ($programacion !== null) {
            $this->soloSiEsSuya($peticion, $programacion);
        }

        /*
         * El ROL tiene que ser uno que esta persona TENGA. Si no, programar
         * sería concederse un alcance: elegir «dirección general» sin serlo y
         * recibir la escuela entera por correo cada lunes.
         *
         * Se comprueba aquí Y en cada corrida: el desplegable no es una defensa,
         * y entre programar y correr pueden pasar meses.
         */
        AvisoParaElUsuario::si(
            ! $usuario->persona->asignacionesRol()
                ->where('rol_id', $datos['rol_id'])->where('activo', true)->exists(),
            403,
            'No puedes programar un reporte con un rol que no tienes.',
        );

        // Y la vista tiene que ser una que alcance.
        AvisoParaElUsuario::si(
            ! VistaReporte::query()->whereKey($datos['vista_id'])->visiblesPara($usuario)->exists(),
            403,
            'Esa vista guardada no es tuya ni está compartida contigo.',
        );

        $datos['dia'] = $datos['frecuencia'] === ProgramacionReporte::DIARIA ? null : $datos['dia'];

        AvisoParaElUsuario::si(
            $datos['frecuencia'] !== ProgramacionReporte::DIARIA && $datos['dia'] === null,
            422,
            'Elige el día en que quieres que salga.',
        );

        AvisoParaElUsuario::si(
            $datos['frecuencia'] === ProgramacionReporte::SEMANAL && $datos['dia'] > 7,
            422,
            'El día de la semana va de 1 (lunes) a 7 (domingo).',
        );

        DB::transaction(function () use ($datos, $programacion, $usuario) {
            $fila = $programacion ?? new ProgramacionReporte;

            $fila->fill([
                'vista_id' => $datos['vista_id'],
                'nombre' => $datos['nombre'],
                'rol_id' => $datos['rol_id'],
                'frecuencia' => $datos['frecuencia'],
                'dia' => $datos['dia'],
                'hora' => $datos['hora'],
                'formato' => $datos['formato'],
            ]);

            /*
             * El dueño NO se reescribe al editar: quien la creó sigue siendo
             * quien la creó, y su alcance es el que produce el archivo. Dejar
             * que un editor se pusiera de dueño cambiaría lo que sale sin
             * tocar nada visible.
             */
            $fila->persona_id ??= $usuario->persona_id;

            /*
             * Y editar la DESPIERTA: si estaba suspendida, tocarla es decir «ya
             * lo arreglé». Se limpia el motivo para que no quede un aviso viejo
             * explicando algo que ya no pasa.
             */
            $fila->suspendida_en = null;
            $fila->motivo_suspension = null;

            $fila->save();

            $fila->destinatarios()->delete();

            foreach ($datos['destinatarios'] as $destinatario) {
                $fila->destinatarios()->create([
                    'tipo' => $destinatario['tipo'],
                    'destino_id' => $destinatario['destino_id'],
                ]);
            }
        });

        return back(303)->with('exito', 'Programación guardada.');
    }

    public function alternar(Request $peticion, ProgramacionReporte $programacion): RedirectResponse
    {
        $this->soloSiEsSuya($peticion, $programacion);

        $programacion->forceFill([
            'activa' => ! $programacion->activa,
            // Encenderla a mano también la despierta de una suspensión.
            'suspendida_en' => $programacion->activa ? $programacion->suspendida_en : null,
            'motivo_suspension' => $programacion->activa ? $programacion->motivo_suspension : null,
        ])->save();

        return back(303);
    }

    public function eliminar(Request $peticion, ProgramacionReporte $programacion): RedirectResponse
    {
        $this->soloSiEsSuya($peticion, $programacion);

        $programacion->delete();

        return back(303)->with('exito', 'Programación eliminada.');
    }

    /** La propia, o cualquiera si organiza la sección. */
    private function soloSiEsSuya(Request $peticion, ProgramacionReporte $programacion): void
    {
        $usuario = $peticion->user();

        AvisoParaElUsuario::si(
            $programacion->persona_id !== $usuario->persona_id
                && ! $usuario->can('gestionar-areas-reporte'),
            403,
            'Esa programación no es tuya.',
        );
    }

    /** @return array<string, mixed> */
    private function renglon(ProgramacionReporte $programacion): array
    {
        $definicion = $this->registro->todos()[$programacion->vista?->reporte ?? ''] ?? null;

        return [
            'id' => $programacion->id,
            'nombre' => $programacion->nombre,
            'vista_id' => $programacion->vista_id,
            // Un reporte retirado deja su programación atrás; se dice, en vez de
            // enseñar un hueco que nadie sabe interpretar.
            'reporte' => $definicion?->titulo() ?? ($programacion->vista?->reporte.' (retirado)'),
            'vista' => $programacion->vista?->nombre ?? 'La vista ya no existe',
            'dueno' => $programacion->dueno?->nombreCompleto(),
            'rol' => $programacion->rol?->nombre ?? 'Ya no existe',
            'rol_id' => $programacion->rol_id,
            'frecuencia' => $programacion->frecuencia,
            'dia' => $programacion->dia,
            'hora' => substr((string) $programacion->hora, 0, 5),
            'formato' => $programacion->formato,
            'cuando' => $programacion->cuando(),
            'activa' => $programacion->activa,
            'suspendida' => $programacion->suspendida_en !== null,
            'motivo_suspension' => $programacion->motivo_suspension,
            'ultima_corrida_en' => $programacion->ultima_corrida_en?->toIso8601String(),
            'ultimo_estado' => $programacion->ultimo_estado,
            'ultimo_error' => $programacion->ultimo_error,
            'destinatarios' => $programacion->destinatarios
                ->map(fn (DestinatarioReporte $d) => [
                    'tipo' => $d->tipo,
                    'destino_id' => $d->destino_id,
                    'etiqueta' => $this->comoSeLlama($d),
                ])->all(),
        ];
    }

    /** El nombre del destinatario, o que ya no está. */
    private function comoSeLlama(DestinatarioReporte $destinatario): string
    {
        return match ($destinatario->tipo) {
            /*
             * `where('id', ...)` y no `whereKey()`: esto es el QUERY BUILDER, no
             * Eloquent, y ahí `whereKey` no existe — el `__call` dinámico lo
             * convierte en `where('key', ...)` y revienta con «Unknown column
             * 'key'». No falla al escribirlo: falla al abrir la pantalla.
             */
            DestinatarioReporte::PERSONA => DB::table('personas')
                ->where('id', $destinatario->destino_id)
                ->selectRaw("concat_ws(' ', nombre, primer_apellido, segundo_apellido) as n")
                ->value('n') ?? 'Ya no existe',
            DestinatarioReporte::ROL => DB::table('roles')
                ->where('id', $destinatario->destino_id)->value('nombre') ?? 'Ya no existe',
            default => 'Ya no existe',
        };
    }

    /**
     * Las vistas que esta persona puede programar, con el título de su reporte.
     *
     * @return array<int, array<string, mixed>>
     */
    private function vistasQueSePuedenProgramar($usuario): array
    {
        $registro = $this->registro->todos();

        return VistaReporte::query()
            ->visiblesPara($usuario)
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'reporte'])
            ->filter(fn (VistaReporte $v) => isset($registro[$v->reporte]))
            ->map(fn (VistaReporte $v) => [
                'id' => $v->id,
                'nombre' => $v->nombre,
                'reporte' => $registro[$v->reporte]->titulo(),
            ])
            ->values()
            ->all();
    }

    /**
     * Los roles que esta persona TIENE, que son los únicos con los que puede
     * programar. El desplegable no es la defensa —eso lo hace `guardar()`— pero
     * ofrecer lo que se va a rechazar es una trampa para quien captura.
     *
     * @return array<int, array<string, mixed>>
     */
    private function rolesDe($usuario): array
    {
        return Rol::query()
            ->whereIn('id', $usuario->persona->asignacionesRol()->where('activo', true)->pluck('rol_id'))
            ->orderBy('nombre')
            ->get(['id', 'nombre'])
            ->map(fn (Rol $r) => ['id' => $r->id, 'nombre' => $r->nombre])
            ->all();
    }

    /**
     * A quién se le puede mandar: personas de la escuela CON correo.
     *
     * Sin correo no hay a dónde mandar, y ofrecerlas haría que alguien las
     * eligiera y no se enterara de que no les llega —el envío las descarta y lo
     * anota, pero eso sólo se ve después—.
     *
     * @return array<int, array<string, mixed>>
     */
    private function personasConCorreo(): array
    {
        return DB::table('usuarios')
            ->join('personas', 'personas.id', '=', 'usuarios.persona_id')
            ->whereNull('usuarios.deleted_at')
            ->whereNotNull('usuarios.email')
            ->where('usuarios.email', '!=', '')
            ->orderBy('personas.primer_apellido')
            ->limit(500)
            ->selectRaw("personas.id, concat_ws(' ', personas.nombre, personas.primer_apellido, personas.segundo_apellido) as nombre")
            ->get()
            ->map(fn ($f) => ['id' => (int) $f->id, 'nombre' => $f->nombre])
            ->all();
    }
}
