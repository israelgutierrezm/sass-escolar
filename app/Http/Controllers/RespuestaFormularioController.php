<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AcotaPorCampus;
use App\Http\Controllers\Concerns\ResuelveMiSolicitud;
use App\Models\Admisiones\Aspirante;
use App\Models\Admisiones\RespuestaCampo;
use App\Models\Formularios\CampoFormulario;
use App\Models\Formularios\Formulario;
use App\Services\ResolutorFormularios;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Contestar un formulario.
 *
 * `ResolutorFormularios` ya decía QUÉ formularios le tocan a alguien y cuánto
 * llevaba contestado; faltaba el sitio donde contestarlos. Sin esto el avance
 * de todos era siempre cero y no había forma de moverlo.
 *
 * ── Dos puertas, una pantalla ──────────────────────────────────────────────
 * El mismo formulario lo llena el interesado desde su portal o quien lo atiende
 * desde la ficha. Es la MISMA captura con distinto quién: separarlas habría
 * llevado a que una acepte un tipo de campo que la otra no, y a que las reglas
 * de validación se separaran sin que nadie lo decidiera.
 *
 * Lo único que cambia entre las dos es de dónde sale el titular —de la URL en
 * la administrativa, de la sesión en la del portal— y a dónde se vuelve.
 *
 * ── Las respuestas se guardan por campo ────────────────────────────────────
 * Una fila por (titular, campo), como manda `respuestas_campo`. Se conserva la
 * versión del formulario con la que se respondió: si mañana se publica una
 * versión nueva, lo ya contestado sigue diciendo con qué preguntas fue.
 */
class RespuestaFormularioController extends Controller
{
    use AcotaPorCampus;
    use ResuelveMiSolicitud;

    /** Lo que se acepta subir en un campo de tipo documento. */
    private const FORMATOS = 'pdf,jpg,jpeg,png';

    private const CARPETA = 'formularios';

    public function __construct(private readonly ResolutorFormularios $resolutor) {}

    // ── Desde la ficha: lo llena quien atiende ─────────────────────────────

    public function mostrar(Request $request, Aspirante $aspirante, Formulario $formulario): Response
    {
        $this->autorizarCampus($request, $aspirante->campus_id);

        return $this->pantalla($aspirante, $formulario, [
            'titulo' => $aspirante->persona?->nombreCompleto(),
            'volver' => "/aspirantes/{$aspirante->id}",
        ], "/aspirantes/{$aspirante->id}/formularios/{$formulario->id}");
    }

    public function guardar(Request $request, Aspirante $aspirante, Formulario $formulario): RedirectResponse
    {
        $this->autorizarCampus($request, $aspirante->campus_id);
        $this->persistir($request, $aspirante, $formulario);

        return redirect("/aspirantes/{$aspirante->id}")->with('exito', "«{$formulario->titulo}» quedó guardado.");
    }

    // ── Desde el portal: lo llena el interesado ────────────────────────────

    /*
     * No reciben id de aspirante: sale de la persona en sesión. Es la única
     * diferencia real con las de arriba —quién es el titular y a dónde se
     * vuelve—, y por eso el resto se comparte: separar las dos capturas habría
     * llevado a que una acepte un tipo de campo que la otra no, y a que las
     * reglas se separaran sin que nadie lo decidiera.
     */
    public function mostrarMio(Request $request, Formulario $formulario): Response
    {
        $aspirante = $this->miSolicitud($request);

        return $this->pantalla($aspirante, $formulario, [
            'titulo' => 'Mi solicitud',
            'volver' => '/mi-solicitud',
        ], "/mi-solicitud/formularios/{$formulario->id}");
    }

    public function guardarMio(Request $request, Formulario $formulario): RedirectResponse
    {
        $aspirante = $this->miSolicitud($request);

        $this->persistir($request, $aspirante, $formulario);

        return redirect('/mi-solicitud')->with('exito', "«{$formulario->titulo}» quedó guardado.");
    }

    // ── Lo compartido ──────────────────────────────────────────────────────

    /**
     * @param  array{titulo: ?string, volver: string}  $contexto
     */
    private function pantalla(Aspirante $aspirante, Formulario $formulario, array $contexto, string $accion): Response
    {
        $this->abortarSiNoLeToca($aspirante, $formulario);

        return Inertia::render('Formularios/Captura', [
            'contexto' => $contexto,
            'formulario' => [
                'id' => $formulario->id,
                'titulo' => $formulario->titulo,
                'instruccion' => $formulario->instruccion,
            ],
            'campos' => $this->campos($formulario),
            'respuestas' => $this->respuestasActuales($aspirante, $formulario),
            'accion' => $accion,
        ]);
    }

    private function persistir(Request $request, Aspirante $aspirante, Formulario $formulario): void
    {
        $this->abortarSiNoLeToca($aspirante, $formulario);

        $campos = $formulario->campos()->with('tipoCampo', 'opciones')->get();

        $datos = $request->validate(
            $this->reglas($campos, $request),
            $this->mensajes($campos),
            $this->atributos($campos),
        );

        DB::transaction(function () use ($campos, $datos, $aspirante, $formulario, $request) {
            foreach ($campos as $campo) {
                $this->guardarRespuesta($campo, $datos, $aspirante, $formulario, $request);
            }
        });
    }

    /**
     * Un formulario que no le toca no se contesta.
     *
     * La ficha sólo enlaza los que sí, pero la URL lleva ids y se puede teclear:
     * sin esto, cualquiera con permiso de editar aspirantes podría llenarle un
     * formulario que la escuela nunca le asignó, y ese dato aparecería después
     * en su expediente sin que nadie supiera de dónde salió.
     */
    private function abortarSiNoLeToca(Aspirante $aspirante, Formulario $formulario): void
    {
        abort_unless(
            $this->resolutor->para($aspirante)->contains('id', $formulario->id),
            404,
            'Ese formulario no le corresponde a este aspirante.',
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function campos(Formulario $formulario): array
    {
        return $formulario->campos()->with('tipoCampo:id,clave,nombre', 'opciones')->get()
            ->map(fn (CampoFormulario $c) => [
                'id' => $c->id,
                'pregunta' => $c->pregunta,
                'descripcion' => $c->descripcion,
                'tipo' => $c->tipoCampo?->clave,
                'obligatorio' => $c->obligatorio,
                'min' => $c->min === null ? null : (float) $c->min,
                'max' => $c->max === null ? null : (float) $c->max,
                // La condición viaja para que la pantalla esconda el campo en
                // vivo; el servidor la vuelve a mirar al validar.
                'campo_padre_id' => $c->campo_padre_id,
                'condicional' => $c->condicional,
                'opciones' => $c->opciones->map(fn ($o) => ['valor' => $o->valor, 'etiqueta' => $o->etiqueta]),
            ])
            ->all();
    }

    /**
     * Lo ya contestado, listo para prellenar.
     *
     * @return array<string, mixed>
     */
    private function respuestasActuales(Aspirante $aspirante, Formulario $formulario): array
    {
        return RespuestaCampo::query()
            ->where('aspirante_id', $aspirante->id)
            ->whereIn('campo_formulario_id', $formulario->campos()->pluck('id'))
            ->get()
            ->mapWithKeys(fn (RespuestaCampo $r) => [
                (string) $r->campo_formulario_id => [
                    'valor' => $this->decodificar($r->valor),
                    'documento' => $r->documento_ruta,
                ],
            ])
            ->all();
    }

    /**
     * Las reglas, armadas desde la definición de cada campo.
     *
     * @param  \Illuminate\Support\Collection<int, CampoFormulario>  $campos
     * @return array<string, mixed>
     */
    private function reglas($campos, Request $request): array
    {
        $reglas = [];

        foreach ($campos as $campo) {
            $clave = "campos.{$campo->id}";
            $tipo = $campo->tipoCampo?->clave;

            /*
             * Un campo escondido por su condición NO es obligatorio.
             *
             * Se pregunta «¿fumas?» y sólo si contesta que sí aparece «¿cuántos
             * al día?». Exigir el segundo cuando ni siquiera se muestra deja el
             * formulario imposible de enviar, con un error señalando algo que
             * no está en pantalla.
             */
            $visible = $this->condicionSeCumple($campo, $campos, $request);
            $base = [$campo->obligatorio && $visible ? 'required' : 'nullable'];

            $reglas[$clave] = match ($tipo) {
                'numero' => array_filter([
                    ...$base, 'numeric',
                    $campo->min === null ? null : 'min:'.(float) $campo->min,
                    $campo->max === null ? null : 'max:'.(float) $campo->max,
                ]),
                'email' => [...$base, 'email', 'max:150'],
                'fecha' => [...$base, 'date'],
                'checkbox' => ['boolean'],
                'documento' => [...$base, 'file', 'mimes:'.self::FORMATOS, 'max:5120'],
                'multiselect' => [...$base, 'array'],
                'select', 'radio' => [...$base, Rule::in($campo->opciones->pluck('valor'))],
                default => [...$base, 'string', 'max:500'],
            };

            if ($tipo === 'multiselect') {
                $reglas["{$clave}.*"] = [Rule::in($campo->opciones->pluck('valor'))];
            }

            // El patrón que la escuela definió, con su propio mensaje.
            if (filled($campo->regex) && ! in_array($tipo, ['documento', 'multiselect', 'checkbox'], true)) {
                $reglas[$clave][] = 'regex:/'.$campo->regex.'/';
            }
        }

        return $reglas;
    }

    /** ¿Se cumple la condición que hace visible a este campo? */
    private function condicionSeCumple(CampoFormulario $campo, $campos, Request $request): bool
    {
        if ($campo->campo_padre_id === null) {
            return true;
        }

        return (string) $request->input("campos.{$campo->campo_padre_id}") === (string) $campo->condicional;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, CampoFormulario>  $campos
     * @return array<string, string>
     */
    private function mensajes($campos): array
    {
        $mensajes = [];

        foreach ($campos as $campo) {
            if (filled($campo->mensaje_error)) {
                // El mensaje que la escuela escribió gana al genérico: sabe qué
                // formato espera y por qué, cosa que «el formato es inválido» no
                // le dice a nadie.
                $mensajes["campos.{$campo->id}.regex"] = $campo->mensaje_error;
            }
        }

        return $mensajes;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, CampoFormulario>  $campos
     * @return array<string, string>
     */
    private function atributos($campos): array
    {
        return $campos
            ->mapWithKeys(fn (CampoFormulario $c) => ["campos.{$c->id}" => mb_strtolower($c->pregunta)])
            ->all();
    }

    /**
     * Una fila por campo. Se reescribe la que hubiera: la respuesta actual es
     * la que vale, y guardar el historial de lo que alguien tecleó y corrigió
     * antes de enviar no le sirve a nadie.
     *
     * @param  array<string, mixed>  $datos
     */
    private function guardarRespuesta(
        CampoFormulario $campo,
        array $datos,
        Aspirante $aspirante,
        Formulario $formulario,
        Request $request,
    ): void {
        $valor = $datos['campos'][$campo->id] ?? null;
        $llave = [
            'aspirante_id' => $aspirante->id,
            'campo_formulario_id' => $campo->id,
        ];

        if ($campo->tipoCampo?->clave === 'documento') {
            $archivo = $request->file("campos.{$campo->id}");

            // Sin archivo nuevo no se toca lo que ya estaba: reguardar el
            // formulario para corregir otra pregunta no puede borrar un acta
            // que ya se había subido.
            if ($archivo === null) {
                return;
            }

            RespuestaCampo::updateOrCreate($llave, [
                'persona_id' => $aspirante->persona_id,
                'formulario_version' => $formulario->version,
                'documento_ruta' => $archivo->store(self::CARPETA.'/'.$aspirante->id, 'local'),
            ]);

            return;
        }

        RespuestaCampo::updateOrCreate($llave, [
            'persona_id' => $aspirante->persona_id,
            'formulario_version' => $formulario->version,
            'valor' => $this->codificar($valor),
        ]);
    }

    /**
     * `valor` es una columna de texto y una selección múltiple son varios.
     * Se guardan como JSON para poder recuperarlos como lista sin adivinar
     * separadores —una opción con coma dentro rompería un `implode(',')`—.
     */
    private function codificar(mixed $valor): ?string
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        return is_array($valor) ? json_encode(array_values($valor)) : (string) $valor;
    }

    private function decodificar(?string $valor): mixed
    {
        if ($valor === null) {
            return null;
        }

        $decodificado = json_decode($valor, true);

        return is_array($decodificado) ? $decodificado : $valor;
    }
}
