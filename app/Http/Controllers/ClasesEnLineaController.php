<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Lms\CuentaVideo;
use App\Models\Lms\DestinoGrabacion;
use App\Models\Lms\IntegracionVideo;
use App\Support\DestinosGrabacionCatalogo;
use App\Support\ProveedoresVideoCatalogo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * La configuración de las clases en línea: qué proveedor está encendido, con qué
 * credenciales y con cuántas licencias.
 *
 * ── Las credenciales entran pero no salen ──────────────────────────────────
 * Al frontend viaja SI un campo está puesto, nunca su valor. Un `client_secret`
 * pintado en un formulario acaba en la caché del navegador, en una captura de
 * pantalla de soporte y en el historial de quien comparta la sesión. Guardar sin
 * tocar un campo lo deja como estaba: es lo que permite corregir el correo de
 * una licencia sin volver a pegar los tres secretos.
 *
 * ── Encender exige poder operar ────────────────────────────────────────────
 * Un proveedor encendido sin credenciales le ofrece al docente una opción que va
 * a fallar al programar, delante de un grupo que ya está esperando. Se comprueba
 * al encender, igual que en las pasarelas de cobro.
 */
class ClasesEnLineaController extends Controller
{
    public function index(): Response
    {
        $cuentas = CuentaVideo::query()->orderBy('proveedor')->orderBy('id')->get();

        return Inertia::render('Plataforma/ClasesEnLinea', [
            'proveedores' => collect(ProveedoresVideoCatalogo::todos())
                ->map(function (array $catalogo, string $clave) use ($cuentas) {
                    $integracion = IntegracionVideo::para($clave);
                    $guardadas = $integracion->credencialesArray();

                    return [
                        'clave' => $clave,
                        'nombre' => $catalogo['nombre'],
                        'descripcion' => $catalogo['descripcion'],
                        'color' => $catalogo['color'],
                        'activa' => (bool) $integracion->activa,
                        'completa' => $integracion->credencialesCompletas(),
                        // Lo que hace distinto a cada uno, dicho donde se
                        // configura y no escondido en la documentación.
                        'una_reunion_por_cuenta' => $catalogo['unaReunionPorCuenta'],
                        'que_es_una_cuenta' => $catalogo['queEsUnaCuenta'],
                        'campo_cuenta' => $catalogo['campoCuenta'],
                        'campos' => collect($catalogo['campos'])->map(fn (array $campo, string $nombre) => [
                            'nombre' => $nombre,
                            'etiqueta' => $campo['etiqueta'],
                            'requerido' => $campo['requerido'],
                            'ayuda' => $campo['ayuda'] ?? null,
                            // Sólo si está puesto. Nunca el valor.
                            'puesto' => filled($guardadas[$nombre] ?? null),
                        ])->values(),
                        'cuentas' => $cuentas->where('proveedor', $clave)->map(fn (CuentaVideo $c) => [
                            'id' => $c->id,
                            'etiqueta' => $c->etiqueta,
                            'identificador' => $c->identificador,
                            'activa' => (bool) $c->activa,
                            // Cuántas clases futuras sostiene: dice si apagarla
                            // va a dejar a alguien sin sala.
                            'proximas' => $c->sesiones()->vigentes()->count(),
                        ])->values(),
                    ];
                })->values(),
            'grabaciones' => [
                'destinos' => collect(DestinosGrabacionCatalogo::todos())
                    ->map(function (array $catalogo, string $clave) {
                        $destino = DestinoGrabacion::para($clave);
                        $guardadas = $destino->credencialesArray();

                        return [
                            'clave' => $clave,
                            'nombre' => $catalogo['nombre'],
                            'descripcion' => $catalogo['descripcion'],
                            'color' => $catalogo['color'],
                            'necesita_cuenta' => $catalogo['necesitaCuenta'],
                            'activo' => (bool) $destino->activo,
                            'completo' => $destino->credencialesCompletas(),
                            'campos' => collect($catalogo['campos'])->map(fn (array $campo, string $nombre) => [
                                'nombre' => $nombre,
                                'etiqueta' => $campo['etiqueta'],
                                'requerido' => $campo['requerido'],
                                'ayuda' => $campo['ayuda'] ?? null,
                                'puesto' => filled($guardadas[$nombre] ?? null),
                            ])->values(),
                        ];
                    })->values(),
                // La URL que hay que registrar en Zoom. Se arma aquí porque
                // depende del dominio de la escuela y copiarla mal es el error
                // más fácil de cometer y el más difícil de diagnosticar.
                'url_aviso' => url('/clases/grabacion/zoom'),
            ],
        ]);
    }

    /** Enciende un destino de archivado y apaga los demás. */
    public function guardarDestino(Request $request, string $destino): RedirectResponse
    {
        $catalogo = DestinosGrabacionCatalogo::uno($destino);

        abort_if($catalogo === null, 404);

        $datos = $request->validate([
            'activo' => ['boolean'],
            'credenciales' => ['array'],
            'credenciales.*' => ['nullable', 'string', 'max:8000'],
        ]);

        $fila = DestinoGrabacion::para($destino);
        $credenciales = $fila->credencialesArray();

        foreach ($catalogo['campos'] as $nombre => $campo) {
            if (filled($datos['credenciales'][$nombre] ?? null)) {
                $credenciales[$nombre] = $datos['credenciales'][$nombre];
            }
        }

        $fila->credenciales = $credenciales;

        if ($request->boolean('activo') && ! $fila->credencialesCompletas()) {
            $faltan = collect(DestinosGrabacionCatalogo::camposRequeridos($destino))
                ->reject(fn (string $c) => filled($credenciales[$c] ?? null))
                ->map(fn (string $c) => $catalogo['campos'][$c]['etiqueta'])
                ->implode(', ');

            throw ValidationException::withMessages([
                'activo' => "No se puede usar {$catalogo['nombre']} sin sus credenciales. Falta: {$faltan}.",
            ]);
        }

        $fila->activo = $request->boolean('activo');
        $fila->save();

        /*
         * Uno solo a la vez. Con dos encendidos habría que decidir qué enlace se
         * le enseña al alumno y se pagaría dos veces el mismo archivo.
         *
         * Lo ya archivado NO se mueve: cada grabación guarda a dónde fue, así
         * que lo viejo sigue abriéndose donde está.
         */
        if ($fila->activo) {
            DestinoGrabacion::query()->whereKeyNot($fila->id)->update(['activo' => false]);
        }

        return back(303)->with(
            'exito',
            $fila->activo
                ? "Las grabaciones nuevas se guardarán en {$catalogo['nombre']}. Lo ya archivado se queda donde está."
                : 'Destino apagado. Las grabaciones que lleguen no se van a guardar.',
        );
    }

    /** Guarda credenciales y el interruptor de un proveedor. */
    public function guardar(Request $request, string $proveedor): RedirectResponse
    {
        $catalogo = ProveedoresVideoCatalogo::uno($proveedor);

        abort_if($catalogo === null, 404);

        $datos = $request->validate([
            'activa' => ['boolean'],
            'credenciales' => ['array'],
            'credenciales.*' => ['nullable', 'string', 'max:8000'],
        ]);

        $integracion = IntegracionVideo::para($proveedor);
        $credenciales = $integracion->credencialesArray();

        foreach ($catalogo['campos'] as $nombre => $campo) {
            $valor = $datos['credenciales'][$nombre] ?? null;

            // Vacío = «no lo toqué». Es lo que permite corregir un campo sin
            // volver a pegar los otros; para borrarlo se apaga el proveedor.
            if (filled($valor)) {
                $credenciales[$nombre] = $valor;
            }
        }

        $integracion->credenciales = $credenciales;

        if ($request->boolean('activa') && ! $integracion->credencialesCompletas()) {
            $faltan = collect(ProveedoresVideoCatalogo::camposRequeridos($proveedor))
                ->reject(fn (string $c) => filled($credenciales[$c] ?? null))
                ->map(fn (string $c) => $catalogo['campos'][$c]['etiqueta'])
                ->implode(', ');

            throw ValidationException::withMessages([
                'activa' => "No se puede encender {$catalogo['nombre']} sin sus credenciales. Falta: {$faltan}.",
            ]);
        }

        $integracion->activa = $request->boolean('activa');
        $integracion->save();

        return back(303)->with(
            'exito',
            $integracion->activa
                ? "{$catalogo['nombre']} quedó encendido."
                : "{$catalogo['nombre']} quedó apagado: deja de ofrecerse al programar una clase.",
        );
    }

    /** Agrega una licencia (o la cuenta que organiza, según el proveedor). */
    public function agregarCuenta(Request $request, string $proveedor): RedirectResponse
    {
        abort_unless(ProveedoresVideoCatalogo::existe($proveedor), 404);

        $datos = $request->validate([
            'etiqueta' => ['required', 'string', 'max:120'],
            'identificador' => [
                'required', 'string', 'max:190',
                // Una cuenta no se carga dos veces: dos filas para la misma
                // licencia harían creer que se pueden dar dos clases a la vez.
                Rule::unique('cuentas_videoconferencia', 'identificador')->where('proveedor', $proveedor),
            ],
        ], [], [
            'etiqueta' => 'nombre',
            'identificador' => ProveedoresVideoCatalogo::uno($proveedor)['campoCuenta']['etiqueta'],
        ]);

        CuentaVideo::create($datos + ['proveedor' => $proveedor, 'activa' => true]);

        return back(303)->with('exito', 'Cuenta agregada.');
    }

    /** Enciende o apaga una cuenta del pool. */
    public function alternarCuenta(Request $request, CuentaVideo $cuenta): RedirectResponse
    {
        $activa = $request->boolean('activa');

        $cuenta->update(['activa' => $activa]);

        /*
         * Apagar NO cancela lo ya programado. Las clases que esa licencia
         * sostiene siguen en pie —cancelarlas dejaría a varios grupos sin sala
         * por un clic de configuración— y lo que cambia es que deja de entrar en
         * el reparto de aquí en adelante. Se dice, para que nadie suponga lo
         * contrario.
         */
        $proximas = $cuenta->sesiones()->vigentes()->count();

        return back(303)->with(
            'exito',
            $activa
                ? 'Cuenta encendida: vuelve a entrar en el reparto.'
                : ($proximas > 0
                    ? "Cuenta apagada. Las {$proximas} clases que ya tiene programadas siguen en pie; deja de usarse para las nuevas."
                    : 'Cuenta apagada: deja de usarse al programar.'),
        );
    }

    /**
     * Retira una cuenta del catálogo.
     *
     * Sólo si nunca se usó. Con clases detrás se apaga: borrarla dejaría sin
     * explicación los enlaces de un ciclo entero, y la foránea de
     * `videoconferencias` lo impediría de todos modos —mejor decirlo que
     * reventar—.
     */
    public function eliminarCuenta(CuentaVideo $cuenta): RedirectResponse
    {
        if ($cuenta->sesiones()->exists()) {
            $cuenta->update(['activa' => false]);

            return back(303)->with(
                'exito',
                'Esa cuenta ya dio clases, así que se apagó en vez de borrarse: deja de usarse y sigue explicando los enlaces que creó.',
            );
        }

        $cuenta->forceDelete();

        return back(303)->with('exito', 'Cuenta eliminada.');
    }
}
