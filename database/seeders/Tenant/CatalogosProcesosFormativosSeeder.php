<?php

declare(strict_types=1);

namespace Database\Seeders\Tenant;

use App\Models\ProcesosFormativos\ModalidadProceso;
use App\Models\ProcesosFormativos\SectorOrganizacion;
use App\Models\ProcesosFormativos\SituacionOrganizacion;
use App\Models\ProcesosFormativos\TipoConvenioFormativo;
use App\Models\ProcesosFormativos\TipoInformeProceso;
use App\Models\ProcesosFormativos\TipoOrganizacion;
use App\Models\ProcesosFormativos\TipoProcesoFormativo;
use Illuminate\Database\Seeder;

/**
 * Catálogos TENANT-CONFIG de servicio social y prácticas. Idempotente por clave.
 *
 * ── Se siembran POCOS y CIERTOS ────────────────────────────────────────────
 * Lo que llega es lo que casi cualquier escuela mexicana reconoce; lo suyo lo
 * agrega cada una desde la pantalla. Sembrar un catálogo exhaustivo produce
 * listas de treinta opciones que nadie depura y entre las que no se encuentra
 * la que se usa.
 *
 * Lo que sí importa es que las BANDERAS lleguen con valores que signifiquen
 * algo: son ellas —y no la clave— lo que el código consulta.
 */
class CatalogosProcesosFormativosSeeder extends Seeder
{
    public function run(): void
    {
        $this->tipos();
        $this->organizaciones();
        $this->convenios();
        $this->modalidades();
        $this->informes();
    }

    /**
     * Los ocho tipos que el pedido nombra como base.
     *
     * Las banderas son lo interesante:
     *  - «Experiencia profesional» se acredita con una constancia laboral, así
     *    que NO cuenta horas: pedirle bitácora dejaría el expediente esperando
     *    algo que nadie va a capturar.
     *  - «Proyecto comunitario» lo organiza la propia escuela, así que no exige
     *    organización receptora — obligarla convertiría en trámite falso
     *    capturarse a sí misma como si fuera un tercero.
     *  - «Residencia» e «internado» exigen PLAZA: son procesos donde la escuela
     *    coloca, no donde el alumno llega con su carta.
     */
    private function tipos(): void
    {
        $tipos = [
            ['servicio_social', 'Servicio social', true, false, true, true, 1],
            ['practicas_profesionales', 'Prácticas profesionales', true, false, true, true, 2],
            ['residencia_profesional', 'Residencia profesional', true, true, false, true, 3],
            ['estancia_profesional', 'Estancia profesional', true, true, false, true, 4],
            ['internado', 'Internado', true, true, false, true, 5],
            ['proyecto_comunitario', 'Proyecto comunitario', false, false, true, true, 6],
            ['experiencia_profesional', 'Experiencia profesional', true, false, true, false, 7],
            ['otro', 'Otro', true, false, true, true, 8],
        ];

        foreach ($tipos as [$clave, $nombre, $organizacion, $plaza, $propuesta, $horas, $orden]) {
            TipoProcesoFormativo::query()->updateOrCreate(
                ['clave' => $clave],
                [
                    'nombre' => $nombre,
                    'exige_organizacion' => $organizacion,
                    'exige_plaza' => $plaza,
                    'permite_organizacion_propuesta' => $propuesta,
                    'cuenta_horas' => $horas,
                    'orden' => $orden,
                ],
            );
        }
    }

    private function organizaciones(): void
    {
        $sectores = [
            ['gobierno', 'Gobierno y administración pública', 1],
            ['salud', 'Salud y asistencia social', 2],
            ['educacion', 'Educación', 3],
            ['asistencia', 'Asociaciones y asistencia privada', 4],
            ['industria', 'Industria y manufactura', 5],
            ['servicios', 'Servicios y comercio', 6],
        ];

        foreach ($sectores as [$clave, $nombre, $orden]) {
            SectorOrganizacion::query()->updateOrCreate(
                ['clave' => $clave],
                ['nombre' => $nombre, 'orden' => $orden],
            );
        }

        $tipos = [
            ['dependencia_federal', 'Dependencia federal', 1],
            ['dependencia_estatal', 'Dependencia estatal', 2],
            ['dependencia_municipal', 'Dependencia municipal', 3],
            ['asociacion_civil', 'Asociación civil', 4],
            ['empresa', 'Empresa', 5],
            ['institucion_educativa', 'Institución educativa', 6],
        ];

        foreach ($tipos as [$clave, $nombre, $orden]) {
            TipoOrganizacion::query()->updateOrCreate(
                ['clave' => $clave],
                ['nombre' => $nombre, 'orden' => $orden],
            );
        }

        /*
         * Tres situaciones, y sólo UNA acepta asignaciones.
         *
         * «En revisión» es donde nace la que propone un alumno: existe en el
         * padrón, se puede ver y todavía no recibe a nadie. Sin ese estado
         * intermedio, autorizar una organización sería lo mismo que crearla.
         */
        $situaciones = [
            ['activa', 'Activa', true, 1],
            ['en_revision', 'En revisión', false, 2],
            ['suspendida', 'Suspendida', false, 3],
        ];

        foreach ($situaciones as [$clave, $nombre, $acepta, $orden]) {
            SituacionOrganizacion::query()->updateOrCreate(
                ['clave' => $clave],
                ['nombre' => $nombre, 'acepta_asignaciones' => $acepta, 'orden' => $orden],
            );
        }
    }

    private function convenios(): void
    {
        $tipos = [
            ['marco', 'Convenio marco', 1],
            ['especifico', 'Convenio específico', 2],
            ['carta_compromiso', 'Carta compromiso', 3],
        ];

        foreach ($tipos as [$clave, $nombre, $orden]) {
            TipoConvenioFormativo::query()->updateOrCreate(
                ['clave' => $clave],
                ['nombre' => $nombre, 'orden' => $orden],
            );
        }
    }

    private function modalidades(): void
    {
        $modalidades = [
            ['presencial', 'Presencial', false, 1],
            ['mixta', 'Mixta', false, 2],
            ['remota', 'Remota', true, 3],
        ];

        foreach ($modalidades as [$clave, $nombre, $aDistancia, $orden]) {
            ModalidadProceso::query()->updateOrCreate(
                ['clave' => $clave],
                ['nombre' => $nombre, 'es_a_distancia' => $aDistancia, 'orden' => $orden],
            );
        }
    }

    /**
     * Dos tipos de informe, y sólo uno es el final.
     *
     * Cuántos parciales pide cada programa lo dice su REGLA, no este catálogo:
     * aquí se declara qué CLASE de informe existe, y allá cuántos y cada cuándo.
     */
    private function informes(): void
    {
        $tipos = [
            ['parcial', 'Informe parcial', 'El avance periódico que la regla del programa pida.', false, 1],
            ['final', 'Informe final', 'El que cierra el proceso; la liberación lo exige.', true, 2],
        ];

        foreach ($tipos as [$clave, $nombre, $descripcion, $final, $orden]) {
            TipoInformeProceso::query()->updateOrCreate(
                ['clave' => $clave],
                ['nombre' => $nombre, 'descripcion' => $descripcion, 'es_final' => $final, 'orden' => $orden],
            );
        }
    }
}
