<?php

declare(strict_types=1);

namespace App\Http\Controllers\Plataforma;

use App\Enums\DestinoEvento;
use App\Enums\TipoEventoCalendario;
use App\Http\Controllers\Concerns\ArmaDestinos;
use App\Http\Controllers\Controller;
use App\Models\Academico\Campus;
use App\Models\Academico\Carrera;
use App\Models\Academico\NivelEstudio;
use App\Models\Academico\PlanEstudio;
use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\ControlEscolar\Grupo;
use App\Models\Plataforma\EventoCalendario;
use App\Services\Plataforma\AgendaDeUsuario;
use App\Services\Plataforma\FeriadosOficiales;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

/**
 * El calendario de la escuela, desde el lado de quien lo escribe.
 *
 * Avisos, feriados, recesos, inicio y fin de ciclo, evaluaciones y eventos, con
 * el público al que le llega cada uno. Lo que aquí se publica es lo que después
 * aparece en la agenda del panel de cada persona.
 *
 * ── Quién entra ────────────────────────────────────────────────────────────
 * `gestionar-calendario`. VER la agenda no pide permiso —cada quien ve la suya
 * y eso lo resuelve {@see AgendaDeUsuario}—; lo que se
 * controla aquí es escribir en la de los demás.
 */
class CalendarioController extends Controller
{
    use ArmaDestinos;

    public function index(Request $request): Response
    {
        // Por omisión, el mes que se está viendo. Un calendario sin recorte
        // traería años enteros a una pantalla que muestra treinta días.
        $mes = $this->mesPedido($request);

        $desde = "{$mes}-01";
        $hasta = date('Y-m-t', strtotime($desde));

        $eventos = EventoCalendario::query()
            ->with('destinos')
            ->enRango($desde, $hasta)
            ->orderBy('inicia_en')
            ->get();

        return Inertia::render('Plataforma/Calendario', [
            'mes' => $mes,
            'eventos' => $eventos->map(fn (EventoCalendario $e) => $this->comoFila($e))->values(),
            'tipos' => TipoEventoCalendario::paraSelect(),
            'destinos' => DestinoEvento::paraSelect(),
            // Los catálogos para armar la segmentación. Van completos porque el
            // selector es un buscador local: pedirlos por AJAX en cada tecla
            // sería más viajes para listas que no pasan de unos cientos.
            'opciones' => $this->opcionesDeDestino(),
        ]);
    }

    public function guardar(Request $request, ?EventoCalendario $evento = null): RedirectResponse
    {
        $datos = $this->validar($request);

        $guardado = DB::transaction(function () use ($datos, $evento) {
            $registro = $evento ?? new EventoCalendario;

            $registro->fill([
                'tipo' => $datos['tipo'],
                'titulo' => $datos['titulo'],
                'descripcion' => $datos['descripcion'] ?? null,
                'inicia_en' => $datos['inicia_en'],
                'termina_en' => $datos['termina_en'] ?? null,
                'todo_el_dia' => $datos['todo_el_dia'],
                'no_laborable' => $datos['no_laborable'],
                'publicado' => $datos['publicado'],
            ]);

            $registro->save();

            /*
             * Los destinos se rehacen enteros en cada guardado.
             *
             * Conciliar cuál se agregó y cuál se quitó sería más código para el
             * mismo resultado: son pocas filas y no tienen datos propios que
             * conservar. Y así no queda forma de que sobreviva un destino que
             * el usuario ya había quitado de la pantalla.
             */
            $registro->destinos()->delete();

            foreach ($datos['destinos'] as $destino) {
                $registro->destinos()->create([
                    'tipo' => $destino['tipo'],
                    'destino_id' => $destino['destino_id'] ?? null,
                ]);
            }

            return $registro;
        });

        return back(303)->with(
            'exito',
            $evento === null
                ? "«{$guardado->titulo}» quedó en el calendario."
                : "«{$guardado->titulo}» actualizado.",
        );
    }

    /**
     * Trae los días festivos oficiales del año y los deja en borrador.
     *
     * En borrador a propósito: un feriado oficial no siempre es día sin clases
     * en una escuela particular, y esa decisión es de la dirección. Se importan,
     * se revisan y se publica lo que aplique.
     */
    public function importarFeriados(Request $request, FeriadosOficiales $feriados): RedirectResponse
    {
        $datos = $request->validate([
            'anio' => ['required', 'integer', 'min:2020', 'max:2100'],
        ], [], ['anio' => 'año']);

        $anio = (int) $datos['anio'];

        if ($feriados->delAnio($anio) === null) {
            return back(303)->with('error', 'No se pudo consultar el calendario oficial. Inténtalo más tarde.');
        }

        $resultado = $feriados->importar($anio);

        if ($resultado['creados'] === 0) {
            return back(303)->with('info', "Los feriados de {$anio} ya estaban en el calendario.");
        }

        return back(303)->with(
            'exito',
            "Se agregaron {$resultado['creados']} feriado(s) de {$anio} como borrador. "
            .'Revísalos y publica los que apliquen en tu escuela.'
            .($resultado['existentes'] > 0 ? " ({$resultado['existentes']} ya estaban.)" : ''),
        );
    }

    public function eliminar(EventoCalendario $evento): RedirectResponse
    {
        $titulo = $evento->titulo;

        $evento->delete();

        return back(303)->with('exito', "«{$titulo}» se quitó del calendario.");
    }

    /**
     * @return array<string, mixed>
     */
    private function validar(Request $request): array
    {
        return $request->validate([
            'tipo' => ['required', Rule::enum(TipoEventoCalendario::class)],
            'titulo' => ['required', 'string', 'max:180'],
            'descripcion' => ['nullable', 'string', 'max:5000'],
            'inicia_en' => ['required', 'date'],
            'termina_en' => ['nullable', 'date', 'after_or_equal:inicia_en'],
            'todo_el_dia' => ['boolean'],
            'no_laborable' => ['boolean'],
            'publicado' => ['boolean'],

            // Sin destinos no lo ve nadie, y un aviso que nadie ve es un aviso
            // perdido: se exige al menos uno en vez de guardarlo en silencio.
            'destinos' => ['required', 'array', 'min:1'],
            'destinos.*.tipo' => ['required', Rule::enum(DestinoEvento::class)],
            'destinos.*.destino_id' => ['nullable', 'integer'],
        ], [], [
            'inicia_en' => 'fecha de inicio',
            'termina_en' => 'fecha de fin',
            'destinos' => 'destinatarios',
        ]);
    }

    /**
     * El mes que se está viendo, en `AAAA-MM`.
     *
     * Se comprueba el formato en vez de confiar en la URL: entra directo a una
     * consulta por fecha, y un valor raro dejaría la pantalla vacía sin decir
     * por qué.
     */
    private function mesPedido(Request $request): string
    {
        $mes = (string) $request->query('mes', '');

        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mes) === 1
            ? $mes
            : date('Y-m');
    }

    /**
     * @return array<string, mixed>
     */
    private function comoFila(EventoCalendario $evento): array
    {
        return [
            'id' => $evento->id,
            'tipo' => $evento->tipo->value,
            'tipo_etiqueta' => $evento->tipo->etiqueta(),
            'color' => $evento->tipo->color(),
            'titulo' => $evento->titulo,
            'descripcion' => $evento->descripcion,
            'inicia_en' => $evento->inicia_en->format('Y-m-d\TH:i'),
            'termina_en' => $evento->termina_en?->format('Y-m-d\TH:i'),
            'inicia_dia' => $evento->inicia_en->format('Y-m-d'),
            'termina_dia' => $evento->finReal()->format('Y-m-d'),
            'todo_el_dia' => $evento->todo_el_dia,
            'no_laborable' => $evento->no_laborable,
            'publicado' => $evento->publicado,
            'destinos' => $evento->destinos->map(fn ($d) => [
                'tipo' => $d->tipo->value,
                'tipo_etiqueta' => $d->tipo->etiqueta(),
                'destino_id' => $d->destino_id,
                'nombre' => $this->nombreDelDestino($d->tipo, $d->destino_id),
            ])->values(),
        ];
    }

    /**
     * Cómo se llama aquello a lo que apunta un destino.
     *
     * Se resuelve al leer y no se guarda: si alguien renombra un campus, el
     * aviso tiene que decir el nombre nuevo. Lo que ya no existe se muestra
     * como tal —el destino simplemente no alcanza a nadie, y esconderlo dejaría
     * al administrador sin entender por qué su aviso no llega—.
     */
    private function nombreDelDestino(DestinoEvento $tipo, ?int $id): string
    {
        if ($tipo === DestinoEvento::Todos) {
            return 'Toda la escuela';
        }

        if ($id === null) {
            return 'Sin especificar';
        }

        $nombre = match ($tipo) {
            DestinoEvento::Rol => Role::find($id)?->name,
            DestinoEvento::Campus => Campus::find($id)?->nombre,
            DestinoEvento::Nivel => NivelEstudio::find($id)?->nombre,
            DestinoEvento::Carrera => Carrera::find($id)?->nombre,
            DestinoEvento::Plan => PlanEstudio::find($id)?->nombre,
            DestinoEvento::Grupo => Grupo::find($id)?->clave,
            DestinoEvento::Materia => $this->nombreDeMateria($id),
            DestinoEvento::Alumno => DB::table('personas')->where('id', $id)
                ->selectRaw("TRIM(CONCAT_WS(' ', nombre, primer_apellido, segundo_apellido)) AS n")
                ->value('n'),
            default => null,
        };

        return $nombre ?? 'Ya no existe';
    }

    private function nombreDeMateria(int $id): ?string
    {
        $materia = AsignaturaGrupo::query()
            ->with(['planMateria.asignatura:id,nombre', 'grupo:id,clave'])
            ->find($id);

        if ($materia === null) {
            return null;
        }

        return trim(($materia->planMateria?->asignatura?->nombre ?? 'Materia')
            .' · '.($materia->grupo?->clave ?? ''));
    }
}
