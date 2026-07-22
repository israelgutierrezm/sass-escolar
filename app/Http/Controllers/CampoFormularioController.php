<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Formularios\CampoFormulario;
use App\Models\Formularios\Formulario;
use App\Models\Formularios\OpcionCampo;
use App\Models\Formularios\TipoCampo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Los campos de un formulario y sus opciones.
 *
 * Un formulario con respuestas capturadas está congelado: agregarle, quitarle o
 * cambiarle campos reescribiría preguntas que alguien ya contestó. Se valida en
 * cada acción y no una sola vez, porque cada una entra por su propia ruta.
 */
class CampoFormularioController extends Controller
{
    /** Tipos que necesitan opciones para significar algo. */
    private const TIPOS_CON_OPCIONES = ['select', 'multiselect', 'radio'];

    public function store(Request $request, Formulario $formulario): RedirectResponse
    {
        $this->exigirEditable($formulario);

        $datos = $this->validar($request, $formulario);

        CampoFormulario::create([
            ...$datos,
            'formulario_id' => $formulario->id,
            'orden' => $datos['orden'] ?? ((int) $formulario->campos()->max('orden') + 1),
        ]);

        return back()->with('exito', 'Campo agregado.');
    }

    public function update(Request $request, Formulario $formulario, CampoFormulario $campo): RedirectResponse
    {
        abort_unless($campo->formulario_id === $formulario->id, 404);

        $this->exigirEditable($formulario);

        $campo->update($this->validar($request, $formulario, $campo));

        return back()->with('exito', 'Campo actualizado.');
    }

    public function destroy(Formulario $formulario, CampoFormulario $campo): RedirectResponse
    {
        abort_unless($campo->formulario_id === $formulario->id, 404);

        $this->exigirEditable($formulario);

        // Los campos que dependían de este quedarían condicionados a algo que ya
        // no existe: se les quita la condición en vez de dejarlos rotos.
        DB::transaction(function () use ($campo): void {
            CampoFormulario::query()
                ->where('campo_padre_id', $campo->id)
                ->update(['campo_padre_id' => null, 'condicional' => null]);

            $campo->delete();
        });

        return back()->with('exito', 'Campo eliminado.');
    }

    /** Sube o baja un campo en el orden de la pantalla. */
    public function mover(Request $request, Formulario $formulario, CampoFormulario $campo): RedirectResponse
    {
        abort_unless($campo->formulario_id === $formulario->id, 404);

        $this->exigirEditable($formulario);

        $datos = $request->validate(['direccion' => ['required', Rule::in(['arriba', 'abajo'])]]);

        $vecino = CampoFormulario::query()
            ->where('formulario_id', $formulario->id)
            ->when(
                $datos['direccion'] === 'arriba',
                fn ($q) => $q->where('orden', '<', $campo->orden)->orderByDesc('orden'),
                fn ($q) => $q->where('orden', '>', $campo->orden)->orderBy('orden'),
            )
            ->first();

        if ($vecino === null) {
            return back(); // ya está en el extremo
        }

        DB::transaction(function () use ($campo, $vecino): void {
            $suyo = $campo->orden;
            $campo->update(['orden' => $vecino->orden]);
            $vecino->update(['orden' => $suyo]);
        });

        return back();
    }

    /*
    |--------------------------------------------------------------------------
    | Opciones (select, radio, multiselect)
    |--------------------------------------------------------------------------
    */

    public function agregarOpcion(Request $request, Formulario $formulario, CampoFormulario $campo): RedirectResponse
    {
        abort_unless($campo->formulario_id === $formulario->id, 404);

        $this->exigirEditable($formulario);

        $datos = $request->validate([
            'etiqueta' => ['required', 'string', 'max:255'],
            'valor' => ['nullable', 'string', 'max:100'],
        ]);

        // El valor es lo que se guarda en la respuesta; la etiqueta lo que se
        // lee. Si no se da, se deriva de la etiqueta para no obligar a
        // inventarlo en cada opción.
        $valor = $datos['valor'] ?? null;

        if ($valor === null || trim($valor) === '') {
            $valor = mb_substr(preg_replace('/[^a-z0-9]+/', '_', mb_strtolower($datos['etiqueta'])) ?? '', 0, 100);
            $valor = trim($valor, '_');
        }

        $repetido = OpcionCampo::query()
            ->where('campo_formulario_id', $campo->id)
            ->where('valor', $valor)
            ->exists();

        if ($repetido) {
            throw ValidationException::withMessages([
                'etiqueta' => 'Ese valor ya existe en este campo; dos opciones con el mismo valor son indistinguibles en la respuesta.',
            ]);
        }

        OpcionCampo::create([
            'campo_formulario_id' => $campo->id,
            'valor' => $valor,
            'etiqueta' => $datos['etiqueta'],
            'orden' => (int) $campo->opciones()->max('orden') + 1,
        ]);

        return back()->with('exito', 'Opción agregada.');
    }

    public function eliminarOpcion(Formulario $formulario, CampoFormulario $campo, OpcionCampo $opcion): RedirectResponse
    {
        abort_unless($campo->formulario_id === $formulario->id && $opcion->campo_formulario_id === $campo->id, 404);

        $this->exigirEditable($formulario);

        // Si algún campo se muestra solo cuando se elige esta opción, esa
        // condición dejaría de cumplirse nunca. Se avisa en vez de dejarlo mudo.
        $dependientes = CampoFormulario::query()
            ->where('campo_padre_id', $campo->id)
            ->where('condicional', $opcion->valor)
            ->count();

        if ($dependientes > 0) {
            return back()->with(
                'error',
                "No se puede quitar: {$dependientes} campo(s) se muestran solo con esa opción. Cámbiales la condición primero.",
            );
        }

        $opcion->delete();

        return back()->with('exito', 'Opción eliminada.');
    }

    /*
    |--------------------------------------------------------------------------
    | Apoyo
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<string, mixed>
     */
    private function validar(Request $request, Formulario $formulario, ?CampoFormulario $campo = null): array
    {
        $datos = $request->validate([
            'pregunta' => ['required', 'string', 'max:255'],
            'descripcion' => ['nullable', 'string', 'max:255'],
            'tipo_campo_id' => ['required', 'integer', Rule::exists('tipos_campo', 'id')->whereNull('deleted_at')],
            'obligatorio' => ['boolean'],
            'regex' => ['nullable', 'string', 'max:255'],
            'mensaje_error' => ['nullable', 'string', 'max:150'],
            'orden' => ['nullable', 'integer', 'min:0'],
            'min' => ['nullable', 'numeric'],
            'max' => ['nullable', 'numeric', 'gte:min'],
            'campo_padre_id' => ['nullable', 'integer'],
            'condicional' => ['nullable', 'string', 'max:100'],
        ], [
            'max.gte' => 'El máximo no puede ser menor que el mínimo.',
        ], [
            'tipo_campo_id' => 'tipo de campo',
            'campo_padre_id' => 'campo del que depende',
        ]);

        $this->validarCondicional($datos, $formulario, $campo);
        $this->validarRegex($datos);

        return $datos;
    }

    /**
     * El campo del que depende tiene que existir en ESTE formulario, no ser él
     * mismo, y no formar un ciclo.
     *
     * @param  array<string, mixed>  $datos
     */
    private function validarCondicional(array $datos, Formulario $formulario, ?CampoFormulario $campo): void
    {
        $padreId = $datos['campo_padre_id'] ?? null;

        if ($padreId === null) {
            return;
        }

        if ($campo !== null && (int) $padreId === $campo->id) {
            throw ValidationException::withMessages([
                'campo_padre_id' => 'Un campo no puede depender de sí mismo.',
            ]);
        }

        $padre = CampoFormulario::query()
            ->where('formulario_id', $formulario->id)
            ->find($padreId);

        if ($padre === null) {
            throw ValidationException::withMessages([
                'campo_padre_id' => 'Ese campo no pertenece a este formulario.',
            ]);
        }

        // Ciclo: si el padre depende (directa o indirectamente) de este campo,
        // ninguno de los dos se mostraría jamás.
        if ($campo !== null && $this->desciendeDe($padre, $campo->id)) {
            throw ValidationException::withMessages([
                'campo_padre_id' => 'Eso crearía una dependencia circular: ninguno de los dos campos llegaría a mostrarse.',
            ]);
        }

        if (trim((string) ($datos['condicional'] ?? '')) === '') {
            throw ValidationException::withMessages([
                'condicional' => 'Indica con qué valor del campo padre se muestra este campo.',
            ]);
        }
    }

    private function desciendeDe(CampoFormulario $campo, int $posibleDescendienteId): bool
    {
        $actual = $campo;
        $vistos = [];

        while ($actual !== null && $actual->campo_padre_id !== null) {
            if ($actual->campo_padre_id === $posibleDescendienteId) {
                return true;
            }

            if (isset($vistos[$actual->id])) {
                return true; // ciclo preexistente
            }

            $vistos[$actual->id] = true;
            $actual = CampoFormulario::find($actual->campo_padre_id);
        }

        return false;
    }

    /**
     * Una expresión regular inválida haría fallar la validación de CADA
     * respuesta, y el error aparecería al capturar y no al configurar.
     *
     * @param  array<string, mixed>  $datos
     */
    private function validarRegex(array $datos): void
    {
        $regex = $datos['regex'] ?? null;

        if ($regex === null || trim($regex) === '') {
            return;
        }

        // Se prueba en silencio: preg_match avisa por warning, no por excepción.
        if (@preg_match('/'.str_replace('/', '\/', $regex).'/', '') === false) {
            throw ValidationException::withMessages([
                'regex' => 'Esa expresión regular no es válida.',
            ]);
        }
    }

    private function exigirEditable(Formulario $formulario): void
    {
        $respuestas = DB::table('respuestas_campo')
            ->whereIn(
                'campo_formulario_id',
                DB::table('campos_formulario')->where('formulario_id', $formulario->id)->select('id')
            )
            ->count();

        if ($respuestas > 0) {
            throw ValidationException::withMessages([
                'formulario' => 'Este formulario ya tiene respuestas: publica una versión nueva para cambiarlo.',
            ]);
        }
    }

    /** ¿Este tipo de campo necesita opciones? Lo consulta la interfaz. */
    public static function necesitaOpciones(?TipoCampo $tipo): bool
    {
        return $tipo !== null && in_array($tipo->clave, self::TIPOS_CON_OPCIONES, true);
    }
}
