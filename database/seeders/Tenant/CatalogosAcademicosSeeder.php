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
use Illuminate\Database\Eloquent\Model;
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

        /*
         * Tipos de periodo OFICIALES: id fijo = clave, protegidos (no se editan
         * ni se eliminan). Solo se siembran en tenants nuevos con estos ids.
         *
         * El id ES el valor del catálogo de la SEP —91 = semestre—, y por eso la
         * clave lo repite: no es un descuido, es que aquí no hay otra clave que
         * poner. `ConstructorCertificadoXml` manda LA CLAVE como
         * `idTipoPeriodo`; estos catálogos ya no tienen `identificador`, porque
         * era una tercera copia del mismo número que nadie leía.
         *
         * El NOMBRE va como se lee, no en versalitas: sale en pantalla y en el
         * historial impreso, donde «SEMESTRE 1» grita. De estos dos catálogos el
         * nombre no viaja a la SEP; el del tipo de asignatura sí, y allá se
         * manda en mayúsculas desde el constructor del XML.
         */
        $this->sembrarFijos(TipoPeriodo::class, [
            [91, 'Semestre'],
            [92, 'Bimestre'],
            [93, 'Cuatrimestre'],
            [94, 'Tetramestre'],
            [260, 'Trimestre'],
            [261, 'Modular'],
            [262, 'Anual'],
        ]);

        $this->sembrar(TipoPlanEstudio::class, [
            ['clave' => 'escolarizado', 'nombre' => 'Escolarizado'],
            ['clave' => 'no_escolarizado', 'nombre' => 'No escolarizado'],
            ['clave' => 'mixto', 'nombre' => 'Mixto'],
        ]);

        // El Tipo queda en cuatro fijas, sin agregar más (decisión del cliente).
        // Tipos de asignatura OFICIALES: id fijo = clave, protegidos.
        $this->sembrarFijos(TipoAsignatura::class, [
            [263, 'Obligatoria'],
            [264, 'Optativa'],
            [265, 'Adicional'],
            [266, 'Complementaria'],
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

        // Autorización/Reconocimiento OFICIALES de la SEP: id fijo = valor del
        // catálogo `idAutorizacionReconocimiento` del título electrónico, para
        // que el id del plan sea directamente el que exige la SEP. Protegidos.
        $this->sembrarFijos(AutorizacionReconocimiento::class, [
            [1, 'RVOE FEDERAL'],
            [2, 'RVOE ESTATAL'],
            [3, 'AUTORIZACIÓN FEDERAL'],
            [4, 'AUTORIZACIÓN ESTATAL'],
            [5, 'ACTA DE SESIÓN'],
            [6, 'ACUERDO DE INCORPORACIÓN'],
            [7, 'ACUERDO SECRETARIAL SEP'],
            [8, 'DECRETO DE CREACIÓN'],
            [9, 'OTRO'],
        ]);

        $this->sembrar(Turno::class, [
            ['clave' => 'matutino', 'nombre' => 'Matutino'],
            ['clave' => 'vespertino', 'nombre' => 'Vespertino'],
            ['clave' => 'mixto', 'nombre' => 'Mixto'],
            ['clave' => 'sabatino', 'nombre' => 'Sabatino'],
        ]);

        // Niveles de estudio OFICIALES: id fijo = clave, protegidos. `orden` fija
        // la progresión académica (asociado → TSU → licenciatura → especialidad →
        // maestría → doctorado). El 4º valor es la clave SAT (ClaveProdServ del
        // CFDI de colegiaturas): sólo el TSU lleva 86121803; el resto 86121804.
        // Solo en tenants nuevos con estos ids.
        $this->sembrarFijos(NivelEstudio::class, [
            [83, 'PROFESIONAL ASOCIADO', 1, '86121804'],
            [84, 'TÉCNICO SUPERIOR UNIVERSITARIO', 2, '86121803'],
            [81, 'LICENCIATURA', 3, '86121804'],
            [85, 'ESPECIALIDAD', 4, '86121804'],
            [82, 'MAESTRÍA', 5, '86121804'],
            [95, 'DOCTORADO', 6, '86121804'],
        ]);

        // Modalidades: catálogo TENANT nuevo (presencial / en línea / mixta).
        $this->sembrar(Modalidad::class, [
            ['clave' => 'presencial', 'nombre' => 'Presencial'],
            ['clave' => 'en_linea', 'nombre' => 'En línea'],
            ['clave' => 'mixta', 'nombre' => 'Mixta'],
        ]);
    }

    /**
     * @param  class-string<Model>  $modelo
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

    /**
     * Catálogos de valores fijos con id explícito (= clave) y `protegido`.
     *
     * Se identifican por id para que el id sea el pedido; `forceFill` porque el
     * id no es fillable. Idempotente: re-sembrar no duplica. Pensado para
     * tenants nuevos —donde la tabla está vacía—; en existentes NO se corre para
     * no colisionar con los ids ya asignados a sus programas académicos/planes.
     *
     * @param  class-string<Model>  $modelo
     * @param  array<int, array{0: int, 1: string, 2?: int, 3?: string}>  $filas  [id, nombre, orden?, claveSat?]
     */
    private function sembrarFijos(string $modelo, array $filas): void
    {
        foreach ($filas as $fila) {
            [$id, $nombre] = $fila;

            $registro = $modelo::query()->firstOrNew(['id' => $id]);
            $registro->forceFill([
                'id' => $id,
                'clave' => (string) $id,
                'nombre' => $nombre,
                'protegido' => true,
            ]
                + (isset($fila[2]) ? ['orden' => $fila[2]] : [])
                + (isset($fila[3]) ? ['clave_sat' => $fila[3]] : []))->save();
        }

        // El catálogo queda EXACTAMENTE con estos valores: se quita lo demás
        // (p. ej. los niveles que la migración copió de la landlord). Seguro en
        // tenants nuevos (sin programas académicos/planes); por eso NO se re-siembra sobre
        // tenants existentes, donde borraría valores en uso.
        $modelo::query()->whereNotIn('id', array_column($filas, 0))->delete();
    }
}
