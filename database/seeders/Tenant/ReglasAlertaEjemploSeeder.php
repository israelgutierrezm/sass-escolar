<?php

declare(strict_types=1);

namespace Database\Seeders\Tenant;

use App\Models\Permanencia\CategoriaSenal;
use App\Models\Permanencia\ReglaAlerta;
use Illuminate\Database\Seeder;

/**
 * Ocho reglas de ejemplo, TODAS APAGADAS. Idempotente por nombre.
 *
 * ── Por qué apagadas, y por qué la pantalla lo dice ────────────────────────
 * Encendidas, una escuela recién migrada empieza a levantar alertas el primer
 * día sobre datos a medio cargar: la asistencia con dos sesiones capturadas, la
 * cartera a medio generar, el LMS sin un solo curso. A la tercera semana nadie
 * mira la cola, y una cola que nadie mira es peor que no tenerla — porque la
 * escuela cree que está vigilando algo.
 *
 * Es el mismo criterio que la escalera de cobranza y que la publicación
 * automática de grabaciones. Y como allá, **la pantalla lo dice arriba**: ocho
 * reglas escritas se leen como ocho reglas funcionando.
 *
 * ── Los umbrales son un PUNTO DE PARTIDA, no una recomendación ─────────────
 * Ninguno de estos números sale de una norma: son los que casi cualquier
 * escuela reconoce como conversación de partida. La escuela los mueve antes de
 * encender, y el que no los mueva está adoptando los nuestros sin decidirlo —
 * por eso la descripción de cada regla dice de dónde salió su número.
 *
 * ── Ninguna avisa a nadie todavía ──────────────────────────────────────────
 * `avisa_al_alumno` y `avisa_a_la_escuela` llegan apagados aunque la regla se
 * encienda. Encender el aviso es un acto aparte: primero se mira la cola unas
 * semanas, se calibra el umbral con la tasa de descarte, y sólo entonces se le
 * empieza a escribir a la gente.
 */
class ReglasAlertaEjemploSeeder extends Seeder
{
    public function run(): void
    {
        $categorias = CategoriaSenal::query()->pluck('id', 'clave');

        foreach ($this->reglas() as $r) {
            if (! isset($categorias[$r['categoria']])) {
                continue;
            }

            $regla = ReglaAlerta::query()->firstOrCreate(
                ['nombre' => $r['nombre']],
                [
                    'descripcion' => $r['descripcion'],
                    'categoria_id' => $categorias[$r['categoria']],
                    'proveedor' => $r['proveedor'],
                    // Sin ningún eje: son las generales de la escuela, que es lo
                    // que un ejemplo debe ser. Acotarlas a un campus concreto
                    // las volvería inservibles en cuanto la escuela crezca.
                    'activa' => false,
                ],
            );

            if ($regla->versiones()->exists()) {
                continue;
            }

            $regla->versiones()->create([
                'version' => 1,
                'vigente_desde' => now()->toDateString(),
                'metrica' => $r['metrica'],
                'comparador' => $r['comparador'],
                'umbral' => $r['umbral'],
                'umbral_fuente' => $r['umbral_fuente'] ?? 'fijo',
                'ventana_tipo' => $r['ventana_tipo'],
                'ventana_valor' => $r['ventana_valor'] ?? null,
                'cobertura_minima' => $r['cobertura_minima'],
                'severidad' => $r['severidad'],
                'peso' => $r['peso'],
                'frecuencia' => 'diaria',
                'cooldown_dias' => $r['cooldown_dias'],
                'sla_horas' => $r['sla_horas'],
                'notas' => $r['notas'],
            ]);
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function reglas(): array
    {
        return [
            [
                'nombre' => 'Faltas seguidas en una materia',
                'descripcion' => 'Tres sesiones seguidas sin asistir a la misma materia. Es la señal más '
                    .'temprana que existe: aparece antes que cualquier calificación.',
                'categoria' => 'asistencia',
                'proveedor' => 'asistencia',
                'metrica' => 'asistencia.faltas_consecutivas',
                'comparador' => '>=',
                'umbral' => 3,
                'ventana_tipo' => 'ultimos_dias',
                'ventana_valor' => 28,
                'cobertura_minima' => 3,
                'severidad' => 'medio',
                'peso' => 2,
                'cooldown_dias' => 14,
                'sla_horas' => 48,
                'notas' => 'El 3 es el número con el que casi cualquier escuela empieza a preguntar. '
                    .'La cobertura pide 3 sesiones porque con menos no se puede hablar de una racha.',
            ],
            [
                'nombre' => 'Asistencia por debajo del mínimo',
                'descripcion' => 'Asistencia bajo el porcentaje que la escuela exige para conservar el '
                    .'derecho a examen, medida sobre las sesiones REGISTRADAS.',
                'categoria' => 'asistencia',
                'proveedor' => 'asistencia',
                'metrica' => 'asistencia.porcentaje',
                'comparador' => '<',
                'umbral' => 80,
                'ventana_tipo' => 'ciclo',
                'cobertura_minima' => 6,
                'severidad' => 'alto',
                'peso' => 3,
                'cooldown_dias' => 21,
                'sla_horas' => 72,
                'notas' => 'El 80 % es el más común en México, pero NO hay norma: cada escuela lo fija en '
                    .'su reglamento y hay que copiarlo de ahí. La cobertura de 6 sesiones es lo que impide '
                    .'que un alumno con una sola falta registrada salga con «0 % de asistencia».',
            ],
            [
                'nombre' => 'Actividades vencidas sin entregar',
                'descripcion' => 'Dos o más actividades cuya fecha de cierre ya pasó y siguen sin entrega. '
                    .'Sólo cuenta lo vencido: lo que aún no cierra no es un incumplimiento.',
                'categoria' => 'participacion',
                'proveedor' => 'lms',
                'metrica' => 'lms.actividades_vencidas_sin_entrega',
                'comparador' => '>=',
                'umbral' => 2,
                'ventana_tipo' => 'ciclo',
                'cobertura_minima' => 1,
                'severidad' => 'medio',
                'peso' => 2,
                'cooldown_dias' => 14,
                'sla_horas' => 72,
                'notas' => 'Con una sola se dispararía en cada materia de cada alumno la semana de exámenes. '
                    .'La cobertura pide al menos una actividad ya vencida: en un curso donde nada ha cerrado, '
                    .'«cero entregas» no significa nada.',
            ],
            [
                'nombre' => 'Sin actividad en la plataforma',
                'descripcion' => 'Siete días sin abrir, entregar ni contestar nada en un curso que sí está '
                    .'publicado y con actividades.',
                'categoria' => 'participacion',
                'proveedor' => 'lms',
                'metrica' => 'lms.dias_sin_actividad',
                'comparador' => '>=',
                'umbral' => 7,
                'ventana_tipo' => 'ultimos_dias',
                'ventana_valor' => 30,
                'cobertura_minima' => 1,
                'severidad' => 'bajo',
                'peso' => 1,
                'cooldown_dias' => 14,
                'sla_horas' => null,
                'notas' => 'SÓLO tiene sentido donde la escuela usa la plataforma de verdad. En una escuela '
                    .'presencial que no la usa, esta regla encendida pondría a toda la matrícula en la cola: '
                    .'antes de encenderla hay que mirar cuántos cursos publicados hay.',
            ],
            [
                'nombre' => 'Promedio por debajo del mínimo del plan',
                'descripcion' => 'El promedio general de la matrícula cayó bajo la calificación mínima '
                    .'aprobatoria que declara su plan de estudios.',
                'categoria' => 'academica',
                'proveedor' => 'academico',
                'metrica' => 'academico.promedio',
                'comparador' => '<',
                'umbral' => null,
                'umbral_fuente' => 'plan',
                'ventana_tipo' => 'desde_inicio',
                'cobertura_minima' => 1,
                'severidad' => 'alto',
                'peso' => 3,
                'cooldown_dias' => 30,
                'sla_horas' => 72,
                'notas' => 'El umbral se LEE DEL PLAN y no se captura: copiarlo aquí crearía un segundo '
                    .'número que se separaría del real en cuanto alguien corrigiera el plan, y entonces la '
                    .'alerta diría que va reprobando quien no.',
            ],
            [
                'nombre' => 'Dos o más materias no aprobadas en el ciclo',
                'descripcion' => 'Sobre ACTAS CERRADAS del ciclo. Lo que un docente todavía está capturando '
                    .'no cuenta: mediría qué tan rápido captura, no cómo va el alumno.',
                'categoria' => 'academica',
                'proveedor' => 'academico',
                'metrica' => 'academico.reprobadas_ciclo',
                'comparador' => '>=',
                'umbral' => 2,
                'ventana_tipo' => 'ciclo',
                'cobertura_minima' => 1,
                'severidad' => 'alto',
                'peso' => 3,
                'cooldown_dias' => 60,
                'sla_horas' => 96,
                'notas' => 'El enfriamiento es largo a propósito: las actas se cierran por parcial, así que '
                    .'con uno corto la misma situación levantaría alerta en cada cierre.',
            ],
            [
                'nombre' => 'Documento obligatorio por vencer',
                'descripcion' => 'Un documento entregado y aceptado vence dentro de 30 días. Se avisa antes '
                    .'de que caduque, que es cuando todavía se puede resolver sin trámite.',
                'categoria' => 'administrativa',
                'proveedor' => 'expediente',
                'metrica' => 'expediente.dias_para_vencer',
                'comparador' => '<=',
                'umbral' => 30,
                'ventana_tipo' => 'desde_inicio',
                'cobertura_minima' => 1,
                'severidad' => 'informativo',
                'peso' => 1,
                'cooldown_dias' => 30,
                'sla_horas' => null,
                'notas' => 'Informativa y con peso 1: un papel por renovar no es un riesgo de permanencia, '
                    .'pero sí es lo que traba una inscripción tres meses después.',
            ],
            [
                'nombre' => 'Cargo vencido con atraso',
                'descripcion' => 'El cargo vencido más antiguo lleva 15 días o más sin cubrirse. Un convenio '
                    .'de pago vigente lo saca: quien ya se puso de acuerdo con la escuela no es una señal.',
                'categoria' => 'financiera',
                'proveedor' => 'finanzas',
                'metrica' => 'finanzas.dias_de_atraso',
                'comparador' => '>=',
                'umbral' => 15,
                'ventana_tipo' => 'desde_inicio',
                'cobertura_minima' => 1,
                'severidad' => 'medio',
                'peso' => 2,
                'cooldown_dias' => 21,
                'sla_horas' => 96,
                'notas' => 'Categoría RESERVADA: quien no tenga el permiso financiero verá que hay una señal '
                    .'administrativa, no el monto. Y esto NO sustituye a la cobranza —que ya avisa por su '
                    .'cuenta—: aquí el atraso entra como uno de los frentes de un caso de permanencia.',
            ],
        ];
    }
}
