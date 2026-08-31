<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Admisiones\MatriculaOferta;
use App\Models\ControlEscolar\MovimientoEscolar;
use App\Models\ControlEscolar\TipoMovimientoEscolar;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * El ÚNICO sitio donde nace un movimiento escolar.
 *
 * ── Por qué un solo sitio ──────────────────────────────────────────────────
 * Si cada controlador escribiera el suyo, cada uno guardaría un juego distinto
 * de campos: uno pondría la situación anterior y otro no, uno el origen y otro
 * lo dejaría en blanco. La trayectoria acabaría contando la mitad de los casos
 * —que es exactamente lo que le pasó a la bitácora de postulaciones antes de
 * centralizarla en `Postulador`—.
 *
 * ── El duplicado lo impide la BASE, no un SELECT ───────────────────────────
 * `referencia` identifica el hecho que originó el movimiento
 * («conversion:412»). Va con índice único, así que un proceso repetido choca
 * contra la base. Un `SELECT` previo no bastaría: dos peticiones simultáneas lo
 * pasan las dos y se insertan las dos.
 *
 * Cuando la referencia ya existe se devuelve el movimiento que YA estaba, no se
 * lanza: reintentar una conversión no es un error del usuario.
 */
class RegistradorMovimientos
{
    /**
     * Registra un movimiento.
     *
     * @param  array{
     *     fecha_efectiva?: string|null, ciclo_id?: int|null,
     *     situacion_anterior_id?: int|null, situacion_nueva_id?: int|null,
     *     oferta_anterior_id?: int|null, oferta_nueva_id?: int|null,
     *     grupo_anterior_id?: int|null, grupo_nuevo_id?: int|null,
     *     periodo_anterior?: int|null, periodo_nuevo?: int|null,
     *     motivo?: string|null, observaciones?: string|null,
     *     corrige_movimiento_id?: int|null
     * }  $datos
     */
    public function registrar(
        MatriculaOferta $matricula,
        string $claveTipo,
        string $origen = MovimientoEscolar::ORIGEN_MANUAL,
        ?string $referencia = null,
        array $datos = [],
    ): ?MovimientoEscolar {
        $tipo = TipoMovimientoEscolar::query()->where('clave', $claveTipo)->first();

        /*
         * Un tipo que la escuela apagó o borró no detiene la operación que lo
         * disparó: dar de baja a alguien tiene que funcionar aunque su tipo de
         * movimiento no esté. Se deja de anotar, no se rompe el proceso.
         */
        if ($tipo === null) {
            return null;
        }

        $atributos = array_merge([
            'matricula_oferta_id' => $matricula->id,
            'tipo_id' => $tipo->id,
            'fecha_efectiva' => now()->toDateString(),
            'origen' => $origen,
            'referencia' => $referencia,
        ], array_intersect_key($datos, array_flip([
            'fecha_efectiva', 'ciclo_id',
            'situacion_anterior_id', 'situacion_nueva_id',
            'oferta_anterior_id', 'oferta_nueva_id',
            'grupo_anterior_id', 'grupo_nuevo_id',
            'periodo_anterior', 'periodo_nuevo',
            'motivo', 'observaciones', 'corrige_movimiento_id',
        ])));

        try {
            return MovimientoEscolar::create($atributos);
        } catch (UniqueConstraintViolationException $e) {
            /*
             * Se atrapa la excepción de ÚNICO y no `QueryException` a secas.
             * La segunda taparía también un error de columna o de foránea, y
             * entonces la trayectoria se quedaría incompleta sin que nadie se
             * entere — que es justo el defecto que este módulo viene a evitar.
             * Es la trampa del `catch` pelado que este proyecto ya se cobró tres
             * veces.
             */
            if ($referencia === null) {
                throw $e;
            }

            return MovimientoEscolar::query()
                ->where('matricula_oferta_id', $matricula->id)
                ->where('referencia', $referencia)
                ->first();
        }
    }

    /**
     * El alta de una matrícula recién creada.
     *
     * Lleva la situación NUEVA y no la anterior: antes de este momento la
     * matrícula no existía, así que no venía de ninguna parte.
     */
    public function alta(MatriculaOferta $matricula, string $origen, ?string $referencia = null, ?string $observaciones = null): ?MovimientoEscolar
    {
        return $this->registrar($matricula, TipoMovimientoEscolar::ALTA, $origen, $referencia, [
            'fecha_efectiva' => $matricula->fecha_ingreso?->toDateString() ?? now()->toDateString(),
            'situacion_nueva_id' => $matricula->situacion_id,
            'oferta_nueva_id' => $matricula->oferta_id,
            'periodo_nuevo' => $matricula->periodo_actual,
            'observaciones' => $observaciones,
        ]);
    }

    /**
     * Una baja, con el par «de → a» de su situación.
     *
     * El tipo se elige por la CLAVE de la situación de destino y no por un
     * parámetro: así, si la escuela agrega «baja por reglamento», el movimiento
     * sigue quedando bien clasificado mientras su clave empiece por `baja`.
     */
    public function baja(MatriculaOferta $matricula, ?int $situacionAnterior, ?int $situacionNueva, string $claveSituacion, ?string $referencia = null): ?MovimientoEscolar
    {
        $tipo = $claveSituacion === 'baja_temporal'
            ? TipoMovimientoEscolar::BAJA_TEMPORAL
            : TipoMovimientoEscolar::BAJA_DEFINITIVA;

        return $this->registrar($matricula, $tipo, MovimientoEscolar::ORIGEN_BAJA, $referencia, [
            'situacion_anterior_id' => $situacionAnterior,
            'situacion_nueva_id' => $situacionNueva,
        ]);
    }

    public function reingreso(MatriculaOferta $matricula, ?int $situacionAnterior, ?int $situacionNueva, ?string $referencia = null): ?MovimientoEscolar
    {
        return $this->registrar($matricula, TipoMovimientoEscolar::REINGRESO, MovimientoEscolar::ORIGEN_REINGRESO, $referencia, [
            'situacion_anterior_id' => $situacionAnterior,
            'situacion_nueva_id' => $situacionNueva,
        ]);
    }
}
