<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Repara actividades amarradas a un componente de evaluación que ya no existe.
 *
 * ── Cómo salió a la luz ────────────────────────────────────────────────────
 * Al agregarle una columna a `actividades`, MySQL reconstruye la tabla y de paso
 * REVALIDA sus foráneas. Ahí saltó:
 *
 *     Cannot add or update a child row: a foreign key constraint fails
 *     (actividades_esquema_evaluacion_id_foreign)
 *
 * O sea que la foránea existía y los datos ya la violaban. Es el mismo resto que
 * dejaron otras resiembras hechas con las comprobaciones apagadas —el que tiene
 * a los dos planes de cobro del demo apuntando a un ciclo que no está—: MySQL
 * sólo comprueba al escribir, así que una fila envenenada puede vivir meses sin
 * dar señales y sólo estorbar el día que alguien toque el esquema.
 *
 * ── Por qué NULL y no cualquier otra cosa ──────────────────────────────────
 * Es exactamente lo que la propia foránea habría hecho: está declarada
 * `ON DELETE SET NULL`. Reponer un componente a mano sería inventarle una
 * ponderación a una actividad, y elegir otro sería peor: cambiaría de sitio la
 * calificación de quien la haya entregado.
 *
 * Sin amarre la actividad queda FORMATIVA —se entrega y se retroalimenta, pero
 * no promedia—, que es lo que de hecho lleva siendo desde que el componente
 * desapareció: `CalculadorComponente` no podía escribir contra un id que no
 * está, así que ese porcentaje no llegaba a ninguna calificación.
 *
 * ── Migración aparte, y antes ──────────────────────────────────────────────
 * No va dentro de la de rúbricas aunque sea la que la destapó: esto repara un
 * dato roto de antes y tiene que poder leerse —y revertirse— sin nada que ver
 * con rúbricas. Ir escondido dentro de otra migración es como se llega a que
 * nadie sepa de dónde salió un cambio.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sueltas = DB::table('actividades')
            ->whereNotNull('esquema_evaluacion_id')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('esquema_evaluacion')
                ->whereColumn('esquema_evaluacion.id', 'actividades.esquema_evaluacion_id'))
            ->update(['esquema_evaluacion_id' => null, 'updated_at' => now()]);

        if ($sueltas > 0) {
            echo "  Actividades desamarradas de un componente inexistente: {$sueltas}".PHP_EOL;
        }
    }

    public function down(): void
    {
        // No hay vuelta: el id al que apuntaban se perdió con el componente.
    }
};
