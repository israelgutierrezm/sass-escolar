<?php

declare(strict_types=1);

namespace App\Http\Controllers\Plataforma;

use App\Enums\DestinoEvento;
use App\Enums\PrioridadAviso;
use App\Http\Controllers\Concerns\ArmaDestinos;
use App\Http\Controllers\Controller;
use App\Models\Plataforma\Aviso;
use App\Models\Plataforma\AvisoLectura;
use App\Services\Plataforma\SeguimientoDeAviso;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Los avisos de la escuela: redactarlos, dirigirlos y ver quién los leyó.
 *
 * ── Por qué no están en el calendario ──────────────────────────────────────
 * Un evento ocupa un día en la rejilla; un aviso es un mensaje que tiene que
 * llegar. Ver la migración `avisos` para el razonamiento completo.
 *
 * ── La segmentación se reusa entera ────────────────────────────────────────
 * Los mismos criterios que el calendario —`DestinoEvento`: todos, rol, campus,
 * nivel, carrera, plan, grupo, materia, alumno— con el mismo componente de
 * captura. No hay una segunda forma de decir «esto es para los de tercero de
 * Derecho», que es como acaban divergiendo dos pantallas que hacen lo mismo.
 */
class AvisoController extends Controller
{
    use ArmaDestinos;

    public function index(Request $request): Response
    {
        $avisos = Aviso::query()
            ->with('destinos')
            ->withCount([
                // Cuántos lo han confirmado: es lo que convierte un aviso
                // crítico en algo comprobable y no en un acto de fe.
                'lecturas as confirmadas' => fn ($q) => $q->whereNotNull('confirmado_en'),
                'lecturas as vistas' => fn ($q) => $q->whereNotNull('visto_en'),
            ])
            ->orderByDesc('publicado')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Aviso $a) => [
                'id' => $a->id,
                'titulo' => $a->titulo,
                'cuerpo' => $a->cuerpo,
                'prioridad' => $a->prioridad->value,
                'prioridad_etiqueta' => $a->prioridad->etiqueta(),
                'color' => $a->prioridad->color(),
                'exige_confirmacion' => $a->exigeConfirmacion(),
                'publicado_desde' => $a->publicado_desde?->format('Y-m-d\TH:i'),
                'vigente_hasta' => $a->vigente_hasta?->format('Y-m-d\TH:i'),
                'publicado' => $a->publicado,
                'vigente' => $a->publicado
                    && ($a->publicado_desde === null || $a->publicado_desde->lte(now()))
                    && ($a->vigente_hasta === null || $a->vigente_hasta->gte(now())),
                'confirmadas' => $a->confirmadas,
                'vistas' => $a->vistas,
                'destinos' => $a->destinos->map(fn ($d) => [
                    'tipo' => $d->tipo->value,
                    'destino_id' => $d->destino_id,
                ])->values(),
            ]);

        return Inertia::render('Plataforma/Avisos', [
            'avisos' => $avisos,
            'prioridades' => PrioridadAviso::paraSelector(),
            'tiposDestino' => DestinoEvento::paraSelect(),
            'opciones' => $this->opcionesDeDestino(),
        ]);
    }

    public function guardar(Request $request, ?Aviso $aviso = null): RedirectResponse
    {
        $datos = $request->validate([
            'titulo' => ['required', 'string', 'max:180'],
            'cuerpo' => ['required', 'string', 'max:5000'],
            'prioridad' => ['required', Rule::enum(PrioridadAviso::class)],
            'publicado_desde' => ['nullable', 'date'],
            'vigente_hasta' => ['nullable', 'date', 'after_or_equal:publicado_desde'],
            'publicado' => ['boolean'],
            // Sin destinos no lo ve nadie, y un aviso que nadie ve no es un
            // aviso: es un texto guardado en una tabla.
            'destinos' => ['required', 'array', 'min:1'],
            'destinos.*.tipo' => ['required', Rule::enum(DestinoEvento::class)],
            'destinos.*.destino_id' => ['nullable', 'integer'],
        ], [
            'destinos.required' => 'Elige a quién va dirigido.',
            'vigente_hasta.after_or_equal' => 'La vigencia no puede terminar antes de empezar.',
        ], [
            'cuerpo' => 'el texto del aviso',
            'publicado_desde' => 'la fecha de publicación',
            'vigente_hasta' => 'el fin de vigencia',
        ]);

        $guardado = DB::transaction(function () use ($datos, $aviso) {
            $registro = $aviso ?? new Aviso;

            $registro->fill([
                'titulo' => $datos['titulo'],
                'cuerpo' => $datos['cuerpo'],
                'prioridad' => $datos['prioridad'],
                'publicado_desde' => $datos['publicado_desde'] ?? null,
                'vigente_hasta' => $datos['vigente_hasta'] ?? null,
                'publicado' => $datos['publicado'] ?? false,
            ])->save();

            /*
             * Los destinos se rehacen enteros en cada guardado.
             *
             * Calcular altas y bajas contra lo que había sería más código para
             * el mismo resultado: son media docena de renglones sin nada que
             * conservar —ni id que alguien referencie, ni fecha propia—.
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
            $aviso === null
                ? ($guardado->publicado ? 'Aviso publicado.' : 'Aviso guardado en borrador.')
                : 'Aviso actualizado.',
        );
    }

    public function eliminar(Aviso $aviso): RedirectResponse
    {
        /*
         * Con confirmaciones encima NO se borra, se retira.
         *
         * Las confirmaciones son la constancia de que alguien declaró haber
         * leído esto; borrarlas junto al aviso destruye justo la prueba para la
         * que existen. Despublicarlo lo quita de en medio y conserva el rastro.
         */
        if ($aviso->lecturas()->whereNotNull('confirmado_en')->exists()) {
            $aviso->update(['publicado' => false]);

            return back(303)->with(
                'info',
                'Este aviso ya tenía confirmaciones de lectura, así que se retiró en vez de borrarse: la constancia de quién lo leyó se conserva.',
            );
        }

        $aviso->delete();

        return back(303)->with('exito', 'Aviso eliminado.');
    }

    /** Publicar o retirar sin abrir el formulario: es el gesto más repetido. */
    public function publicacion(Request $request, Aviso $aviso): RedirectResponse
    {
        // El campo llega como `publicada` porque lo manda `InterruptorVisible`,
        // que es el mismo componente de todos los listados: se acata su contrato
        // en vez de hacerle una excepción a este.
        $publicado = $request->boolean('publicada');

        $aviso->update(['publicado' => $publicado]);

        return back(303)->with(
            'exito',
            $publicado ? "«{$aviso->titulo}» ya se está mostrando." : "«{$aviso->titulo}» quedó retirado.",
        );
    }

    /**
     * Cómo va el aviso: a cuántos alcanzó, quién lo vio y quién lo confirmó.
     *
     * Las cifras van arriba y los nombres abajo a propósito: lo primero que se
     * pregunta quien publicó algo es «¿llegó?», y para eso el total y el
     * desglose por rol contestan de un vistazo. La lista nominal es para el
     * segundo paso —ir a buscar a quien falta—, no para contar a mano.
     */
    public function lecturas(Aviso $aviso, SeguimientoDeAviso $seguimiento): Response
    {
        return Inertia::render('Plataforma/AvisoLecturas', [
            'aviso' => [
                'id' => $aviso->id,
                'titulo' => $aviso->titulo,
                'cuerpo' => $aviso->cuerpo,
                'prioridad_etiqueta' => $aviso->prioridad->etiqueta(),
                'color' => $aviso->prioridad->color(),
                'publicado' => $aviso->publicado,
            ],
            'seguimiento' => $seguimiento->de($aviso),
            'lecturas' => AvisoLectura::query()
                ->where('aviso_id', $aviso->id)
                ->with('persona')
                ->orderByDesc('confirmado_en')
                ->orderByDesc('visto_en')
                ->get()
                ->map(fn (AvisoLectura $l) => [
                    'quien' => $l->persona?->nombreCompleto() ?? 'Alguien',
                    'visto' => $l->visto_en?->toDateTimeString(),
                    'confirmado' => $l->confirmado_en?->toDateTimeString(),
                ]),
        ]);
    }
}
