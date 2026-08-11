<?php

declare(strict_types=1);

namespace Database\Seeders\Tenant;

use App\Models\ControlEscolar\EstatusHistorial;
use App\Models\ControlEscolar\ObservacionAsignatura;
use App\Models\ControlEscolar\ObservacionHistorial;
use App\Models\ControlEscolar\SituacionAsignaturaGrupo;
use App\Models\ControlEscolar\SituacionCiclo;
use App\Models\ControlEscolar\SituacionDocente;
use App\Models\ControlEscolar\SituacionGrupo;
use App\Models\ControlEscolar\SituacionInscripcion;
use App\Models\ControlEscolar\SituacionReprobatoria;
use App\Models\ControlEscolar\TipoDocente;
use App\Models\ControlEscolar\TipoEvaluacion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;

/**
 * Catálogos TENANT-CONFIG del módulo de control escolar. Idempotente por clave.
 *
 * `aulas` NO se siembra: son los espacios físicos reales de cada escuela.
 */
class CatalogosControlEscolarSeeder extends Seeder
{
    public function run(): void
    {
        $this->sembrar(SituacionCiclo::class, [
            ['clave' => 'planeado', 'nombre' => 'Planeado'],
            ['clave' => 'abierto', 'nombre' => 'Abierto'],
            ['clave' => 'en_curso', 'nombre' => 'En curso'],
            ['clave' => 'cerrado', 'nombre' => 'Cerrado'],
        ]);

        $this->sembrar(SituacionGrupo::class, [
            ['clave' => 'abierto', 'nombre' => 'Abierto'],
            ['clave' => 'cerrado', 'nombre' => 'Cerrado'],
            ['clave' => 'cancelado', 'nombre' => 'Cancelado'],
        ]);

        $this->sembrar(SituacionAsignaturaGrupo::class, [
            ['clave' => 'activa', 'nombre' => 'Activa'],
            ['clave' => 'cerrada', 'nombre' => 'Cerrada'],
        ]);

        $this->sembrar(SituacionInscripcion::class, [
            ['clave' => 'inscrito', 'nombre' => 'Inscrito'],
            ['clave' => 'cursando', 'nombre' => 'Cursando'],
            ['clave' => 'baja', 'nombre' => 'Baja'],
        ]);

        $this->sembrar(SituacionDocente::class, [
            ['clave' => 'activo', 'nombre' => 'Activo'],
            ['clave' => 'baja', 'nombre' => 'Baja'],
            ['clave' => 'licencia', 'nombre' => 'Licencia'],
        ]);

        $this->sembrar(TipoDocente::class, [
            ['clave' => 'titular', 'nombre' => 'Titular'],
            ['clave' => 'asignatura', 'nombre' => 'De asignatura'],
            ['clave' => 'invitado', 'nombre' => 'Invitado'],
        ]);

        $this->sembrar(TipoEvaluacion::class, [
            ['clave' => 'ordinaria', 'nombre' => 'Ordinaria'],
            ['clave' => 'extraordinaria', 'nombre' => 'Extraordinaria'],
            ['clave' => 'revalidacion', 'nombre' => 'Revalidación'],
            ['clave' => 'recursamiento', 'nombre' => 'Recursamiento'],
            ['clave' => 'a_titulo', 'nombre' => 'A título de suficiencia'],
            ['clave' => 'regularizacion', 'nombre' => 'Regularización'],
        ]);

        $this->sembrar(EstatusHistorial::class, [
            ['clave' => 'aprobada', 'nombre' => 'Aprobada'],
            ['clave' => 'reprobada', 'nombre' => 'Reprobada'],
            ['clave' => 'en_curso', 'nombre' => 'En curso'],
            ['clave' => 'no_presento', 'nombre' => 'No presentó'],
        ]);

        $this->sembrar(SituacionReprobatoria::class, [
            ['clave' => 'np', 'nombre' => 'No presentó'],
            ['clave' => 'reprobo_examen', 'nombre' => 'Reprobó examen'],
            ['clave' => 'reprobo_faltas', 'nombre' => 'Reprobó por faltas'],
        ]);

        $this->sembrar(ObservacionHistorial::class, [
            ['clave' => 'sin_observacion', 'nombre' => 'Sin observación'],
            ['clave' => 'acta_extemporanea', 'nombre' => 'Acta extemporánea'],
            ['clave' => 'correccion_calificacion', 'nombre' => 'Corrección de calificación'],
        ]);

        // `cat_observacion_asignatura` OFICIAL de la SEP: ids fijos (70–104) y
        // protegido. Es el estatus académico con el que se cursó la asignatura.
        $this->sembrarObservacionesAsignatura([
            [70, 'equivalencia_estudios', 'EQUIVALENCIA DE ESTUDIOS', 'E.'],
            [71, 'examen_extraordinario', 'EXAMEN EXTRAORDINARIO', 'E.E.'],
            [72, 'a_titulo_suficiencia', 'EXAMEN A TÍTULO DE SUFICIENCIA', 'E.T.S.'],
            [73, 'curso_verano', 'CURSO DE VERANO', 'C.V.'],
            [74, 'recursamiento', 'RECURSAMIENTO', 'Rec.'],
            [75, 'reingreso', 'REINGRESO', 'Rein.'],
            [76, 'acuerdo_regularizacion', 'ACUERDO REGULARIZACIÓN', 'A.C.'],
            [77, 'cambio_acuerdo_rvoe', 'CON CAMBIO EN EL ACUERDO DE RVOE', 'C.A.'],
            [78, 'revalidacion_estudios', 'REVALIDACIÓN DE ESTUDIOS', 'R.'],
            [100, 'normal', 'NORMAL / ORDINARIO', null],
            [101, 'correspondencia_asignatura', 'CORRESPONDENCIA DE ASIGNATURA', 'C.A.P.'],
            [102, 'exento', 'EXENTO', 'EX.'],
            [103, 'acreditado', 'ACREDITADO O APROBADO', 'A.'],
            [104, 'curso_regularizacion', 'CURSO DE REGULARIZACIÓN', 'C.R.'],
        ]);
    }

    /**
     * Catálogo oficial con id fijo y `protegido`. Idempotente por id; pensado
     * para tenants nuevos (la tabla arranca vacía). Deja EXACTAMENTE estos
     * valores.
     *
     * @param  array<int, array{0: int, 1: string, 2: string, 3: ?string}>  $filas  [id, clave, nombre, abreviatura]
     */
    private function sembrarObservacionesAsignatura(array $filas): void
    {
        foreach ($filas as [$id, $clave, $nombre, $abreviatura]) {
            $registro = ObservacionAsignatura::query()->firstOrNew(['id' => $id]);
            $registro->forceFill([
                'id' => $id,
                'clave' => $clave,
                'nombre' => $nombre,
                'abreviatura' => $abreviatura,
                'protegido' => true,
            ])->save();
        }

        ObservacionAsignatura::query()->whereNotIn('id', array_column($filas, 0))->delete();
    }

    /**
     * @param  class-string<Model>  $modelo
     * @param  array<int, array{clave: string, nombre: string}>  $filas
     */
    private function sembrar(string $modelo, array $filas): void
    {
        foreach ($filas as $fila) {
            $modelo::query()->updateOrCreate(
                ['clave' => $fila['clave']],
                ['nombre' => $fila['nombre']],
            );
        }
    }
}
