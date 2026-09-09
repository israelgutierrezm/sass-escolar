<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\AvisoParaElUsuario;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Finanzas\Factura;
use App\Models\Finanzas\SolicitudFactura;
use App\Models\Identidad\Usuario;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Las tres cosas que le pasan a una solicitud de factura: nacer, emitirse y
 * rechazarse. La regla de «qué se puede facturar» sale de `EmisorFactura`, no de
 * aquí: una sola verdad para el portal, la bandeja y el motor.
 */
class GestorSolicitudFactura
{
    public function __construct(private readonly EmisorFactura $emisor) {}

    /**
     * Nace la solicitud desde el portal del alumno o su familia.
     *
     * El receptor viene YA VALIDADO contra el catálogo del SAT (lo hace el
     * FormRequest del controlador) y se CONGELA aquí: es lo que el alumno pidió.
     *
     * @param  array<int, int>  $pagoIds
     * @param  array<string, string|null>  $receptor  rfc, razon_social, uso_cfdi, regimen_fiscal, cp, correo
     *
     * @throws AvisoParaElUsuario
     */
    public function solicitar(MatriculaOferta $matricula, array $pagoIds, array $receptor, ?Usuario $por): SolicitudFactura
    {
        return SolicitudFactura::create([
            'matricula_oferta_id' => $matricula->id,
            'solicitada_por' => $por?->id,
            'pago_ids' => $this->elegirFacturables($matricula, $pagoIds),
            ...$this->columnasReceptor($receptor),
        ]);
    }

    /**
     * El alumno o su familia GENERA su factura al momento: nace el CFDI sin pasar
     * por la bandeja. Es la capacidad que la escuela abre por separado —emitir a
     * su nombre es delicado—, y usa el MISMO motor y las MISMAS guardas que
     * solicitar. Deja constancia como una solicitud ya EMITIDA, para que el
     * historial y la descarga del portal sirvan igual que cuando la emite la
     * escuela.
     *
     * @param  array<int, int>  $pagoIds
     * @param  array<string, string|null>  $receptor
     *
     * @throws AvisoParaElUsuario|\RuntimeException
     */
    public function generarDirecto(MatriculaOferta $matricula, array $pagoIds, array $receptor, ?Usuario $por): Factura
    {
        return DB::transaction(function () use ($matricula, $pagoIds, $receptor, $por) {
            $elegidos = $this->elegirFacturables($matricula, $pagoIds);

            $factura = $this->emisor->emitir($matricula->id, $elegidos, $receptor);

            SolicitudFactura::create([
                'matricula_oferta_id' => $matricula->id,
                'solicitada_por' => $por?->id,
                'pago_ids' => $elegidos,
                ...$this->columnasReceptor($receptor),
                'estado' => SolicitudFactura::EMITIDA,
                'factura_id' => $factura->id,
                'revisado_en' => now(),
            ]);

            return $factura;
        });
    }

    /**
     * Los pagos suyos que de verdad se pueden facturar. La lista la da el emisor
     * —cobrados y no amparados por una factura viva—, así que el portal no puede
     * facturar algo ajeno ni ya facturado; y no puede haber otra solicitud
     * pendiente cubriéndolos (el duplicado protege la cobertura de la operación).
     *
     * @param  array<int, int>  $pagoIds
     * @return array<int, int>
     */
    private function elegirFacturables(MatriculaOferta $matricula, array $pagoIds): array
    {
        AvisoParaElUsuario::si($pagoIds === [], 422, 'Elige al menos una operación para facturar.');

        $facturables = $this->emisor->facturables($matricula->id)->pluck('id')->all();
        $elegidos = array_values(array_intersect(array_map('intval', $pagoIds), $facturables));

        AvisoParaElUsuario::si(
            $elegidos === [],
            422,
            'Esas operaciones ya no se pueden facturar: quizá ya tienen su factura o el pago no está confirmado.',
        );

        $repetidos = array_intersect($elegidos, $this->pagosEnSolicitudPendiente($matricula->id));

        AvisoParaElUsuario::si(
            $repetidos !== [],
            422,
            'Ya pediste factura de alguna de esas operaciones y sigue pendiente de emitir.',
        );

        return $elegidos;
    }

    /**
     * El receptor congelado, en columnas. El correo es de ENTREGA, aparte.
     *
     * @param  array<string, string|null>  $receptor
     * @return array<string, string|null>
     */
    private function columnasReceptor(array $receptor): array
    {
        return [
            'receptor_rfc' => strtoupper(trim((string) $receptor['rfc'])),
            'receptor_razon_social' => trim((string) $receptor['razon_social']),
            'receptor_uso_cfdi' => $receptor['uso_cfdi'],
            'receptor_regimen_fiscal' => $receptor['regimen_fiscal'],
            'receptor_cp' => $receptor['cp'],
            'receptor_correo' => $receptor['correo'] ?? null,
        ];
    }

    /**
     * La escuela la atiende: nace el CFDI, reusando el motor de siempre.
     *
     * Bajo bloqueo de la fila —dos personas en la bandeja no emiten dos facturas
     * por la misma solicitud— y volviendo a comprobar qué sigue facturable: entre
     * pedir y emitir pudieron condonar un cargo o facturarlo por otra vía.
     *
     * @throws AvisoParaElUsuario|\RuntimeException
     */
    public function emitir(SolicitudFactura $solicitud): Factura
    {
        return DB::transaction(function () use ($solicitud) {
            $solicitud = SolicitudFactura::query()->whereKey($solicitud->id)->lockForUpdate()->firstOrFail();

            AvisoParaElUsuario::si($solicitud->estaResuelta(), 422, 'Esa solicitud ya se atendió.');

            $facturables = $this->emisor->facturables($solicitud->matricula_oferta_id)->pluck('id')->all();
            $pagos = array_values(array_intersect(array_map('intval', $solicitud->pago_ids ?? []), $facturables));

            AvisoParaElUsuario::si(
                $pagos === [],
                422,
                'Ninguna de las operaciones sigue facturable: ya tienen factura o se movieron. Recházala con ese motivo.',
            );

            // Puede lanzar RuntimeException (sin emisor asignado, etc.): la
            // bandeja lo atrapa y lo enseña.
            $factura = $this->emisor->emitir($solicitud->matricula_oferta_id, $pagos, $solicitud->receptor());

            $solicitud->update([
                'estado' => SolicitudFactura::EMITIDA,
                'factura_id' => $factura->id,
                'revisado_por' => Auth::id(),
                'revisado_en' => now(),
            ]);

            return $factura;
        });
    }

    /** Se rechaza con motivo: quien pidió necesita saber qué corregir. */
    public function rechazar(SolicitudFactura $solicitud, string $motivo): void
    {
        DB::transaction(function () use ($solicitud, $motivo) {
            $solicitud = SolicitudFactura::query()->whereKey($solicitud->id)->lockForUpdate()->firstOrFail();

            AvisoParaElUsuario::si($solicitud->estaResuelta(), 422, 'Esa solicitud ya se atendió.');

            $solicitud->update([
                'estado' => SolicitudFactura::RECHAZADA,
                'motivo_rechazo' => $motivo,
                'revisado_por' => Auth::id(),
                'revisado_en' => now(),
            ]);
        });
    }

    /**
     * Los pagos que ya están en una solicitud PENDIENTE de esta matrícula.
     *
     * @return array<int, int>
     */
    private function pagosEnSolicitudPendiente(int $matriculaId): array
    {
        return SolicitudFactura::query()
            ->where('matricula_oferta_id', $matriculaId)
            ->pendientes()
            ->get()
            ->flatMap(fn (SolicitudFactura $s) => $s->pago_ids ?? [])
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
