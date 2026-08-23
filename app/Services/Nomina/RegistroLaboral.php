<?php

declare(strict_types=1);

namespace App\Services\Nomina;

use App\Models\Nomina\Adscripcion;
use App\Models\Nomina\ExpedienteLaboral;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Las reglas del expediente laboral: contratar, adscribir y dar de baja.
 *
 * Vive aparte del controlador porque la baja y el cambio de adscripción son dos
 * escrituras cada una —y una sola de las dos deja el expediente mintiendo—.
 */
class RegistroLaboral
{
    /**
     * Cierra el vínculo laboral.
     *
     * ── La fecha y el motivo son obligatorios ─────────────────────────────
     * Una baja sin fecha no se puede usar para nada después —ni para el
     * finiquito, ni para saber a quién pagarle este periodo— y una sin motivo
     * no sirve para el reporte de rotación, que es lo que una dirección
     * pregunta. Lo comprueba el servicio y no sólo el formulario: por aquí
     * pasan la pantalla y lo que venga después.
     *
     * @throws RuntimeException si ya estaba dado de baja
     */
    public function darDeBaja(ExpedienteLaboral $expediente, string $fecha, int $motivoId): ExpedienteLaboral
    {
        if (! $expediente->sigueContratado()) {
            throw new RuntimeException('Ese expediente ya estaba dado de baja.');
        }

        if ($expediente->fecha_ingreso !== null && $fecha < $expediente->fecha_ingreso->toDateString()) {
            throw new RuntimeException('La baja no puede ser anterior al ingreso.');
        }

        return DB::transaction(function () use ($expediente, $fecha, $motivoId) {
            $expediente->update(['fecha_baja' => $fecha, 'motivo_baja_id' => $motivoId]);

            /*
             * Y se cierran sus adscripciones abiertas.
             *
             * Sin esto, quien renunció seguiría figurando como coordinador del
             * campus norte en el organigrama, que es exactamente la pregunta
             * que esa tabla existe para contestar.
             */
            $expediente->adscripciones()
                ->whereNull('vigente_hasta')
                ->update(['vigente_hasta' => $fecha, 'updated_at' => now()]);

            /*
             * Y su esquema de sueldo, por lo mismo.
             *
             * Un esquema abierto sobre alguien que ya no trabaja aquí contesta
             * «gana tanto» a una pregunta sobre una fecha en la que ya no
             * ganaba nada. Hoy la nómina no le pagaría igual —`enNomina()` saca
             * a los dados de baja— pero eso es una segunda defensa: el dato
             * tiene que ser cierto por sí solo, porque el día que algo consulte
             * el esquema sin pasar por ese filtro nadie se acordará de esto.
             *
             * Salió de una mutación que sobrevivió: sin ningún tramo cerrado sin
             * sucesor, la consulta por fecha nunca ejercitaba su fecha de fin.
             */
            $expediente->esquemas()
                ->whereNull('vigente_hasta')
                ->update(['vigente_hasta' => $fecha, 'updated_at' => now()]);

            return $expediente->refresh();
        });
    }

    /** Deshace una baja capturada por error. */
    public function reactivar(ExpedienteLaboral $expediente): ExpedienteLaboral
    {
        if ($expediente->sigueContratado()) {
            throw new RuntimeException('Ese expediente no está dado de baja.');
        }

        /*
         * Ni las adscripciones ni el esquema de sueldo se reabren.
         *
         * Al dar de baja se cerraron con la fecha de baja, y no hay forma de
         * saber cuáles estaban abiertas antes, si el puesto sigue libre ni si el
         * sueldo del regreso es el mismo de la salida. Reabrirlos devolvería
         * puestos que quizá ya ocupa alguien más y un sueldo que nadie volvió a
         * autorizar; se deja que RH abra los que correspondan, que son gestos
         * que ya existen.
         */
        $expediente->update(['fecha_baja' => null, 'motivo_baja_id' => null]);

        return $expediente->refresh();
    }

    /**
     * Abre una adscripción.
     *
     * Si se marca principal, degrada a la anterior en la misma transacción: con
     * dos principales, cualquier reporte por puesto enseña la que salga primero.
     */
    public function adscribir(
        ExpedienteLaboral $expediente,
        int $puestoId,
        int $campusId,
        string $desde,
        ?string $hasta,
        bool $principal,
    ): Adscripcion {
        if (! $expediente->sigueContratado()) {
            throw new RuntimeException('No se puede adscribir a alguien que ya está dado de baja.');
        }

        if ($hasta !== null && $hasta < $desde) {
            throw new RuntimeException('La adscripción no puede terminar antes de empezar.');
        }

        return DB::transaction(function () use ($expediente, $puestoId, $campusId, $desde, $hasta, $principal) {
            if ($principal) {
                $expediente->adscripciones()->where('es_principal', true)
                    ->update(['es_principal' => false, 'updated_at' => now()]);
            }

            return $expediente->adscripciones()->create([
                'puesto_id' => $puestoId,
                'campus_id' => $campusId,
                'vigente_desde' => $desde,
                'vigente_hasta' => $hasta,
                'es_principal' => $principal,
            ]);
        });
    }

    /**
     * Cierra una adscripción en una fecha.
     *
     * Se CIERRA y no se borra: perder desde cuándo ocupó cada puesto es perder
     * la mitad de para qué existe esa tabla.
     */
    public function cerrarAdscripcion(Adscripcion $adscripcion, string $hasta): Adscripcion
    {
        if ($hasta < $adscripcion->vigente_desde->toDateString()) {
            throw new RuntimeException('No se puede cerrar antes de la fecha en que empezó.');
        }

        $adscripcion->update(['vigente_hasta' => $hasta]);

        return $adscripcion->refresh();
    }
}
