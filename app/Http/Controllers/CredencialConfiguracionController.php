<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Credencial\CatalogoCampos;
use App\Credencial\CodigoQr;
use App\Credencial\Compositor;
use App\Credencial\Disenos;
use App\Models\Academico\NivelEstudio;
use App\Models\Identidad\CredencialRol;
use App\Models\Identidad\Rol;
use App\Support\CatalogoPermisos;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as Pantalla;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * La credencial virtual, configurada rol por rol.
 *
 * ── Por qué por ROL y no una sola para la escuela ──────────────────────────
 * Porque un gafete de alumno y uno de personal no llevan lo mismo ni se ven
 * igual: el del alumno trae matrícula y carrera, el del docente no tiene ni una
 * ni otra. Y hay escuelas que emiten sólo para alumnos. Una configuración
 * global obligaría a poner campos que la mitad de la gente no tiene.
 *
 * ── Y la variante por nivel, sólo para alumnos ─────────────────────────────
 * Lo pidió el cliente y es lo único que tiene sentido: bachillerato y posgrado
 * pueden querer credenciales distintas, pero un docente no cursa nada, así que
 * no hay nivel al que atarlo. La pantalla sólo ofrece variantes cuando el rol
 * es de la faceta alumno, y el servidor lo vuelve a comprobar.
 */
class CredencialConfiguracionController extends Controller
{
    public function __construct(private readonly Compositor $compositor) {}

    public function index(Request $peticion): Pantalla
    {
        $roles = Rol::query()->orderBy('nombre')->get();

        return Inertia::render('Plataforma/Configuraciones/Credencial', [
            'roles' => $roles->map(fn (Rol $rol) => [
                'id' => $rol->id,
                'nombre' => $rol->nombre ?: $rol->name,
                'faceta' => $rol->ambitoDePermisos(),
                'es_alumno' => $rol->ambitoDePermisos() === CatalogoPermisos::ALUMNO,
            ])->values(),
            'configuraciones' => CredencialRol::query()->get()->map(fn (CredencialRol $c) => $this->aPantalla($c))->values(),
            'campos' => CatalogoCampos::todos(),
            'disenos' => Disenos::CATALOGO,
            'niveles' => NivelEstudio::query()->orderBy('nombre')->get(['id', 'nombre']),
        ]);
    }

    /**
     * Guarda —o crea— la configuración de un rol.
     *
     * Es un `PUT` sobre el par (rol, nivel) en vez de un alta y una edición
     * separadas: para quien configura no hay dos operaciones, hay «cómo es la
     * credencial de los alumnos de posgrado», exista ya la fila o no.
     */
    public function guardar(Request $peticion): RedirectResponse
    {
        $datos = $this->validado($peticion);

        $config = CredencialRol::query()->firstOrNew([
            'rol_id' => $datos['rol_id'],
            'nivel_estudios_id' => $datos['nivel_estudios_id'],
        ]);

        $config->fill($datos)->save();

        return back(303)->with('exito', 'Credencial guardada.');
    }

    /** Retira la variante de un nivel; la general del rol se apaga, no se borra. */
    public function eliminar(CredencialRol $credencial): RedirectResponse
    {
        abort_if($credencial->nivel_estudios_id === null, 422, 'La configuración general del rol se apaga, no se elimina.');

        $credencial->delete();

        return back(303)->with('exito', 'Variante eliminada. Ese nivel vuelve a usar la credencial general del rol.');
    }

    /**
     * Sube el machote, la firma, o los quita.
     *
     * Van al disco PRIVADO. Un machote es la plantilla del gafete oficial de la
     * escuela y una firma es la de una persona: publicarlas en `public/` sería
     * regalar los dos ingredientes para falsificar una credencial.
     */
    public function subir(Request $peticion, CredencialRol $credencial): RedirectResponse
    {
        $datos = $peticion->validate([
            'campo' => ['required', Rule::in(['machote_anverso', 'machote_reverso', 'firma_imagen'])],
            'archivo' => ['nullable', 'image', 'mimes:png,jpg,jpeg', 'max:4096'],
        ]);

        $anterior = $credencial->{$datos['campo']};

        $ruta = $peticion->hasFile('archivo')
            ? $peticion->file('archivo')->store('credenciales', 'local')
            : null;

        $credencial->update([$datos['campo'] => $ruta]);

        // El archivo viejo se va: son imágenes pesadas y quedarse con todas las
        // versiones de un machote llena el disco sin que nadie las mire nunca.
        if (filled($anterior)) {
            Storage::disk('local')->delete($anterior);
        }

        return back(303)->with('exito', $ruta === null ? 'Imagen retirada.' : 'Imagen cargada.');
    }

    /** La imagen cargada, para que la pantalla la pueda enseñar. */
    public function imagen(CredencialRol $credencial, string $campo): StreamedResponse
    {
        abort_unless(in_array($campo, ['machote_anverso', 'machote_reverso', 'firma_imagen'], true), 404);

        $ruta = $credencial->{$campo};

        abort_if(blank($ruta) || ! Storage::disk('local')->exists($ruta), 404);

        return Storage::disk('local')->response($ruta);
    }

    /**
     * La vista previa: la credencial dibujada con datos de ejemplo.
     *
     * Con datos INVENTADOS y no con los de una persona real. Configurar no es
     * consultar el expediente de nadie, y quien acomoda las cajas necesita ver
     * el caso difícil —el nombre largo, la carrera larga— que justo la primera
     * persona de la lista puede no tener.
     */
    public function vistaPrevia(Request $peticion, string $cara): Response
    {
        abort_unless(in_array($cara, ['anverso', 'reverso'], true), 404);

        /*
         * Se dibuja sobre lo que la pantalla tiene EN EL FORMULARIO, no sobre
         * lo guardado, y sin exigir que la fila exista.
         *
         * Las dos cosas por el mismo motivo: el rol que más necesita ver cómo
         * va quedando es el que todavía no se ha configurado nunca. Colgar la
         * vista previa de un registro guardado obligaría a guardar a medias
         * para poder mirar, y a un viaje a la base por cada arrastre de caja.
         *
         * El machote y la firma sí salen de la fila —son archivos en disco—,
         * así que se recoge si ya existe.
         */
        $config = CredencialRol::query()->find($peticion->integer('credencial_id')) ?? new CredencialRol;

        $config->fill(array_merge(
            ['ancho' => 1011, 'alto' => 638, 'diseno' => 'clasico'],
            array_filter($peticion->only(['diseno', 'vigencia']), fn ($v) => $v !== null),
            [
                // Acotados aquí también, no sólo al guardar: esto reserva un
                // lienzo en memoria, así que un ancho de 40000 escrito a mano
                // tumbaría al servidor sin haber guardado nada.
                'ancho' => max(300, min(4000, $peticion->integer('ancho', 1011))),
                'alto' => max(300, min(4000, $peticion->integer('alto', 638))),
                // Por el mismo filtro que al guardar: si no, una coordenada de
                // 400 dibujaría fuera del lienzo y la vista previa enseñaría
                // algo que la credencial guardada nunca haría.
                'campos_anverso' => $this->campos((array) $peticion->input('campos_anverso', [])),
                'campos_reverso' => $this->campos((array) $peticion->input('campos_reverso', [])),
            ],
        ));

        return response(
            $this->compositor->componer($config, $cara, CatalogoCampos::ejemplo(), $this->siluetaDeEjemplo(), $this->qrDeEjemplo()),
            200,
            ['Content-Type' => 'image/png'],
        );
    }

    /**
     * Una silueta para el hueco de la foto en la vista previa.
     *
     * Dibujada, no una foto de archivo: hace falta ALGO en ese hueco —sin nada,
     * la caja de la foto es invisible y no se puede acomodar—, y ese algo no
     * puede ser el rostro de una persona real de la escuela.
     *
     * Va apaisada (4:3, como sale de casi cualquier cámara) a propósito: así la
     * vista previa enseña el recorte que de verdad va a ocurrir cuando la caja
     * sea vertical, en vez de fingir que la foto ya venía con la forma correcta.
     */
    private function siluetaDeEjemplo(): string
    {
        $lienzo = imagecreatetruecolor(800, 600);
        imagefill($lienzo, 0, 0, imagecolorallocate($lienzo, 226, 232, 240));

        $figura = imagecolorallocate($lienzo, 148, 163, 184);
        imagefilledellipse($lienzo, 400, 250, 220, 220, $figura);
        imagefilledellipse($lienzo, 400, 560, 380, 320, $figura);

        ob_start();
        imagepng($lienzo);
        $png = (string) ob_get_clean();
        imagedestroy($lienzo);

        return $png;
    }

    /** @return array<string, mixed> */
    private function validado(Request $peticion): array
    {
        $datos = $peticion->validate([
            'rol_id' => ['required', 'integer', 'exists:roles,id'],
            'nivel_estudios_id' => ['nullable', 'integer'],
            'activa' => ['required', 'boolean'],
            'diseno' => ['required', Rule::in(CredencialRol::DISENOS)],
            // El rango no es capricho: por debajo de 300 px el texto sale
            // ilegible al imprimir, y por encima de 4000 una credencial pesa
            // más que la foto del expediente.
            'ancho' => ['required', 'integer', 'between:300,4000'],
            'alto' => ['required', 'integer', 'between:300,4000'],
            'campos_anverso' => ['nullable', 'array'],
            'campos_reverso' => ['nullable', 'array'],
            'vigencia' => ['nullable', 'string', 'max:120'],
            'qr_activo' => ['required', 'boolean'],
            'qr_publico' => ['required', 'boolean'],
            'firma_nombre' => ['nullable', 'string', 'max:120'],
            'firma_cargo' => ['nullable', 'string', 'max:120'],
        ]);

        $datos['campos_anverso'] = $this->campos($datos['campos_anverso'] ?? []);
        $datos['campos_reverso'] = $this->campos($datos['campos_reverso'] ?? []);

        /*
         * El nivel sólo aplica a la faceta ALUMNO.
         *
         * Se comprueba aquí y no sólo en la pantalla: un docente no cursa nada,
         * así que una credencial de docente atada a «Licenciatura» nunca la
         * elegiría nadie —`CredencialesDeLaPersona` sólo busca por nivel cuando
         * hay matrícula— y quedaría configurada para siempre sin emitirse. Es
         * la clase de ajuste que se hace una vez y se descubre roto un año
         * después.
         */
        if ($datos['nivel_estudios_id'] !== null) {
            $rol = Rol::query()->findOrFail($datos['rol_id']);

            abort_unless(
                $rol->ambitoDePermisos() === CatalogoPermisos::ALUMNO,
                422,
                'Sólo los roles de la faceta alumno pueden tener una credencial por nivel de estudios.',
            );
        }

        return $datos;
    }

    /**
     * Limpia el mapa de campos que manda la pantalla.
     *
     * Se filtra contra el CATÁLOGO: lo que llega es un JSON que cualquiera con
     * el permiso puede escribir a mano, y una clave inventada acabaría guardada
     * para siempre estorbando en un arreglo que nadie vuelve a mirar. Y las
     * coordenadas se acotan a 0–100 porque son porcentajes: un 400 dibujaría el
     * dato fuera del lienzo, o sea invisible sin explicación.
     *
     * @param  array<int, mixed>  $campos
     * @return array<int, array<string, mixed>>
     */
    private function campos(array $campos): array
    {
        return collect($campos)
            ->filter(fn ($campo) => is_array($campo) && CatalogoCampos::existe($campo['clave'] ?? ''))
            ->map(fn (array $campo) => array_filter([
                'clave' => $campo['clave'],
                'x' => $this->porcentaje($campo['x'] ?? 0),
                'y' => $this->porcentaje($campo['y'] ?? 0),
                'ancho' => $this->porcentaje($campo['ancho'] ?? 20),
                'alto' => $this->porcentaje($campo['alto'] ?? 10),
                'tamano' => max(6, min(120, (int) ($campo['tamano'] ?? 18))),
                'alineacion' => in_array($campo['alineacion'] ?? '', ['izquierda', 'centro', 'derecha'], true)
                    ? $campo['alineacion']
                    : 'izquierda',
                'etiqueta' => is_string($campo['etiqueta'] ?? null) ? mb_substr($campo['etiqueta'], 0, 40) : null,
                'color' => $this->color($campo['color'] ?? null),
                'color_etiqueta' => $this->color($campo['color_etiqueta'] ?? null),
            ], fn ($v) => $v !== null))
            ->values()
            ->all();
    }

    private function porcentaje(mixed $valor): float
    {
        return round(max(0, min(100, (float) $valor)), 2);
    }

    private function color(mixed $valor): ?string
    {
        return is_string($valor) && preg_match('/^#[0-9a-fA-F]{6}$/', $valor) === 1 ? $valor : null;
    }

    /** @return array<string, mixed> */
    private function aPantalla(CredencialRol $config): array
    {
        return array_merge($config->only([
            'id', 'rol_id', 'nivel_estudios_id', 'activa', 'diseno', 'ancho', 'alto',
            'campos_anverso', 'campos_reverso', 'vigencia', 'qr_activo', 'qr_publico',
            'firma_nombre', 'firma_cargo',
        ]), [
            // Se manda si HAY imagen, no la ruta del disco: la pantalla sólo
            // necesita saber si enseñar la miniatura o el botón de cargar.
            'tiene_machote_anverso' => filled($config->machote_anverso),
            'tiene_machote_reverso' => filled($config->machote_reverso),
            'tiene_firma' => filled($config->firma_imagen),
        ]);
    }

    private function qrDeEjemplo(): string
    {
        return app(CodigoQr::class)->pngDe('https://ejemplo/credencial/vista-previa');
    }
}
