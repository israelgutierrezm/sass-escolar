<?php

declare(strict_types=1);

namespace Database\Seeders\Tenant;

use App\Models\Permanencia\CategoriaSenal;
use App\Models\Permanencia\MotivoCierreCaso;
use App\Models\Permanencia\MotivoDescarte;
use App\Models\Permanencia\TipoIntervencion;
use Illuminate\Database\Seeder;

/**
 * Catálogos TENANT-CONFIG de alertas tempranas y permanencia. Idempotente por
 * clave.
 *
 * ── Se siembran POCOS y CIERTOS ────────────────────────────────────────────
 * Lo que llega es lo que casi cualquier escuela reconoce; lo suyo lo agrega
 * cada una desde la pantalla. Un catálogo exhaustivo produce listas de treinta
 * opciones que nadie depura y entre las que no se encuentra la que se usa.
 *
 * ── Y con LENGUAJE NEUTRAL, que aquí no es cosmética ───────────────────────
 * Ninguna clave, nombre ni descripción de este módulo dice «moroso»,
 * «desertor», «problemático» ni «alumno en riesgo» como si fuera una condición
 * de la persona. Se dice qué se observó —«adeudo con atraso», «asistencia por
 * debajo del mínimo»— y qué hace falta —«requiere revisión», «contacto
 * pendiente»—. Una etiqueta puesta en un catálogo se acaba imprimiendo en un
 * listado, y de ahí no se quita.
 */
class CatalogosPermanenciaSeeder extends Seeder
{
    public function run(): void
    {
        $this->categorias();
        $this->tiposDeIntervencion();
        $this->motivosDeCierre();
        $this->motivosDeDescarte();
    }

    /**
     * Las siete categorías, y cuáles son reservadas.
     *
     * `financiera` y `bienestar` nacen sensibles. La primera porque el pedido
     * lo exige con esas palabras —«un docente ordinario no debería conocer
     * montos o detalles de deuda»—; la segunda porque cualquier cosa que
     * termine ahí es información personal, y su permiso propio es lo que impide
     * que se lea desde la bandeja general.
     *
     * `bienestar` se siembra APAGADA además: no hay todavía ninguna fuente que
     * escriba en ella —la encuesta de bienestar espera su mecanismo de
     * consentimiento—, y una categoría vacía en el filtro se lee como que no
     * hay señales de ese tipo en vez de como que no se están midiendo.
     */
    private function categorias(): void
    {
        $categorias = [
            ['academica', 'Académica', 'Calificaciones, materias reprobadas y avance del plan.', false, null, 'ambar', 10, true],
            ['asistencia', 'Asistencia', 'Faltas, retardos y porcentaje de asistencia.', false, null, 'azul', 20, true],
            ['participacion', 'Participación', 'Entregas, actividad en la plataforma y conexión.', false, null, 'morado', 30, true],
            ['administrativa', 'Administrativa', 'Expediente, inscripción y trámites obligatorios.', false, null, 'gris', 40, true],
            [
                'financiera', 'Financiera',
                'Cargos vencidos y convenios de pago. Su detalle sólo lo abre quien tiene el permiso: el resto ve que hay una señal, no el monto.',
                true, 'ver-alertas-financieras', 'verde', 50, true,
            ],
            [
                'bienestar', 'Bienestar',
                'Lo que el alumno pide o reporta sobre sí mismo. Reservada. Llega apagada porque todavía no hay ninguna fuente que escriba aquí.',
                true, 'ver-notas-reservadas', 'rosa', 60, false,
            ],
            ['referencia', 'Referencia', 'Lo que un docente, un tutor o el propio alumno señalan a mano.', false, null, 'indigo', 70, true],
        ];

        foreach ($categorias as [$clave, $nombre, $descripcion, $sensible, $permiso, $color, $orden, $activo]) {
            CategoriaSenal::query()->firstOrCreate(
                ['clave' => $clave],
                [
                    'nombre' => $nombre,
                    'descripcion' => $descripcion,
                    'sensible' => $sensible,
                    'permiso_detalle' => $permiso,
                    'color' => $color,
                    'orden' => $orden,
                    'activo' => $activo,
                ],
            );
        }
    }

    /**
     * Los trece tipos que el pedido nombra.
     *
     * Las banderas son lo interesante, y no están puestas en bloque:
     *  - **`permite_reservada` sólo en cuatro.** Un «seguimiento de asistencia»
     *    reservado esconde de su propio equipo el dato que el equipo necesita, y
     *    a cambio no protege nada. La reserva es para orientación, canalización,
     *    ajuste razonable y contacto con el tutor, que son las que pueden
     *    contener algo personal.
     *  - **`exige_evidencia` sólo donde el papel ES la intervención.** Una
     *    canalización sin oficio es una intención; una llamada no tiene papel y
     *    pedirlo la volvería imposible de registrar.
     *  - **`exige_acuerdos` donde sin ellos el registro no sirve.** Un contacto
     *    sin acuerdos deja al siguiente que abra el caso sin saber qué se dijo.
     */
    private function tiposDeIntervencion(): void
    {
        // clave, nombre, evidencia, acuerdos, próxima fecha, reservada, orden
        $tipos = [
            ['contacto_alumno', 'Contacto con el alumno', false, true, false, false, 10],
            ['contacto_tutor', 'Contacto con el tutor o la familia', false, true, false, true, 20],
            ['tutoria_academica', 'Tutoría académica', false, true, true, false, 30],
            ['asesoria_docente', 'Asesoría con el docente', false, true, false, false, 40],
            ['regularizacion', 'Regularización', false, true, true, false, 50],
            ['plan_recuperacion', 'Plan de recuperación', true, true, true, false, 60],
            ['orientacion', 'Orientación', false, true, true, true, 70],
            ['apoyo_documental', 'Apoyo con documentación', false, false, true, false, 80],
            ['convenio_financiero', 'Acuerdo de pago', true, true, true, false, 90],
            ['canalizacion', 'Canalización', true, true, true, true, 100],
            ['ajuste_razonable', 'Ajuste razonable', true, true, false, true, 110],
            ['seguimiento_asistencia', 'Seguimiento de asistencia', false, false, true, false, 120],
            ['otro', 'Otro', false, true, false, false, 130],
        ];

        foreach ($tipos as [$clave, $nombre, $evidencia, $acuerdos, $proxima, $reservada, $orden]) {
            TipoIntervencion::query()->firstOrCreate(
                ['clave' => $clave],
                [
                    'nombre' => $nombre,
                    'exige_evidencia' => $evidencia,
                    'exige_acuerdos' => $acuerdos,
                    'exige_proxima_fecha' => $proxima,
                    'permite_reservada' => $reservada,
                    'orden' => $orden,
                ],
            );
        }
    }

    /**
     * Por qué se cerró un caso.
     *
     * `cuenta_como_exito` en NULL es el valor interesante: un traslado o un caso
     * abierto por error no son ni éxito ni fracaso, y contarlos de cualquiera de
     * las dos formas ensucia el único indicador que dice si esto sirve.
     */
    private function motivosDeCierre(): void
    {
        $motivos = [
            ['situacion_resuelta', 'La situación se resolvió', 'El alumno recuperó lo que había caído.', true, 10],
            ['mejora_sostenida', 'Mejora sostenida', 'Se mantuvo por encima del umbral el tiempo acordado.', true, 20],
            ['acuerdo_cumplido', 'Se cumplió el acuerdo', 'Lo que se pactó en la intervención se llevó a cabo.', true, 30],
            ['sin_respuesta', 'No se logró contacto', 'Se intentó por los canales disponibles y no hubo respuesta.', false, 40],
            ['situacion_persiste', 'La situación persiste', 'Se intervino y no cambió. Se cierra para volver a abrirlo con otro enfoque.', false, 50],
            ['baja_del_alumno', 'El alumno causó baja', 'Se cierra porque ya no hay a quién acompañar.', false, 60],
            ['cambio_de_plantel', 'Cambió de plantel o programa', 'El seguimiento le corresponde a otra área.', null, 70],
            ['abierto_por_error', 'Se abrió por error', 'La señal no correspondía a este alumno o el dato estaba mal.', null, 80],
        ];

        foreach ($motivos as [$clave, $nombre, $descripcion, $exito, $orden]) {
            MotivoCierreCaso::query()->firstOrCreate(
                ['clave' => $clave],
                [
                    'nombre' => $nombre,
                    'descripcion' => $descripcion,
                    'cuenta_como_exito' => $exito,
                    'orden' => $orden,
                ],
            );
        }
    }

    /**
     * Por qué se descartó una alerta.
     *
     * Sólo los DOS primeros cuentan como falso positivo, y es la distinción que
     * hace útil el catálogo: acusan a la regla. «Ya se atendió por otra vía» no
     * —ahí la señal era cierta— y contarlo contra la regla la haría parecer mal
     * calibrada justo cuando está funcionando.
     */
    private function motivosDeDescarte(): void
    {
        $motivos = [
            ['dato_incorrecto', 'El dato estaba mal capturado', 'La falta, la calificación o el cargo no correspondían.', true, 10],
            ['regla_no_aplica', 'La regla no aplica a este caso', 'Alcanzó a alguien a quien no debía. Conviene revisar su alcance.', true, 20],
            ['ya_se_atendio', 'Ya se está atendiendo por otra vía', 'La señal es cierta y alguien más está encima.', false, 30],
            ['situacion_justificada', 'Situación justificada', 'Hay una razón conocida y autorizada. Conviene registrar una exclusión.', false, 40],
            ['sin_relevancia', 'No amerita seguimiento', 'Cierta pero menor, a juicio de quien revisa.', false, 50],
        ];

        foreach ($motivos as [$clave, $nombre, $descripcion, $falsoPositivo, $orden]) {
            MotivoDescarte::query()->firstOrCreate(
                ['clave' => $clave],
                [
                    'nombre' => $nombre,
                    'descripcion' => $descripcion,
                    'cuenta_como_falso_positivo' => $falsoPositivo,
                    'orden' => $orden,
                ],
            );
        }
    }
}
