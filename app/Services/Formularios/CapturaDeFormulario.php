<?php

declare(strict_types=1);

namespace App\Services\Formularios;

use App\Models\Admisiones\Aspirante;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Admisiones\RespuestaCampo;
use App\Models\Formularios\CampoFormulario;
use App\Models\Formularios\Formulario;
use App\Models\Identidad\Persona;
use App\Services\ResolutorFormularios;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Contestar un formulario dinámico: qué campos tiene, lo ya contestado y guardar
 * lo nuevo.
 *
 * Vive aquí y no en el controlador porque lo comparten SIETE puertas —la ficha
 * del aspirante, su portal, la del alumno, el docente y su expediente, el tutor,
 * «Mis datos»— y ahora también la app. Es la MISMA captura con distinto quién:
 * separarlas llevaría a que una acepte un tipo de campo que la otra no, o a que
 * las reglas se separen sin que nadie lo decida.
 *
 * El titular puede ser un aspirante, una matrícula o una persona a secas; en
 * `respuestas_campo` las dos primeras son la CAPACIDAD en que se contestó, y por
 * eso van en la llave aunque sólo una lleve valor —sin el null explícito, guardar
 * como docente pisaría lo que esa misma persona contestó siendo aspirante—.
 */
class CapturaDeFormulario
{
    /** Lo que se acepta subir en un campo de tipo documento. */
    private const FORMATOS = 'pdf,jpg,jpeg,png';

    private const CARPETA = 'formularios';

    public function __construct(private readonly ResolutorFormularios $resolutor) {}

    /**
     * El formulario para contestarlo: su meta, sus campos y lo ya contestado.
     *
     * @return array{formulario: array<string, mixed>, campos: array<int, array<string, mixed>>, respuestas: array<string, mixed>}
     */
    public function ficha(Aspirante|MatriculaOferta|Persona $titular, Formulario $formulario): array
    {
        $this->exigirLeToca($titular, $formulario);

        return [
            'formulario' => [
                'id' => $formulario->id,
                'titulo' => $formulario->titulo,
                'instruccion' => $formulario->instruccion,
            ],
            'campos' => $this->campos($formulario),
            'respuestas' => $this->respuestasActuales($titular, $formulario),
        ];
    }

    /**
     * Un formulario que no le toca no se contesta. La pantalla sólo enlaza los
     * que sí, pero la URL lleva ids y se puede teclear.
     */
    public function exigirLeToca(Aspirante|MatriculaOferta|Persona $titular, Formulario $formulario): void
    {
        abort_unless(
            $this->resolutor->para($titular)->contains('id', $formulario->id),
            404,
            'Ese formulario no le corresponde.',
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function campos(Formulario $formulario): array
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
                // La condición viaja para esconder el campo en vivo; el servidor
                // la vuelve a mirar al validar.
                'campo_padre_id' => $c->campo_padre_id,
                'condicional' => $c->condicional,
                'opciones' => $c->opciones->map(fn ($o) => ['valor' => $o->valor, 'etiqueta' => $o->etiqueta])->values()->all(),
            ])
            ->all();
    }

    /**
     * Lo ya contestado, listo para prellenar. En `documento` va el id de la
     * respuesta (con el que se pide la descarga), no la ruta del disco privado.
     *
     * @return array<string, mixed>
     */
    public function respuestasActuales(Aspirante|MatriculaOferta|Persona $titular, Formulario $formulario): array
    {
        return RespuestaCampo::query()
            ->paraTitular($titular)
            ->whereIn('campo_formulario_id', $formulario->campos()->pluck('id'))
            ->get()
            ->mapWithKeys(fn (RespuestaCampo $r) => [
                (string) $r->campo_formulario_id => [
                    'valor' => $this->decodificar($r->valor),
                    'documento' => $r->documento_ruta === null ? null : $r->id,
                ],
            ])
            ->all();
    }

    /**
     * Guarda las respuestas: una fila por campo, con la versión del formulario
     * con la que se contestó. Un campo escondido por su condición no es
     * obligatorio (el servidor lo vuelve a mirar).
     */
    public function guardar(Request $request, Aspirante|MatriculaOferta|Persona $titular, Formulario $formulario): void
    {
        $this->exigirLeToca($titular, $formulario);

        $campos = $formulario->campos()->with('tipoCampo', 'opciones')->get();

        $datos = $request->validate(
            $this->reglas($campos, $request),
            $this->mensajes($campos),
            $this->atributos($campos),
        );

        DB::transaction(function () use ($campos, $datos, $titular, $formulario, $request) {
            foreach ($campos as $campo) {
                $this->guardarRespuesta($campo, $datos, $titular, $formulario, $request);
            }
        });
    }

    /**
     * @param  Collection<int, CampoFormulario>  $campos
     * @return array<string, mixed>
     */
    private function reglas($campos, Request $request): array
    {
        $reglas = [];

        foreach ($campos as $campo) {
            $clave = "campos.{$campo->id}";
            $tipo = $campo->tipoCampo?->clave;

            // Un campo escondido por su condición NO es obligatorio: exigirlo
            // dejaría el formulario imposible de enviar señalando algo que no está.
            $visible = $this->condicionSeCumple($campo, $request);
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

            if (filled($campo->regex) && ! in_array($tipo, ['documento', 'multiselect', 'checkbox'], true)) {
                $reglas[$clave][] = 'regex:/'.$campo->regex.'/';
            }
        }

        return $reglas;
    }

    /** ¿Se cumple la condición que hace visible a este campo? */
    private function condicionSeCumple(CampoFormulario $campo, Request $request): bool
    {
        if ($campo->campo_padre_id === null) {
            return true;
        }

        return (string) $request->input("campos.{$campo->campo_padre_id}") === (string) $campo->condicional;
    }

    /**
     * @param  Collection<int, CampoFormulario>  $campos
     * @return array<string, string>
     */
    private function mensajes($campos): array
    {
        $mensajes = [];

        foreach ($campos as $campo) {
            if (filled($campo->mensaje_error)) {
                // El mensaje que la escuela escribió gana al genérico.
                $mensajes["campos.{$campo->id}.regex"] = $campo->mensaje_error;
            }
        }

        return $mensajes;
    }

    /**
     * @param  Collection<int, CampoFormulario>  $campos
     * @return array<string, string>
     */
    private function atributos($campos): array
    {
        return $campos
            ->mapWithKeys(fn (CampoFormulario $c) => ["campos.{$c->id}" => mb_strtolower($c->pregunta)])
            ->all();
    }

    /**
     * Una fila por campo. Se reescribe la que hubiera: la respuesta actual es la
     * que vale.
     *
     * @param  array<string, mixed>  $datos
     */
    private function guardarRespuesta(
        CampoFormulario $campo,
        array $datos,
        Aspirante|MatriculaOferta|Persona $titular,
        Formulario $formulario,
        Request $request,
    ): void {
        $valor = $datos['campos'][$campo->id] ?? null;
        $llave = [...$this->llaveTitular($titular), 'campo_formulario_id' => $campo->id];

        if ($campo->tipoCampo?->clave === 'documento') {
            $archivo = $request->file("campos.{$campo->id}");

            // Sin archivo nuevo no se toca lo que ya estaba: reguardar para
            // corregir otra pregunta no puede borrar un acta ya subida.
            if ($archivo === null) {
                return;
            }

            $personaId = $this->personaDe($titular);

            RespuestaCampo::updateOrCreate($llave, [
                'persona_id' => $personaId,
                'formulario_version' => $formulario->version,
                'documento_ruta' => $archivo->store(self::CARPETA.'/'.$personaId, 'local'),
            ]);

            return;
        }

        RespuestaCampo::updateOrCreate($llave, [
            'persona_id' => $this->personaDe($titular),
            'formulario_version' => $formulario->version,
            'valor' => $this->codificar($valor),
        ]);
    }

    /**
     * En qué columnas cuelga la respuesta. Las dos de capacidad van en la llave
     * aunque sólo una lleve valor.
     *
     * @return array<string, int|null>
     */
    private function llaveTitular(Aspirante|MatriculaOferta|Persona $titular): array
    {
        return match (true) {
            $titular instanceof Aspirante => ['aspirante_id' => $titular->id, 'matricula_oferta_id' => null],
            $titular instanceof MatriculaOferta => ['matricula_oferta_id' => $titular->id, 'aspirante_id' => null],
            default => ['persona_id' => $titular->id, 'aspirante_id' => null, 'matricula_oferta_id' => null],
        };
    }

    /** De quién es la respuesta. `persona_id` va en toda fila. */
    private function personaDe(Aspirante|MatriculaOferta|Persona $titular): int
    {
        return $titular instanceof Persona ? $titular->id : $titular->persona_id;
    }

    /**
     * `valor` es texto y una selección múltiple son varios: se guardan como JSON
     * para recuperarlos como lista sin adivinar separadores.
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
