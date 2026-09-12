<?php

declare(strict_types=1);

namespace App\Services\Pagos;

use App\Models\Admisiones\MatriculaOferta;
use App\Models\Finanzas\Adeudo;
use App\Models\Finanzas\ComprobantePago;
use Illuminate\Http\UploadedFile;

/**
 * Registrar el comprobante de una transferencia: guardar el archivo y crear la
 * fila PENDIENTE. En UN sitio, porque lo hacen la web y la app, y el filtro de
 * cargos al titular es una salvaguarda —los ids vienen del cliente— que escrita
 * dos veces se descompone en una.
 *
 * NO confirma nada: un comprobante es una imagen esperando validación. Quien
 * cobra lo aprueba con `RevisorDeComprobantes`, y ahí nace el pago.
 */
class RegistroDeComprobante
{
    /**
     * @param  array{cuenta_bancaria_id?: int|null, monto: mixed, fecha_transferencia: mixed, referencia?: string|null, adeudo_ids?: array<int, int>|null}  $datos
     */
    public function registrar(MatriculaOferta $matricula, UploadedFile $archivo, array $datos): ComprobantePago
    {
        // Disco privado: un comprobante trae nombre, banco y a veces número de
        // cuenta de una persona.
        $ruta = $archivo->store("comprobantes/{$matricula->id}", 'local');

        return ComprobantePago::create([
            'matricula_oferta_id' => $matricula->id,
            'cuenta_bancaria_id' => $datos['cuenta_bancaria_id'] ?? null,
            'monto' => $datos['monto'],
            'fecha_transferencia' => $datos['fecha_transferencia'],
            'referencia' => $datos['referencia'] ?? null,
            'archivo' => $ruta,
            'adeudo_ids' => $this->adeudosDe($matricula, $datos['adeudo_ids'] ?? []),
        ]);
    }

    /**
     * Los cargos abiertos de ESTA matrícula entre los elegidos. Los ids vienen
     * del cliente: sin filtrar, se podría declarar que se paga la deuda de otro.
     *
     * @param  array<int, int>  $elegidos
     * @return array<int, int>
     */
    private function adeudosDe(MatriculaOferta $matricula, array $elegidos): array
    {
        if ($elegidos === []) {
            return [];
        }

        return Adeudo::query()
            ->deMatricula($matricula->id)
            ->porCobrar()
            ->whereIn('id', $elegidos)
            ->pluck('id')
            ->all();
    }
}
