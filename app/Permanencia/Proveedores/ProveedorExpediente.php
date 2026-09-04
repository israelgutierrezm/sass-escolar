<?php

declare(strict_types=1);

namespace App\Permanencia\Proveedores;

use App\Models\Admisiones\MatriculaOferta;
use App\Models\Identidad\DocumentoRequerido;
use App\Models\Permanencia\ReglaAlertaVersion;
use App\Permanencia\Medicion;
use InvalidArgumentException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Señales administrativas: el papeleo que traba la inscripción tres meses después.
 *
 * ── Lo PENDIENTE DE REVISAR no es un faltante ──────────────────────────────
 * Y es la decisión que hace útil este proveedor. Un documento subido y sin
 * revisar tiene la pelota del lado de la ESCUELA: contarlo como faltante
 * reportaría al alumno por algo que ya hizo, y llenaría la cola de casos que se
 * resuelven revisando un archivo. Faltan los que no están y los RECHAZADOS —que
 * sí son suyos, y por eso el rechazo lleva motivo obligatorio—.
 *
 * ── Los documentos cuelgan de la PERSONA, no de la matrícula ───────────────
 * `documentos_alumno.persona_id`. Quien estudia dos programas entrega un acta
 * de nacimiento, no dos, así que las dos matrículas de la misma persona miden
 * lo mismo. Es correcto y hay que saberlo: dos alertas del mismo papel son dos
 * trayectorias que lo necesitan, no un duplicado.
 */
class ProveedorExpediente implements ProveedorDeSenales
{
    public function clave(): string
    {
        return 'expediente';
    }

    public function titulo(): string
    {
        return 'Expediente documental';
    }

    public function calidad(): string
    {
        return 'Depende de que la escuela haya configurado qué documentos pide en el ámbito «alumno». '
            .'Sin nada configurado no se mide nada. Lo que está SUBIDO Y SIN REVISAR no cuenta como '
            .'faltante: esa pelota es de la escuela.';
    }

    public function modulo(): ?string
    {
        return null;
    }

    public function metricas(): array
    {
        return ['expediente.documentos_faltantes', 'expediente.dias_para_vencer'];
    }

    public function ultimaActualizacion(): ?string
    {
        return DB::table('documentos_alumno')->whereNull('deleted_at')->max('updated_at');
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

        $personaId = $matricula->persona_id;

        $obligatorios = DocumentoRequerido::query()
            ->where('obligatorio', true)
            ->whereIn('id', DB::table('documento_ambitos')
                ->whereNull('deleted_at')->where('ambito', 'alumno')->pluck('documento_id'))
            ->get(['id', 'nombre']);

        if ($obligatorios->isEmpty()) {
            return [Medicion::sinDatos([
                'matricula' => $matricula->matricula,
                'motivo' => 'la escuela no pide ningún documento obligatorio a sus alumnos',
                'fuente' => 'documentos_requeridos + documento_ambitos',
            ])];
        }

        $suyos = DB::table('documentos_alumno')
            ->whereNull('documentos_alumno.deleted_at')
            ->where('persona_id', $personaId)
            ->leftJoin('estados_documento', 'estados_documento.id', '=', 'documentos_alumno.estado_documento_id')
            ->get(['documentos_alumno.documento_id', 'documentos_alumno.vigencia', 'estados_documento.clave as estado']);

        return $metrica === 'expediente.dias_para_vencer'
            ? [$this->porVencer($matricula, $suyos)]
            : [$this->faltantes($matricula, $obligatorios, $suyos)];
    }

    private function faltantes(MatriculaOferta $matricula, $obligatorios, $suyos): Medicion
    {
        $porDocumento = $suyos->keyBy('documento_id');

        $faltan = $obligatorios->filter(function ($d) use ($porDocumento) {
            $entregado = $porDocumento->get($d->id);

            // No está, o está rechazado. Lo pendiente de revisar NO cuenta.
            return $entregado === null || $entregado->estado === 'rechazado';
        });

        return new Medicion(
            valor: (float) $faltan->count(),
            cobertura: $obligatorios->count(),
            evidencia: [
                'matricula' => $matricula->matricula,
                'obligatorios' => $obligatorios->count(),
                'faltantes' => $faltan->count(),
                'cuales' => $faltan->take(6)->pluck('nombre')->values()->all(),
                'nota' => 'Lo subido y pendiente de revisar no cuenta como faltante.',
                'fuente' => 'documentos_alumno',
            ],
        );
    }

    private function porVencer(MatriculaOferta $matricula, $suyos): Medicion
    {
        /*
         * Sólo los ACEPTADOS con vigencia. Uno rechazado que vence no dice nada
         * —ya hay que rehacerlo— y uno sin vigencia capturada no vence nunca.
         */
        $conVigencia = $suyos->filter(
            fn ($d) => $d->vigencia !== null && $d->estado === 'aceptado',
        );

        if ($conVigencia->isEmpty()) {
            return [Medicion::sinDatos([
                'matricula' => $matricula->matricula,
                'motivo' => 'no tiene ningún documento aceptado con vigencia capturada',
                'fuente' => 'documentos_alumno.vigencia',
            ])][0];
        }

        $proxima = $conVigencia->min('vigencia');
        $dias = (int) CarbonImmutable::now()->startOfDay()
            ->diffInDays(CarbonImmutable::parse($proxima)->startOfDay(), false);

        return new Medicion(
            valor: (float) $dias,
            cobertura: $conVigencia->count(),
            evidencia: [
                'matricula' => $matricula->matricula,
                'vence_el' => (string) $proxima,
                'dias' => $dias,
                'documentos_con_vigencia' => $conVigencia->count(),
                'nota' => 'Con signo: negativo significa que YA venció.',
                'fuente' => 'documentos_alumno.vigencia',
            ],
        );
    }
}
