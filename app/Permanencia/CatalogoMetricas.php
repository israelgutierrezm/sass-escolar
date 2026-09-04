<?php

declare(strict_types=1);

namespace App\Permanencia;

/**
 * Qué se puede medir, declarado en el código.
 *
 * ── Por qué las métricas NO son un catálogo de la base ─────────────────────
 * Cada métrica es una CONSULTA contra el modelo de datos: «faltas consecutivas»
 * recorre `asistencia_clase` ordenada por fecha; «promedio» le pregunta a
 * `HistorialDelAlumno`. Una fila nueva en una tabla no sabría consultar nada, así
 * que un catálogo de métricas sería la promesa falsa que este proyecto ya
 * rechazó con `tipos_actividad` y `tipos_reactivo`: la escuela agregaría
 * «participación en clase» y no pasaría nada.
 *
 * Lo que sí es configurable —y es lo que el cliente pidió— es el UMBRAL, el
 * comparador, la ventana, la severidad, el alcance y el enfriamiento. La regla
 * la escribe la escuela; lo que se puede medir lo escribe quien programa.
 *
 * ── Y se declara ANTES que los proveedores, a propósito ────────────────────
 * Los proveedores que calculan estas métricas llegan en la fase 2. Declararlas
 * ya permite que la pantalla de reglas ofrezca lo real y que guardar una regla
 * con una métrica inventada se rehúse — en vez de guardarla, no medir nada y
 * dejar a quien la escribió creyendo que sí.
 *
 * El riesgo de declarar antes es el contrario: una métrica sin proveedor que
 * nadie note. Contra eso hay una prueba que cruza esta lista con los
 * proveedores registrados y **falla en rojo** mientras alguna quede sin
 * calcular. Es la guarda ruidosa que el módulo formativo ya usó en su fase 1.
 *
 * ── `direccion` no es cosmética ────────────────────────────────────────────
 * Dice hacia dónde es MALO moverse. En «faltas» crece hacia el problema; en
 * «promedio» y en «porcentaje de asistencia» decrece. La pantalla la usa para
 * proponer el comparador —y para avisar cuando el que se eligió mira al lado
 * contrario, que es el error de captura que produce una regla que no se
 * dispara nunca y que nadie descubre hasta que alguien pregunta por qué no hay
 * alertas—.
 */
final class CatalogoMetricas
{
    /** Crece hacia el problema: más faltas, más días sin entrar. */
    public const SUBE = 'sube';

    /** Decrece hacia el problema: menos promedio, menos asistencia. */
    public const BAJA = 'baja';

    /**
     * métrica => declaración.
     *
     * `cobertura` dice qué cuenta la cobertura mínima de esa métrica, y es lo
     * que hace posible el tercer resultado del motor (`sin_datos`). No es lo
     * mismo en todas: en asistencia son las sesiones con lista pasada; en el
     * LMS, las actividades ya vencidas; en lo académico, las materias
     * asentadas.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function todas(): array
    {
        return [
            // ── ASISTENCIA ────────────────────────────────────────────────
            'asistencia.faltas_consecutivas' => [
                'proveedor' => 'asistencia',
                'etiqueta' => 'Faltas seguidas',
                'descripcion' => 'Cuántas sesiones seguidas se le registró falta en una misma materia. '
                    .'Una justificada corta la racha: para eso se justifica.',
                'unidad' => 'sesiones',
                'direccion' => self::SUBE,
                'cobertura' => 'sesiones con lista pasada en la materia',
                'por_materia' => true,
            ],
            'asistencia.porcentaje' => [
                'proveedor' => 'asistencia',
                'etiqueta' => 'Porcentaje de asistencia',
                'descripcion' => 'Presencias sobre sesiones REGISTRADAS, no sobre el calendario. '
                    .'Un 100 % sobre tres sesiones significa que fue a esas tres, no que no ha faltado — '
                    .'por eso esta métrica necesita cobertura mínima más que ninguna.',
                'unidad' => '%',
                'direccion' => self::BAJA,
                'cobertura' => 'sesiones con lista pasada en la materia',
                'por_materia' => true,
            ],

            // ── ACADÉMICO ─────────────────────────────────────────────────
            'academico.promedio' => [
                'proveedor' => 'academico',
                'etiqueta' => 'Promedio general',
                'descripcion' => 'El promedio de la matrícula, tal como lo calcula el historial académico: '
                    .'mejor intento por materia y con la precisión del plan. No se recalcula aquí.',
                'unidad' => 'calificación',
                'direccion' => self::BAJA,
                'cobertura' => 'materias asentadas en el historial',
                'por_materia' => false,
            ],
            'academico.reprobadas_ciclo' => [
                'proveedor' => 'academico',
                'etiqueta' => 'Materias no aprobadas en el ciclo',
                'descripcion' => 'Cuántas materias del ciclo quedaron por debajo del mínimo aprobatorio del plan, '
                    .'contadas sobre lo YA RESUELTO en el historial. Lo que sigue en curso no cuenta: '
                    .'no es un intento fallido, es uno que no ha terminado.',
                'unidad' => 'materias',
                'direccion' => self::SUBE,
                'cobertura' => 'materias ya resueltas del ciclo',
                'por_materia' => false,
            ],
            'academico.avance_creditos' => [
                'proveedor' => 'academico',
                'etiqueta' => 'Avance del plan',
                'descripcion' => 'Porcentaje de créditos aprobados sobre el total del plan. '
                    .'Mide el avance acumulado, no el del ciclo.',
                'unidad' => '%',
                'direccion' => self::BAJA,
                'cobertura' => 'plan con créditos totales capturados',
                'por_materia' => false,
            ],

            // ── PARTICIPACIÓN (LMS) ───────────────────────────────────────
            'lms.actividades_vencidas_sin_entrega' => [
                'proveedor' => 'lms',
                'etiqueta' => 'Actividades vencidas sin entregar',
                'descripcion' => 'Actividades publicadas cuya fecha de cierre YA PASÓ y que no tienen entrega. '
                    .'Una que todavía no vence no cuenta: no es un incumplimiento.',
                'unidad' => 'actividades',
                'direccion' => self::SUBE,
                'cobertura' => 'actividades ya vencidas en el curso',
                'por_materia' => true,
            ],
            'lms.dias_sin_actividad' => [
                'proveedor' => 'lms',
                'etiqueta' => 'Días sin actividad en el curso',
                'descripcion' => 'Días desde la última entrega, vista o intento en el curso. '
                    .'Sólo tiene sentido donde la escuela usa la plataforma: en una materia sin curso publicado '
                    .'no se mide.',
                'unidad' => 'días',
                'direccion' => self::SUBE,
                'cobertura' => 'curso publicado con al menos una actividad',
                'por_materia' => true,
            ],

            // ── ADMINISTRATIVO ────────────────────────────────────────────
            'expediente.documentos_faltantes' => [
                'proveedor' => 'expediente',
                'etiqueta' => 'Documentos obligatorios que faltan',
                'descripcion' => 'De los que la escuela pide como obligatorios, cuántos no se han entregado '
                    .'o fueron rechazados. Los que están por revisar NO cuentan como faltantes: la pelota '
                    .'está del lado de la escuela.',
                'unidad' => 'documentos',
                'direccion' => self::SUBE,
                'cobertura' => 'documentos obligatorios configurados',
                'por_materia' => false,
            ],
            'expediente.dias_para_vencer' => [
                'proveedor' => 'expediente',
                'etiqueta' => 'Días para que venza un documento',
                'descripcion' => 'Del documento entregado que vence más pronto. '
                    .'Sólo alcanza a los que tienen vigencia capturada.',
                'unidad' => 'días',
                'direccion' => self::BAJA,
                'cobertura' => 'documentos con vigencia',
                'por_materia' => false,
            ],

            // ── FINANCIERO ────────────────────────────────────────────────
            'finanzas.dias_de_atraso' => [
                'proveedor' => 'finanzas',
                'etiqueta' => 'Días de atraso del cargo más viejo',
                'descripcion' => 'Del cargo vencido más antiguo que sigue por cobrar. '
                    .'Sólo cuentan los cargos de planes que afectan el estatus, y un convenio de pago '
                    .'vigente los saca: quien ya se puso de acuerdo con la escuela no es una señal.',
                'unidad' => 'días',
                'direccion' => self::SUBE,
                'cobertura' => 'cargos que afectan el estatus',
                'por_materia' => false,
            ],
            'finanzas.cargos_vencidos' => [
                'proveedor' => 'finanzas',
                'etiqueta' => 'Cargos vencidos',
                'descripcion' => 'Cuántos cargos vencidos siguen por cobrar. El número, nunca el importe: '
                    .'esta métrica la puede ver quien no alcanza el detalle financiero.',
                'unidad' => 'cargos',
                'direccion' => self::SUBE,
                'cobertura' => 'cargos que afectan el estatus',
                'por_materia' => false,
            ],

            // ── FORMATIVO ─────────────────────────────────────────────────
            'formativo.dias_de_retraso' => [
                'proveedor' => 'formativo',
                'etiqueta' => 'Días de retraso del proceso formativo',
                'descripcion' => 'Días desde que venció el periodo de su servicio social o prácticas '
                    .'sin que el expediente se haya cerrado.',
                'unidad' => 'días',
                'direccion' => self::SUBE,
                'cobertura' => 'expediente con periodo asignado',
                'por_materia' => false,
            ],
        ];
    }

    /** @return array<int, string> */
    public static function claves(): array
    {
        return array_keys(self::todas());
    }

    public static function existe(string $metrica): bool
    {
        return array_key_exists($metrica, self::todas());
    }

    /** @return array<string, mixed>|null */
    public static function de(string $metrica): ?array
    {
        return self::todas()[$metrica] ?? null;
    }

    /**
     * Las que ofrece un proveedor.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function deProveedor(string $proveedor): array
    {
        return array_filter(self::todas(), fn (array $m) => $m['proveedor'] === $proveedor);
    }

    /** @return array<int, string> */
    public static function proveedores(): array
    {
        return array_values(array_unique(array_column(self::todas(), 'proveedor')));
    }

    /**
     * El comparador que tiene sentido para una métrica, dada su dirección.
     *
     * No se impone: hay reglas legítimas al revés —«promedio por encima de X»
     * para una beca de excelencia—. Se usa para PROPONER en la pantalla y para
     * avisar cuando el elegido mira al lado contrario, que es el error de
     * captura que produce una regla que no se dispara nunca.
     */
    public static function comparadorSugerido(string $metrica): string
    {
        return (self::de($metrica)['direccion'] ?? self::SUBE) === self::BAJA ? '<' : '>=';
    }

    /**
     * ¿El comparador elegido mira hacia el problema?
     *
     * Con `null` no se opina: la métrica no existe y eso lo dice otra
     * validación.
     */
    public static function apuntaAlProblema(string $metrica, string $comparador): ?bool
    {
        $direccion = self::de($metrica)['direccion'] ?? null;

        if ($direccion === null) {
            return null;
        }

        return $direccion === self::SUBE
            ? in_array($comparador, ['>=', '>'], true)
            : in_array($comparador, ['<=', '<'], true);
    }
}
