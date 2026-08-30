<?php

declare(strict_types=1);

namespace Database\Seeders\Tenant;

use App\Models\Reportes\AreaReporte;
use Illuminate\Database\Seeder;

/**
 * Las áreas con las que nace una escuela.
 *
 * ── Son BORRABLES, y por eso hay once ────────────────────────────────────
 * Ninguna es «protegida»: la escuela las renombra, las reordena, las apaga y las
 * borra si no las usa. Se siembran las once que cubren el sistema para que nadie
 * tenga que inventarlas desde cero, no para imponerlas — es la misma decisión
 * que con los roles de ejemplo.
 *
 * El orden no es alfabético: arriba lo que se consulta a diario y abajo lo que
 * se saca una vez al año.
 */
class AreasReporteSeeder extends Seeder
{
    public function run(): void
    {
        $areas = [
            ['control-escolar', 'Control escolar', 'Matrículas, grupos, calificaciones y asistencia.'],
            ['admisiones', 'Admisiones y captación', 'Aspirantes, embudo y comisiones.'],
            ['finanzas', 'Finanzas', 'Cartera, pagos, becas y facturación.'],
            ['docentes', 'Docentes', 'Plantilla, carga académica y expedientes.'],
            ['lms', 'Aula virtual', 'Actividades, entregas, exámenes y participación.'],
            ['familia', 'Familia y tutores', 'Vínculos, autorizaciones y comunicación.'],
            ['certificacion', 'Certificación y titulación', 'Lotes, títulos y certificados ante la SEP.'],
            ['rh', 'Recursos humanos', 'Expedientes laborales, nómina y asistencia del personal.'],
            ['bolsa', 'Bolsa de trabajo', 'Vacantes, postulaciones y empleabilidad.'],
            ['movilidad', 'Movilidad', 'Convenios, convocatorias y estancias.'],
            ['general', 'General', 'Lo que no encaja en las demás.'],
        ];

        foreach ($areas as $i => [$clave, $nombre, $descripcion]) {
            /*
             * Idempotente por CLAVE, y sin pisar el nombre.
             *
             * `firstOrCreate` y no `updateOrCreate`: una escuela que ya renombró
             * «Control escolar» a «Servicios escolares» no puede perder ese
             * cambio la próxima vez que alguien corra los seeders. El renombre
             * es justamente lo que esta tabla existe para permitir.
             */
            AreaReporte::query()->firstOrCreate(
                ['clave' => $clave],
                ['nombre' => $nombre, 'descripcion' => $descripcion, 'orden' => $i + 1, 'activo' => true],
            );
        }
    }
}
