<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Rules\CurpValida;
use App\Services\IdentidadPersona;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validación del alta y edición de un aspirante.
 *
 * Captura datos de PERSONA y de ASPIRANTE en una sola pantalla, pero se
 * guardan en sus tablas respectivas: la identidad es de la persona y no se
 * duplica si ya existe.
 */
class GuardarAspiranteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // la autorización la aplica el middleware `can:` de la ruta
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $personaId = $this->route('aspirante')?->persona_id;

        return [
            // Persona
            'nombre' => ['required', 'string', 'max:255'],
            'primer_apellido' => ['required', 'string', 'max:255'],
            'segundo_apellido' => ['nullable', 'string', 'max:255'],
            // Sin `size:18`: hay que dejar pasar la marca EXTRANJERO, y de
            // todos modos medir 18 nunca fue validar una CURP. `CurpValida`
            // comprueba el dígito verificador.
            // Y SIN `unique`. La había, y contradecía al propio controlador: el
            // alta REUTILIZA a la persona cuando la CURP ya existe —es el
            // principio de cero recaptura—, pero la validación la rechazaba
            // antes de llegar ahí. O sea que esa rama de `store()` nunca
            // corría y quien intentaba registrar a un egresado que vuelve por
            // un posgrado se topaba con «ya existe una persona con esa CURP» y
            // ningún camino para continuar.
            //
            // Reutilizar ES la protección contra duplicados; rechazar no lo es.
            // Al editar sí se cuida: dos personas distintas no pueden terminar
            // con la misma CURP.
            'curp' => array_filter([
                'nullable',
                'string',
                'max:20',
                new CurpValida,
                $personaId === null
                    ? null
                    : Rule::unique('personas', 'curp')->ignore($personaId)->whereNull('deleted_at'),
            ]),
            'fecha_nacimiento' => ['nullable', 'date', 'before:today'],
            // `sexo_id` ya no se pregunta: se deriva de la CURP o del género en
            // `IdentidadPersona`. Preguntarlo era pedir dos veces lo mismo.
            'genero_id' => ['nullable', 'integer'],
            'entidad_nacimiento_id' => ['nullable', 'integer'],
            'pais_nacimiento_id' => ['nullable', 'integer'],
            // El correo pasa a OBLIGATORIO: es la credencial con la que el
            // aspirante entrará a su portal. Sin él hay que perseguirlo por
            // teléfono para darle acceso, que es justo lo que el portal evita.
            //
            // Y ÚNICO en la plataforma: dos cuentas con el mismo correo cruzan
            // sesiones en el login. Se excluye la persona que se reutiliza por
            // CURP (o se edita), que sí puede conservar el suyo.
            'email' => ['required', 'email', 'max:150', function (string $atributo, mixed $valor, \Closure $fallar) {
                $conflicto = app(IdentidadPersona::class)->correoEnUso($valor, $this->personaDestino());

                if ($conflicto !== null) {
                    $fallar('Ese correo ya está registrado con otra persona ('.$conflicto->nombreCompleto().'). Usa otro, o captura su CURP para reutilizarla.');
                }
            }],
            'celular' => ['nullable', 'string', 'max:20'],

            // Aspirante
            'oferta_interes_id' => ['nullable', 'integer', Rule::exists('oferta', 'id')->whereNull('deleted_at')],
            'campus_id' => ['nullable', 'integer', Rule::exists('campus', 'id')->whereNull('deleted_at')],
            'situacion_id' => ['required', 'integer', Rule::exists('situaciones_aspirante', 'id')->whereNull('deleted_at')],
            // `origen_id` es el catálogo del CRM; `origen` es el texto libre de
            // antes, que se conserva para no perder lo ya capturado.
            'origen_id' => ['nullable', 'integer', Rule::exists('origenes_aspirante', 'id')->whereNull('deleted_at')],
            'origen' => ['nullable', 'string', 'max:80'],
            // `acepto_terminos` NO se acepta por esta vía. Aceptar los términos
            // del proceso es un acto del interesado, no de quien lo captura:
            // quien registra desde la administración no puede consentir en
            // nombre de otro. Solo el portal del aspirante lo escribe.
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'email' => 'correo',
            'primer_apellido' => 'primer apellido',
            'segundo_apellido' => 'segundo apellido',
            'fecha_nacimiento' => 'fecha de nacimiento',
            'genero_id' => 'género',
            'entidad_nacimiento_id' => 'entidad de nacimiento',
            'oferta_interes_id' => 'oferta de interés',
            'campus_id' => 'campus',
            'situacion_id' => 'situación',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'curp.unique' => 'Ya existe una persona registrada con esa CURP.',
            'email.required' => 'El correo es obligatorio: es el usuario con el que el aspirante entrará a su portal.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'curp' => $this->filled('curp') ? mb_strtoupper(trim((string) $this->input('curp'))) : null,
            'email' => $this->filled('email') ? mb_strtolower(trim((string) $this->input('email'))) : null,
        ]);
    }

    /**
     * La persona a la que se escribirán los datos: al editar, la del aspirante;
     * al crear, la que se reutilizará si la CURP ya existe. Es a quien NO se le
     * reporta el correo como duplicado (es su propio correo).
     */
    private function personaDestino(): ?int
    {
        return $this->route('aspirante')?->persona_id
            ?? app(IdentidadPersona::class)->existentePorCurp($this->input('curp'))?->id;
    }
}
