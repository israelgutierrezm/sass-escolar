<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Finanzas\Beca;
use App\Models\Finanzas\ConvenioDescuento;
use App\Services\Finanzas\ConvenioDeDescuento;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Convenios de descuento con empresas, sindicatos y dependencias.
 *
 * ── OJO con el nombre ──────────────────────────────────────────────────────
 * No es `ConvenioPagoController`. Aquél reprograma la deuda de UN alumno que no
 * puede pagar de golpe; éste es un acuerdo con un TERCERO por el que un grupo
 * de familias paga menos. Uno mueve fechas, el otro mueve importes.
 *
 * ── Sin permiso nuevo ──────────────────────────────────────────────────────
 * Va con `gestionar-planes-cobro`, el mismo con el que se definen las becas —y
 * los términos de un convenio SON una beca—. Un permiso más sin un acto propio
 * que proteger es una llave que la escuela reparte sin saber para qué.
 */
class ConvenioDescuentoController extends Controller
{
    public function __construct(private readonly ConvenioDeDescuento $convenios) {}

    public function index(Request $peticion): Response
    {
        $estatus = (string) $peticion->query('estatus', '');

        return Inertia::render('Finanzas/ConveniosDescuento/Index', [
            'convenios' => ConvenioDescuento::query()
                ->with('becas:id,clave,nombre,modo,valor,convenio_descuento_id')
                ->when($estatus !== '', fn ($q) => $q->where('estatus', $estatus))
                ->orderByDesc('id')
                ->get()
                ->map(fn (ConvenioDescuento $c) => $this->resumen($c)),
            'filtros' => ['estatus' => $estatus],
            'estatuses' => [
                ['valor' => ConvenioDescuento::VIGENTE, 'texto' => 'Vigentes'],
                ['valor' => ConvenioDescuento::TERMINADO, 'texto' => 'Terminados'],
            ],
            // Las becas que todavía no son de ningún convenio: son las que se
            // le pueden atar.
            'becasLibres' => Beca::query()
                ->whereNull('convenio_descuento_id')
                ->orderBy('nombre')
                ->get(['id', 'clave', 'nombre'])
                ->map(fn (Beca $b) => ['valor' => $b->id, 'texto' => $b->nombre.' ('.$b->clave.')']),
        ]);
    }

    public function guardar(Request $peticion, ?ConvenioDescuento $convenio = null): RedirectResponse
    {
        $datos = $peticion->validate([
            'nombre' => ['required', 'string', 'max:150'],
            'contraparte' => ['required', 'string', 'max:180'],
            'rfc' => ['nullable', 'string', 'max:13'],
            'contacto' => ['nullable', 'string', 'max:160'],
            'correo' => ['nullable', 'email', 'max:160'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'vigente_desde' => ['required', 'date'],
            // Obligatorio: un convenio sin fin no se acaba nunca, y el
            // descuento sigue vivo después de que la relación terminó.
            'vigente_hasta' => ['required', 'date', 'after_or_equal:vigente_desde'],
            'notas' => ['nullable', 'string', 'max:500'],
            'documento' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:8192'],
        ], [
            'vigente_hasta.required' => 'Dile hasta cuándo vale: un convenio sin fecha de fin sigue descontando después de que la relación terminó.',
        ]);

        $archivo = $peticion->file('documento');

        if ($archivo !== null) {
            // Al disco privado: es un contrato con datos de un tercero.
            $datos['documento_ruta'] = $archivo->store('convenios-descuento', 'local');
            $datos['documento_nombre'] = $archivo->getClientOriginalName();
        }

        unset($datos['documento']);

        $convenio === null
            ? ConvenioDescuento::create($datos)
            : $convenio->update($datos);

        return back(303)->with('exito', 'Convenio guardado.');
    }

    /** Ata una beca existente a este convenio: son sus términos. */
    public function atarBeca(Request $peticion, ConvenioDescuento $convenio): RedirectResponse
    {
        $datos = $peticion->validate([
            'beca_id' => ['required', 'integer'],
        ]);

        $beca = Beca::findOrFail($datos['beca_id']);

        if ($beca->convenio_descuento_id !== null && $beca->convenio_descuento_id !== $convenio->id) {
            return back(303)->with('error', 'Esa beca ya son los términos de otro convenio.');
        }

        $beca->update(['convenio_descuento_id' => $convenio->id]);

        return back(303)->with('exito', "«{$beca->nombre}» ahora son los términos de este convenio.");
    }

    public function terminar(Request $peticion, ConvenioDescuento $convenio): RedirectResponse
    {
        $datos = $peticion->validate(['motivo' => ['required', 'string', 'max:255']], [
            'motivo.required' => 'Escribe por qué se termina: se le va a quitar el descuento a las familias que lo tenían.',
        ]);

        $cerrados = $this->convenios->terminar($convenio, $datos['motivo']);

        /*
         * Con cero otorgamientos el mensaje NO puede decir que se recompusieron
         * cargos: afirmaría un trabajo que no se hizo, y ésa es la clase de
         * frase que enseña a no creerle a los avisos. Salió al terminar uno
         * vacío en el navegador.
         */
        return back(303)->with(
            'exito',
            $cerrados === 0
                ? 'Convenio terminado. No tenía ningún otorgamiento vigente.'
                : "Convenio terminado. Se cerraron {$cerrados} otorgamiento(s) y sus cargos pendientes se recompusieron sin el descuento."
        );
    }

    public function descargar(ConvenioDescuento $convenio): StreamedResponse
    {
        abort_unless($convenio->documento_ruta !== null, 404);
        abort_unless(Storage::disk('local')->exists($convenio->documento_ruta), 404);

        return Storage::disk('local')->download(
            $convenio->documento_ruta,
            $convenio->documento_nombre ?? 'convenio',
        );
    }

    /** @return array<string, mixed> */
    private function resumen(ConvenioDescuento $convenio): array
    {
        $panorama = $this->convenios->panorama($convenio);

        return [
            'id' => $convenio->id,
            'nombre' => $convenio->nombre,
            'contraparte' => $convenio->contraparte,
            'rfc' => $convenio->rfc,
            'contacto' => $convenio->contacto,
            'correo' => $convenio->correo,
            'telefono' => $convenio->telefono,
            'vigente_desde' => $convenio->vigente_desde?->toDateString(),
            'vigente_hasta' => $convenio->vigente_hasta?->toDateString(),
            'estatus' => $convenio->estatus,
            // Vencido y terminado son cosas distintas: uno tiene la fecha
            // pasada y sigue con estatus vigente hasta que el barrido lo cierre.
            'vencido' => $convenio->estaVencido(),
            'terminado_en' => $convenio->terminado_en?->format('d/m/Y H:i'),
            'motivo_termino' => $convenio->motivo_termino,
            'documento' => $convenio->documento_nombre,
            'notas' => $convenio->notas,
            'becas' => $convenio->becas->map(fn (Beca $b) => [
                'id' => $b->id,
                'nombre' => $b->nombre,
                'clave' => $b->clave,
                'valor' => $b->modo === Beca::MODO_PORCENTAJE
                    ? rtrim(rtrim(number_format((float) $b->valor * 100, 2), '0'), '.').' %'
                    : '$'.number_format((float) $b->valor, 2),
            ])->values(),
            'beneficiarios' => $panorama['beneficiarios'],
            'descontado' => $panorama['descontado'],
        ];
    }
}
