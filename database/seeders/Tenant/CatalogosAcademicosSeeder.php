<?php

declare(strict_types=1);

namespace Database\Seeders\Tenant;

use App\Models\Academico\Area;
use App\Models\Academico\AutorizacionReconocimiento;
use App\Models\Academico\ClasificacionAsignatura;
use App\Models\Academico\Descriptor;
use App\Models\Academico\Modalidad;
use App\Models\Academico\NivelEstudio;
use App\Models\Academico\TipoAsignatura;
use App\Models\Academico\TipoCampus;
use App\Models\Academico\TipoPeriodo;
use App\Models\Academico\TipoPlanEstudio;
use App\Models\Academico\Turno;
use Illuminate\Database\Seeder;

/**
 * Catálogos TENANT-CONFIG del módulo de estructura académica. Se ejecuta por
 * tenant. Defaults de la spec; la escuela puede editar. Idempotente por clave.
 */
class CatalogosAcademicosSeeder extends Seeder
{
    public function run(): void
    {
        $this->sembrar(TipoCampus::class, [
            ['clave' => 'matriz', 'nombre' => 'Matriz'],
            ['clave' => 'extension', 'nombre' => 'Extensión'],
            ['clave' => 'online', 'nombre' => 'En línea'],
        ]);

        $this->sembrar(TipoPeriodo::class, [
            ['clave' => 'semestral', 'nombre' => 'Semestral'],
            ['clave' => 'cuatrimestral', 'nombre' => 'Cuatrimestral'],
            ['clave' => 'trimestral', 'nombre' => 'Trimestral'],
            ['clave' => 'anual', 'nombre' => 'Anual'],
            ['clave' => 'modular', 'nombre' => 'Modular'],
        ]);

        $this->sembrar(TipoPlanEstudio::class, [
            ['clave' => 'escolarizado', 'nombre' => 'Escolarizado'],
            ['clave' => 'no_escolarizado', 'nombre' => 'No escolarizado'],
            ['clave' => 'mixto', 'nombre' => 'Mixto'],
        ]);

        // El Tipo queda en cuatro fijas, sin agregar más (decisión del cliente).
        $this->sembrar(TipoAsignatura::class, [
            ['clave' => 'obligatoria', 'nombre' => 'Obligatoria'],
            ['clave' => 'optativa', 'nombre' => 'Optativa'],
            ['clave' => 'adicional', 'nombre' => 'Adicional'],
            ['clave' => 'complementaria', 'nombre' => 'Complementaria'],
        ]);

        // Descriptores del programa de una asignatura. Nace con cuatro; admite más.
        $this->sembrar(Descriptor::class, [
            ['clave' => 'bienvenida', 'nombre' => 'Bienvenida'],
            ['clave' => 'contenido_tematico', 'nombre' => 'Contenido temático'],
            ['clave' => 'actividades_aprendizaje', 'nombre' => 'Actividades de aprendizaje'],
            ['clave' => 'criterios_evaluacion', 'nombre' => 'Criterios de evaluación'],
        ]);

        $this->sembrar(ClasificacionAsignatura::class, [
            ['clave' => 'teorica', 'nombre' => 'Teórica'],
            ['clave' => 'practica', 'nombre' => 'Práctica'],
            ['clave' => 'teorico_practica', 'nombre' => 'Teórico-práctica'],
        ]);

        $this->sembrar(Area::class, [
            ['clave' => 'basica', 'nombre' => 'Área básica'],
            ['clave' => 'disciplinar', 'nombre' => 'Área disciplinar'],
            ['clave' => 'complementaria', 'nombre' => 'Área complementaria'],
        ]);

        $this->sembrar(AutorizacionReconocimiento::class, [
            ['clave' => 'rvoe_federal', 'nombre' => 'RVOE Federal (SEP)'],
            ['clave' => 'rvoe_estatal', 'nombre' => 'RVOE Estatal'],
            ['clave' => 'autonoma', 'nombre' => 'Universidad Autónoma'],
            ['clave' => 'incorporacion_uni', 'nombre' => 'Incorporación a universidad'],
        ]);

        $this->sembrar(Turno::class, [
            ['clave' => 'matutino', 'nombre' => 'Matutino'],
            ['clave' => 'vespertino', 'nombre' => 'Vespertino'],
            ['clave' => 'mixto', 'nombre' => 'Mixto'],
            ['clave' => 'sabatino', 'nombre' => 'Sabatino'],
        ]);

        // Nivel de estudios pasó a catálogo TENANT. `orden` fija la progresión
        // académica. La migración ya lo siembra copiando de la landlord; esto es
        // para instalaciones nuevas.
        $this->sembrar(NivelEstudio::class, [
            ['clave' => 'bachillerato', 'nombre' => 'Bachillerato', 'orden' => 1],
            ['clave' => 'tecnico_superior', 'nombre' => 'Técnico Superior Universitario', 'orden' => 2],
            ['clave' => 'licenciatura', 'nombre' => 'Licenciatura', 'orden' => 3],
            ['clave' => 'especialidad', 'nombre' => 'Especialidad', 'orden' => 4],
            ['clave' => 'maestria', 'nombre' => 'Maestría', 'orden' => 5],
            ['clave' => 'doctorado', 'nombre' => 'Doctorado', 'orden' => 6],
            ['clave' => 'diplomado', 'nombre' => 'Diplomado', 'orden' => 7],
        ]);

        // Modalidades: catálogo TENANT nuevo (presencial / en línea / mixta).
        $this->sembrar(Modalidad::class, [
            ['clave' => 'presencial', 'nombre' => 'Presencial'],
            ['clave' => 'en_linea', 'nombre' => 'En línea'],
            ['clave' => 'mixta', 'nombre' => 'Mixta'],
        ]);
    }

    /**
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $modelo
     * @param  array<int, array<string, mixed>>  $filas  cada una con clave, nombre y lo que aporte
     */
    private function sembrar(string $modelo, array $filas): void
    {
        foreach ($filas as $fila) {
            // Todo lo que no sea la clave (que identifica) va como atributo a
            // fijar: así el mismo helper siembra catálogos con `orden` u otros
            // campos, no solo clave+nombre.
            $atributos = collect($fila)->except('clave')->all();

            $modelo::query()->updateOrCreate(['clave' => $fila['clave']], $atributos);
        }
    }
}
