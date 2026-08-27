<?php

declare(strict_types=1);

namespace App\Reportes\Envio;

use App\Mail\ReporteProgramado as CorreoDelReporte;
use App\Models\Identidad\Usuario;
use App\Models\Reportes\DestinatarioReporte;
use App\Models\Reportes\ProgramacionReporte;
use App\Reportes\Ejecutor;
use App\Reportes\RegistroReportes;
use App\Reportes\Salida\ExportadorCsv;
use App\Reportes\Salida\ExportadorXlsx;
use App\Services\Correo\CorreoService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Manda los reportes programados que toquen.
 *
 * ── La regla que esto existe para proteger ───────────────────────────────
 * Un reporte programado corre **con el rol GUARDADO en la programación**, no
 * con alcance global ni con el rol que su dueño tenga activo hoy.
 *
 * De madrugada no hay nadie con una sesión abierta, así que no hay rol activo
 * del que sacar el alcance por campus —`Usuario::campusVisibles()` lo lee de
 * `persona_rol.campus_id` del rol ACTIVO—. Correr con alcance global sería
 * mandarle por correo la escuela entera a quien sólo ve un plantel, todos los
 * lunes y sin que nadie lo mirara: la peor forma de fuga, porque es silenciosa y
 * periódica.
 *
 * ── Y si el dueño pierde el permiso, se SUSPENDE ─────────────────────────
 * No se degrada, no corre «con lo que le quede» y no se borra. Se suspende con
 * el motivo escrito, para que la pantalla pueda decir por qué dejó de llegar —
 * que es la pregunta que alguien va a hacer.
 *
 * ── Quién recibe qué ─────────────────────────────────────────────────────
 * El archivo lo produce el alcance del DUEÑO, así que a un destinatario podría
 * llegarle más de lo que él vería entrando. Se acota por las dos puntas:
 *
 *  - Se descarta al destinatario que no tenga el permiso del reporte, y se
 *    anota. Sin eso, programar sería una puerta lateral para hacerle llegar a
 *    alguien un padrón que su rol le niega.
 *  - Y el correo DICE con el alcance de quién salió. Un archivo que trae más de
 *    lo que su lector esperaba, sin decirlo, se reenvía creyendo otra cosa.
 */
class EnviadorProgramado
{
    public function __construct(
        private readonly Ejecutor $ejecutor,
        private readonly RegistroReportes $registro,
        private readonly CorreoService $correo,
    ) {}

    /**
     * Corre las que toquen a este momento.
     *
     * @return array<int, array<string, mixed>> una línea por programación tocada
     */
    public function correrLasQueTocan(Carbon $momento, bool $seco = false): array
    {
        $lineas = [];

        $candidatas = ProgramacionReporte::query()
            ->vivas()
            ->with(['vista', 'dueno', 'rol', 'destinatarios'])
            ->get()
            ->filter(fn (ProgramacionReporte $p) => $p->leTocaA($momento));

        foreach ($candidatas as $programacion) {
            $lineas[] = $this->correr($programacion, $momento, $seco);
        }

        return $lineas;
    }

    /**
     * Una programación.
     *
     * @return array<string, mixed>
     */
    public function correr(ProgramacionReporte $programacion, Carbon $momento, bool $seco = false): array
    {
        $vista = $programacion->vista;

        if ($vista === null) {
            return $this->suspender($programacion, 'La vista guardada que enseñaba ya no existe.', $seco);
        }

        $usuario = Usuario::query()->where('persona_id', $programacion->persona_id)->first();

        if ($usuario === null) {
            return $this->suspender($programacion, 'Su dueño ya no tiene cuenta en la escuela.', $seco);
        }

        /*
         * ── El alcance se FIJA aquí, y es lo único que importa ────────────
         *
         * Se pone como rol activo el que la programación guardó, no el que la
         * persona tenga puesto hoy: quien programó la cartera de su campus y
         * luego conmutó a un rol global no puede empezar a recibir la escuela
         * entera por haber cambiado de sombrero.
         *
         * Y se comprueba que TODAVÍA lo tenga: si se lo retiraron, el `can()` de
         * abajo daría el resultado de otro rol.
         */
        if (! $this->conservaElRol($usuario, $programacion)) {
            return $this->suspender(
                $programacion,
                'A su dueño le retiraron el rol «'.($programacion->rol?->nombre ?? '—').'», que es con el que corría.',
                $seco,
            );
        }

        $usuario->rol_activo_id = $programacion->rol_id;

        $definicion = $this->registro->todos()[$vista->reporte] ?? null;

        if ($definicion === null) {
            return $this->suspender($programacion, 'El reporte que enviaba ya no existe.', $seco);
        }

        $fuente = $this->registro->fuente($definicion->fuente());

        if (! $usuario->can($fuente->permiso())) {
            return $this->suspender(
                $programacion,
                'Su dueño ya no tiene permiso para ver este reporte.',
                $seco,
            );
        }

        try {
            [$archivo, $nombre, $filas] = $this->generar($usuario, $vista, $programacion);
        } catch (Throwable $falla) {
            return $this->anotar($programacion, ProgramacionReporte::ERROR, $momento, $seco, [
                'error' => $falla->getMessage(),
            ]);
        }

        $destinos = $this->destinos($programacion, $fuente->permiso());

        if ($destinos['correos'] === []) {
            /*
             * Se dice A QUIÉN se descartó y por qué. «Ninguno de sus
             * destinatarios puede ver este reporte» es cierto y no sirve: quien
             * lo lea tiene que poder ir a arreglarlo, y para eso necesita el
             * nombre y el motivo de cada uno.
             */
            return $this->anotar($programacion, ProgramacionReporte::ERROR, $momento, $seco, [
                'error' => $destinos['motivo']
                    ?? ('No quedó nadie a quien mandarlo: se descartó a '
                        .implode('; ', $destinos['descartados']).'.'),
                'descartados' => $destinos['descartados'],
            ]);
        }

        if (! $seco) {
            $this->correo->aplicar();

            Mail::to($destinos['correos'])->send(new CorreoDelReporte(
                titulo: $definicion->titulo(),
                vista: $vista->nombre,
                cuando: $programacion->cuando(),
                alcanceDe: $programacion->dueno?->nombreCompleto() ?? '—',
                rol: $programacion->rol?->nombre ?? '—',
                filas: $filas,
                archivo: $archivo,
                nombreArchivo: $nombre,
            ));
        }

        return $this->anotar(
            $programacion,
            $filas === 0 ? ProgramacionReporte::VACIO : ProgramacionReporte::OK,
            $momento,
            $seco,
            ['filas' => $filas, 'a' => count($destinos['correos']), 'descartados' => $destinos['descartados']],
        );
    }

    /**
     * Si la persona todavía tiene ASIGNADO el rol con el que corría.
     *
     * Se mira la asignación activa y no el rol activo: el rol activo es lo que
     * tiene puesto ahora mismo, y esto pregunta si sigue pudiendo ponérselo.
     */
    private function conservaElRol(Usuario $usuario, ProgramacionReporte $programacion): bool
    {
        return $usuario->persona
            ->asignacionesRol()
            ->where('rol_id', $programacion->rol_id)
            ->where('activo', true)
            ->exists();
    }

    /**
     * El archivo, con el alcance de quien se le pase.
     *
     * @return array{0: string, 1: string, 2: int} contenido, nombre y cuántas filas
     */
    private function generar(Usuario $usuario, $vista, ProgramacionReporte $programacion): array
    {
        $exportacion = $this->ejecutor->paraExportar($usuario, $vista->reporte, [
            'columnas' => $vista->columnas,
            'filtros' => $vista->filtros ?? [],
            'orden_por' => $vista->orden_por,
            'orden_dir' => $vista->orden_dir,
        ]);

        $exportador = $programacion->formato === 'csv'
            ? app(ExportadorCsv::class)
            : app(ExportadorXlsx::class);

        /*
         * Los exportadores escriben a la salida porque su trabajo normal es una
         * descarga. Aquí hace falta el contenido en la mano, así que se captura.
         */
        ob_start();

        try {
            $exportador->responder($exportacion)->sendContent();
            $contenido = (string) ob_get_clean();
        } catch (Throwable $falla) {
            ob_end_clean();

            throw $falla;
        }

        $nombre = str($vista->nombre)->slug()->value().'-'.now()->format('Y-m-d').'.'.$programacion->formato;

        return [$contenido, $nombre, $exportacion->total];
    }

    /**
     * A qué correos va, descartando a quien no pueda ver el reporte.
     *
     * @return array{correos: array<int, string>, descartados: array<int, string>, motivo: string|null}
     */
    private function destinos(ProgramacionReporte $programacion, string $permiso): array
    {
        $candidatos = collect();

        foreach ($programacion->destinatarios as $destinatario) {
            $candidatos = $candidatos->merge(match ($destinatario->tipo) {
                DestinatarioReporte::PERSONA => Usuario::query()
                    ->where('persona_id', $destinatario->destino_id)->get(),
                DestinatarioReporte::ROL => Usuario::query()
                    ->whereHas('persona.asignacionesRol', fn ($q) => $q
                        ->where('rol_id', $destinatario->destino_id)
                        ->where('activo', true))
                    ->get(),
                default => collect(),
            });
        }

        $correos = [];
        $descartados = [];

        foreach ($candidatos->unique('id') as $candidato) {
            if ($candidato->email === null || $candidato->email === '') {
                $descartados[] = ($candidato->persona?->nombreCompleto() ?? '—').' (sin correo)';

                continue;
            }

            /*
             * El permiso se comprueba con el rol que ESE destinatario tenga
             * activo. Es lo que impide que programar sea una puerta lateral para
             * hacerle llegar a alguien un padrón que su rol le niega.
             */
            if (! $candidato->can($permiso)) {
                $descartados[] = ($candidato->persona?->nombreCompleto() ?? '—').' (sin permiso)';

                continue;
            }

            $correos[] = $candidato->email;
        }

        return [
            'correos' => array_values(array_unique($correos)),
            'descartados' => $descartados,
            'motivo' => $candidatos->isEmpty() ? 'La programación no tiene destinatarios.' : null,
        ];
    }

    /** @return array<string, mixed> */
    private function suspender(ProgramacionReporte $programacion, string $motivo, bool $seco): array
    {
        if (! $seco) {
            $programacion->forceFill([
                'suspendida_en' => now(),
                'motivo_suspension' => $motivo,
                'ultimo_estado' => ProgramacionReporte::ERROR,
                'ultimo_error' => $motivo,
            ])->save();
        }

        return [
            'programacion' => $programacion->nombre,
            'estado' => 'suspendida',
            'detalle' => $motivo,
        ];
    }

    /**
     * @param  array<string, mixed>  $detalle
     * @return array<string, mixed>
     */
    private function anotar(
        ProgramacionReporte $programacion,
        string $estado,
        Carbon $momento,
        bool $seco,
        array $detalle = [],
    ): array {
        if (! $seco) {
            $programacion->forceFill([
                'ultima_corrida_en' => $momento,
                'ultimo_estado' => $estado,
                'ultimo_error' => $detalle['error'] ?? null,
            ])->save();
        }

        return [
            'programacion' => $programacion->nombre,
            'estado' => $estado,
            'detalle' => $detalle['error']
                ?? (($detalle['filas'] ?? 0).' filas a '.($detalle['a'] ?? 0).' destinatarios'
                    .($detalle['descartados'] ?? [] ? ', sin '.implode('; ', $detalle['descartados']) : '')),
        ];
    }
}
