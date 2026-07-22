<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Academico\Oferta;
use App\Models\Formularios\CampoFormulario;
use App\Models\Landlord\Sexo;
use App\Models\Promocion\FormularioPublico;
use App\Services\RegistradorProspecto;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use RuntimeException;

/**
 * El formulario que la escuela embebe en su página web.
 *
 * **Va en Blade y no en Inertia, a propósito.** Este formulario se carga dentro
 * de un iframe en el sitio de la escuela: montar ahí la SPA administrativa
 * —medio megabyte de JavaScript, más las props compartidas de sesión, permisos
 * y tema— para pintar ocho campos a un anónimo sería absurdo, y arrastraría a
 * la página de la escuela todo el peso del panel. Una vista autocontenida carga
 * en un pestañeo y no sabe nada de la sesión, que es exactamente lo que debe
 * saber alguien que no ha entrado.
 *
 * Todo lo que llega aquí lo escribió un desconocido en internet: la validación
 * y las salvaguardas contra duplicados y sobreescritura viven en
 * `RegistradorProspecto`, que es quien toca la base.
 */
class FormularioPublicoController extends Controller
{
    public function __construct(private readonly RegistradorProspecto $registrador) {}

    public function mostrar(string $token): View
    {
        $publicacion = FormularioPublico::query()
            ->with('formulario')
            ->where('token', $token)
            ->firstOrFail();

        // Una convocatoria cerrada NO devuelve 404: el visitante llegó por un
        // enlace legítimo y merece saber que cerró, no toparse con un error
        // que parece de la escuela.
        if (! $publicacion->estaAbierto()) {
            return view('publico.cerrado', ['publicacion' => $publicacion]);
        }

        $publicacion->increment('visitas');

        return view('publico.formulario', [
            'publicacion' => $publicacion,
            'campos' => $this->campos($publicacion),
            'ofertas' => $publicacion->oferta_id !== null ? [] : $this->ofertas(),
            'sexos' => Sexo::orderBy('id')->get(['id', 'nombre']),
        ]);
    }

    public function enviar(Request $request, string $token): View
    {
        $publicacion = FormularioPublico::query()
            ->with('formulario')
            ->where('token', $token)
            ->firstOrFail();

        // Trampa para robots: un campo oculto que una persona nunca llena. Es
        // barato y atrapa la mayoría del spam automático sin pedirle nada al
        // visitante legítimo, a diferencia de un captcha.
        if (filled($request->input('sitio_web_confirmacion'))) {
            return view('publico.gracias', ['publicacion' => $publicacion, 'repetido' => false, 'usuario' => null]);
        }

        $reglas = [
            'nombre' => ['required', 'string', 'max:100'],
            'primer_apellido' => ['required', 'string', 'max:100'],
            'segundo_apellido' => ['nullable', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:150'],
            'celular' => ['nullable', 'string', 'max:20'],
            'curp' => ['nullable', 'string', 'size:18'],
            // Requerido porque `personas.sexo_id` es NOT NULL por decisión de
            // la spec. Se pregunta en vez de inventar un valor por omisión: un
            // dato de identidad no se rellena a espaldas de quien lo da.
            'sexo_id' => ['required', 'integer'],
            'acepto_terminos' => ['accepted'],
        ];

        if ($publicacion->oferta_id === null) {
            $reglas['oferta_id'] = ['required', 'integer'];
        }

        // En modo inscripción se crea cuenta, así que hace falta contraseña.
        if ($publicacion->permiteCuenta()) {
            $reglas['password'] = ['required', 'string', 'min:8', 'confirmed'];
        }

        foreach ($this->campos($publicacion) as $campo) {
            $reglas['respuestas.'.$campo->id] = $campo->obligatorio
                ? ['required']
                : ['nullable'];
        }

        $datos = Validator::make($request->all(), $reglas, [
            'acepto_terminos.accepted' => 'Hay que aceptar el aviso de privacidad para continuar.',
            'password.confirmed' => 'Las dos contraseñas no coinciden.',
        ])->validate();

        try {
            $resultado = $this->registrador->registrar($publicacion, $datos, $request->ip());
        } catch (RuntimeException $e) {
            return view('publico.cerrado', ['publicacion' => $publicacion, 'motivo' => $e->getMessage()]);
        }

        return view('publico.gracias', [
            'publicacion' => $publicacion,
            'repetido' => $resultado['repetido'],
            'usuario' => $resultado['usuario'],
        ]);
    }

    /**
     * Los campos del formulario dinámico, sin los condicionales: un campo que
     * depende de otro necesita lógica de pantalla que este embed no tiene, y
     * mostrarlo suelto le pediría al visitante algo fuera de contexto.
     *
     * @return Collection<int, CampoFormulario>
     */
    private function campos(FormularioPublico $publicacion)
    {
        return CampoFormulario::query()
            ->with('tipoCampo', 'opciones')
            ->where('formulario_id', $publicacion->formulario_id)
            ->whereNull('campo_padre_id')
            ->orderBy('orden')
            ->get();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function ofertas()
    {
        return Oferta::query()
            ->with('carrera:id,nombre', 'campus:id,nombre', 'turno:id,nombre')
            ->get()
            ->map(fn (Oferta $o) => [
                'id' => $o->id,
                'nombre' => trim(
                    ($o->carrera?->nombre ?? 'Programa')
                    .' · '.($o->campus?->nombre ?? '')
                    .($o->turno?->nombre ? ' · '.$o->turno->nombre : '')
                ),
            ])
            ->sortBy('nombre')
            ->values();
    }
}
