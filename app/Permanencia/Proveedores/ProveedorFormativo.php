<?php

declare(strict_types=1);

namespace App\Permanencia\Proveedores;

use App\Models\Admisiones\MatriculaOferta;
use App\Models\Permanencia\ReglaAlertaVersion;
use App\Models\ProcesosFormativos\EstadoExpediente;
use App\Models\ProcesosFormativos\ExpedienteProceso;
use App\Permanencia\Medicion;
use InvalidArgumentException;
use Carbon\CarbonImmutable;

/**
 * Señales del servicio social y las prácticas.
 *
 * ── Este proveedor NO duplica las alertas de aquel módulo ──────────────────
 * `procesos:avisar` ya le avisa AL ALUMNO de que se le pasó una fecha, y esto no
 * lo sustituye: son dos cosas distintas y las dos hacen falta. Aquél es un
 * recordatorio dirigido a quien tiene que actuar; esto mete el retraso como uno
 * de los FRENTES de un caso de permanencia, para que quien acompaña al alumno
 * vea que además del promedio bajo tiene el servicio social parado.
 *
 * Y por eso mide lo mismo desde otro sitio en vez de leer `alertas_proceso`:
 * las alertas de aquel módulo caducan a los treinta días y se borran, y colgar
 * una señal de permanencia de algo que se autoborra la haría desaparecer sin
 * que nada cambiara en el expediente.
 */
class ProveedorFormativo implements ProveedorDeSenales
{
    public function clave(): string
    {
        return 'formativo';
    }

    public function titulo(): string
    {
        return 'Servicio social y prácticas';
    }

    public function calidad(): string
    {
        return 'Sólo mide a quien ya tiene expediente abierto con periodo asignado. Quien todavía no '
            .'empieza no sale aquí —para eso está la elegibilidad de aquel módulo—, y no se duplica el '
            .'aviso que `procesos:avisar` ya le manda al alumno.';
    }

    public function modulo(): ?string
    {
        return 'procesos_formativos';
    }

    public function metricas(): array
    {
        return ['formativo.dias_de_retraso'];
    }

    public function ultimaActualizacion(): ?string
    {
        return ExpedienteProceso::query()->max('updated_at');
    }

    public function medir(MatriculaOferta $matricula, string $metrica, ReglaAlertaVersion $version): array
    {
        if (! in_array($metrica, $this->metricas(), true)) {
            // Revienta: una métrica ajena es un error de configuración, no un
            // estado del alumno. El motor la aísla y la reporta con su nombre.
            throw new InvalidArgumentException(
                "El proveedor «{$this->clave()}» no sabe calcular «{$metrica}». "
                .'Revisa la métrica de esta regla: apunta a otro proveedor.',
            );
        }

        $expedientes = ExpedienteProceso::query()
            ->where('matricula_oferta_id', $matricula->id)
            ->whereIn('estado', [
                EstadoExpediente::EnCurso->value,
                EstadoExpediente::Suspendido->value,
            ])
            ->whereNotNull('fecha_fin_programada')
            ->with('tipoProceso:id,nombre')
            ->get();

        if ($expedientes->isEmpty()) {
            return [Medicion::sinDatos([
                'matricula' => $matricula->matricula,
                'motivo' => 'no tiene ningún proceso en curso con periodo asignado',
                'fuente' => 'expedientes_proceso',
            ])];
        }

        $hoy = CarbonImmutable::now()->startOfDay();

        return $expedientes->map(function (ExpedienteProceso $e) use ($hoy, $matricula) {
            $fin = CarbonImmutable::parse($e->fecha_fin_programada)->startOfDay();

            /*
             * CON SIGNO: negativo mientras el periodo no ha terminado. Sin
             * signo, un proceso que termina dentro de tres meses saldría con
             * «90 días de retraso» — es la lección que `diffInDays` ya dejó en
             * las alertas formativas.
             */
            $dias = (int) $hoy->diffInDays($fin, false) * -1;

            return new Medicion(
                valor: (float) $dias,
                cobertura: 1,
                evidencia: [
                    'matricula' => $matricula->matricula,
                    'expediente' => $e->id,
                    'proceso' => $e->tipoProceso?->nombre,
                    'debia_terminar' => $fin->toDateString(),
                    'dias_de_retraso' => $dias,
                    'horas_aprobadas' => $e->horas_aprobadas,
                    'horas_requeridas' => $e->horas_requeridas,
                    'nota' => 'Con signo: negativo significa que todavía no termina el periodo.',
                    'fuente' => 'expedientes_proceso',
                ],
            );
        })->all();
    }
}
