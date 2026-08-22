<?php

declare(strict_types=1);

namespace App\Panel\Tarjetas;

use App\Models\Admisiones\MatriculaOferta;
use App\Models\Finanzas\DatosFacturacion;
use App\Models\Finanzas\Factura;
use App\Models\Finanzas\Pago;
use App\Models\Identidad\Usuario;
use App\Panel\TarjetaPanel;
use App\Services\EmisorFactura;
use Illuminate\Support\Facades\DB;

/**
 * Lo que le falta a quien factura: pagos cobrados sin CFDI y timbrados rotos.
 *
 * ── Sólo de quien PIDIÓ factura, y sin ese filtro no sirve ────────────────
 * «Pagos cobrados sin factura» son, sin más, casi todos los pagos de la escuela
 * para siempre: la mayoría de los alumnos nunca pide comprobante fiscal. La
 * cifra sería un número que nadie puede bajar a cero, o sea mobiliario.
 * Acotándola a quien marcó que quiere factura, se vuelve una cola cerrable.
 *
 * ── Qué cuenta como «ya facturado» lo dice el EMISOR ──────────────────────
 * `EmisorFactura::pagosOcupados()` — una cancelada libera sus pagos y una en
 * error también, porque esa factura no ampara nada. Escribirlo aquí otra vez
 * sería la manera de que la tarjeta y la pantalla de emisión discrepen sobre el
 * mismo pago.
 *
 * ── Un pago de una factura en error sale DOS veces, y está bien ───────────
 * Aparece en el renglón de rechazadas y en el de su matrícula. No es un
 * defecto: esa factura no ampara nada, así que el pago vuelve a estar
 * disponible y es exactamente lo que ofrece la pantalla de emitir. Esconderlo
 * haría que la tarjeta y esa pantalla dieran números distintos.
 */
class FacturacionPendiente implements TarjetaPanel
{
    private const A_LA_VISTA = 5;

    public function clave(): string
    {
        return 'facturacion-pendiente';
    }

    public function titulo(): string
    {
        return 'Pendiente de facturar';
    }

    public function permiso(): ?string
    {
        return 'facturar';
    }

    public function tipo(): string
    {
        return 'lista';
    }

    public function ancho(): int
    {
        return 2;
    }

    public function icono(): string
    {
        return 'M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z';
    }

    public function datos(Usuario $usuario): ?array
    {
        $campus = $usuario->campusVisibles();

        $porMatricula = Pago::query()
            ->selectRaw('pagos.matricula_oferta_id, count(*) as total, sum(pagos.monto) as monto')
            ->cobrados()
            // Al aspirante no se le factura: no hay matrícula a la que emitir.
            ->whereNotNull('pagos.matricula_oferta_id')
            ->whereNotIn('pagos.id', EmisorFactura::pagosOcupados())
            ->whereExists(fn ($q) => $q->select(DB::raw(1))
                ->from((new MatriculaOferta)->getTable().' as mo')
                ->join((new DatosFacturacion)->getTable().' as df', 'df.persona_id', '=', 'mo.persona_id')
                ->whereColumn('mo.id', 'pagos.matricula_oferta_id')
                // Consulta a pelo: aquí el borrado lógico se filtra a mano.
                ->whereNull('mo.deleted_at')
                ->whereNull('df.deleted_at')
                ->where('df.quiere_factura', true))
            /*
             * La pantalla de facturas SÍ acota por campus, así que la tarjeta
             * también: si contara de más, quien está acotado vería un número
             * que su propia lista no puede explicar.
             */
            ->when($campus !== null, fn ($q) => $q->whereExists(fn ($s) => $s->select(DB::raw(1))
                ->from('matricula_oferta as moc')
                ->join('oferta as o', 'o.id', '=', 'moc.oferta_id')
                ->whereColumn('moc.id', 'pagos.matricula_oferta_id')
                ->whereIn('o.campus_id', $campus)))
            ->groupBy('pagos.matricula_oferta_id')
            ->orderByDesc('total')
            ->limit(self::A_LA_VISTA)
            ->get();

        $conError = Factura::query()->where('estatus', Factura::ESTATUS_ERROR)->count();

        // Cola de trabajo: sin nada por emitir ni nada roto, no se dibuja.
        if ($porMatricula->isEmpty() && $conError === 0) {
            return null;
        }

        $renglones = [];

        /*
         * El timbrado rechazado va PRIMERO y con alerta: emitir es rutina y
         * rehacer no. Una factura que el PAC devolvió está deteniendo a alguien
         * que ya pagó y ya pidió su comprobante.
         */
        if ($conError > 0) {
            $renglones[] = [
                'etiqueta' => 'Timbrado rechazado',
                'detalle' => 'el PAC las devolvió',
                'valor' => $conError === 1 ? '1 factura' : "{$conError} facturas",
                'pie' => null,
                'progreso' => null,
                'alerta' => true,
                'enlace' => '/finanzas/facturas?estatus=error',
            ];
        }

        // Los nombres, en UNA consulta: no con un `with` sobre el agrupado.
        $matriculas = MatriculaOferta::query()
            ->whereIn('id', $porMatricula->pluck('matricula_oferta_id'))
            ->with('persona:id,nombre,primer_apellido,segundo_apellido')
            ->get(['id', 'matricula', 'persona_id'])
            ->keyBy('id');

        foreach ($porMatricula as $fila) {
            $matricula = $matriculas->get($fila->matricula_oferta_id);

            $renglones[] = [
                'etiqueta' => $matricula?->persona?->nombreCompleto() ?? 'Alumno',
                'detalle' => $matricula?->matricula,
                'valor' => '$'.number_format((float) $fila->monto, 2),
                'pie' => (int) $fila->total === 1 ? '1 pago sin facturar' : "{$fila->total} pagos sin facturar",
                'progreso' => null,
                'alerta' => false,
                'enlace' => "/finanzas/facturas/emitir/{$fila->matricula_oferta_id}",
            ];
        }

        return [
            'renglones' => $renglones,
            'pie' => 'de quienes pidieron comprobante fiscal',
            'enlace' => '/finanzas/facturas',
        ];
    }
}
