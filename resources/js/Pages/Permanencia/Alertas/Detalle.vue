<script setup lang="ts">
/**
 * La ficha de una alerta: por qué se generó, con qué se midió y qué hacer.
 *
 * ── Su única razón de existir es EXPLICAR ─────────────────────────────────
 * El pedido es explícito: «toda alerta debe explicar por qué se generó» y «no
 * guardes únicamente un puntaje sin explicación». Aquí se enseña la condición
 * en palabras, el valor observado contra su umbral, cuántos datos lo respaldan,
 * el periodo, y la evidencia cruda tal como la congeló el motor.
 *
 * ── Y la CALIDAD de la fuente, que es la mitad ────────────────────────────
 * «Se calcula sobre las sesiones registradas, no sobre el calendario» es lo que
 * impide leer un 60 % como si fuera del semestre entero. Sin ese renglón, quien
 * valida decide sobre un número que cree entender.
 *
 * ── Nada de etiquetas ────────────────────────────────────────────────────
 * Nada que describa a la persona en vez de a la situación: se dice qué se
 * observó y qué falta decidir.
 */
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import BotonVolver from '@/Components/BotonVolver.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import CampoTextarea from '@/Components/CampoTextarea.vue';
import PildoraEstado from '@/Components/PildoraEstado.vue';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps<{
    alerta: Record<string, any>;
    motivos: Array<{ id: number; nombre: string; descripcion: string | null }>;
    puedeValidar: boolean;
    otras: Array<Record<string, any>>;
}>();

const severidades: Record<string, string> = {
    informativo: 'gris',
    bajo: 'azul',
    medio: 'ambar',
    alto: 'naranja',
    critico: 'rojo',
};

const descartando = ref(false);
const motivo = ref<number | null>(null);
const nota = ref('');
const procesando = ref(false);

function validar(): void {
    procesando.value = true;

    router.post(
        `/permanencia/alertas/${props.alerta.id}/validar`,
        { nota: nota.value },
        { preserveScroll: true, onFinish: () => (procesando.value = false) },
    );
}

function descartar(): void {
    procesando.value = true;

    router.post(
        `/permanencia/alertas/${props.alerta.id}/descartar`,
        { motivo_descarte_id: motivo.value, nota: nota.value },
        {
            preserveScroll: true,
            onSuccess: () => (descartando.value = false),
            onFinish: () => (procesando.value = false),
        },
    );
}

/** La evidencia, en renglones legibles y sin las claves de máquina. */
function renglones(evidencia: Record<string, unknown> | null): Array<[string, string]> {
    if (!evidencia) return [];

    const etiquetas: Record<string, string> = {
        sesiones_registradas: 'Sesiones con lista pasada',
        presentes: 'Presentes',
        faltas: 'Faltas',
        justificadas: 'Justificadas',
        retardos: 'Retardos',
        faltas_seguidas: 'Faltas seguidas',
        porcentaje: 'Porcentaje',
        periodo: 'Periodo medido',
        materias_resueltas: 'Materias ya resueltas',
        no_aprobadas: 'No aprobadas',
        promedio: 'Promedio',
        materias_asentadas: 'Materias asentadas',
        actividades_vencidas: 'Actividades ya vencidas',
        sin_entrega: 'Sin entregar',
        dias: 'Días',
        ultima_actividad: 'Última actividad',
        cargos_vencidos: 'Cargos vencidos',
        dias_de_atraso: 'Días de atraso',
        vencimiento_mas_viejo: 'Vencimiento más antiguo',
        obligatorios: 'Documentos obligatorios',
        faltantes: 'Faltantes',
        vence_el: 'Vence el',
        motivo: 'Por qué no se midió',
        fuente: 'De dónde sale',
        nota: 'Nota',
        definicion: 'Cómo se define',
        condicion: 'Condición de la regla',
    };

    // Se ocultan las claves internas: no le dicen nada a quien valida y ensucian
    // lo que sí importa.
    const ocultas = ['inscripcion', 'asignatura_grupo', 'curso', 'expediente', 'regla',
        'version', 'umbral_aplicado', 'valor_observado', 'matricula', 'ciclo'];

    return Object.entries(evidencia)
        .filter(([k, v]) => !ocultas.includes(k) && v !== null && v !== '')
        .map(([k, v]) => [
            etiquetas[k] ?? k.replace(/_/g, ' '),
            Array.isArray(v) ? v.map((x) => (typeof x === 'object' ? JSON.stringify(x) : String(x))).join(' · ') : String(v),
        ]);
}
</script>

<template>
    <Head title="Señal de seguimiento" />

    <AppLayout titulo="Señal de seguimiento">
        <BotonVolver href="/permanencia/alertas" texto="Volver a la bandeja" class="mb-4" />

        <!-- ── Quién y qué ───────────────────────────────────────────────── -->
        <section class="tarjeta mb-4 p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <h2 class="text-lg font-semibold">{{ alerta.alumno }}</h2>
                    <p class="text-sm" :style="{ color: 'var(--color-suave)' }">
                        {{ alerta.matricula }} · {{ alerta.programa }}
                        <span v-if="alerta.campus"> · {{ alerta.campus }}</span>
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <PildoraEstado
                        v-if="alerta.categoria"
                        :texto="alerta.categoria.nombre"
                        :color="alerta.categoria.color"
                    />
                    <PildoraEstado :texto="alerta.severidad" :color="severidades[alerta.severidad] ?? 'gris'" />
                    <PildoraEstado
                        v-if="alerta.estado_triage !== 'nueva'"
                        :texto="alerta.estado_triage === 'validada' ? 'Validada' : 'Descartada'"
                        :color="alerta.estado_triage === 'validada' ? 'verde' : 'gris'"
                    />
                    <PildoraEstado
                        v-if="alerta.estado_senal === 'resuelta'"
                        texto="La situación mejoró"
                        color="verde"
                    />
                    <PildoraEstado
                        v-if="alerta.estado_senal === 'obsoleta'"
                        texto="Se dejó de vigilar"
                        color="gris"
                    />
                </div>
            </div>
        </section>

        <div class="grid gap-4 lg:grid-cols-3">
            <!-- ── Por qué se generó ─────────────────────────────────────── -->
            <section class="tarjeta p-5 lg:col-span-2">
                <h3 class="font-semibold">Por qué se generó</h3>

                <p class="mt-2 text-sm">
                    <span :style="{ color: 'var(--color-suave)' }">Regla:</span>
                    {{ alerta.regla }}
                    <span v-if="alerta.materia"> · {{ alerta.materia }}</span>
                </p>

                <p v-if="alerta.condicion" class="mt-1 text-sm">
                    <span :style="{ color: 'var(--color-suave)' }">Condición:</span>
                    <code class="ml-1 text-xs">{{ alerta.condicion }}</code>
                </p>

                <template v-if="alerta.reservada">
                    <p class="mt-4 rounded-lg border border-borde p-4 text-sm" :style="{ color: 'var(--color-suave)' }">
                        <strong>El detalle de esta categoría es reservado.</strong>
                        {{ alerta.motivo }}
                        Puedes ver que existe la señal —para saber a quién llamar— pero no lo que se
                        midió.
                    </p>
                </template>

                <template v-else>
                    <div class="mt-4 flex flex-wrap gap-6">
                        <div>
                            <p class="text-2xl font-semibold">{{ alerta.valor_observado }}</p>
                            <p class="text-xs" :style="{ color: 'var(--color-suave)' }">se observó</p>
                        </div>
                        <div>
                            <p class="text-2xl font-semibold">{{ alerta.umbral }}</p>
                            <p class="text-xs" :style="{ color: 'var(--color-suave)' }">es el umbral</p>
                        </div>
                        <div>
                            <p class="text-2xl font-semibold">{{ alerta.cobertura }}</p>
                            <p class="text-xs" :style="{ color: 'var(--color-suave)' }">
                                datos lo respaldan
                            </p>
                        </div>
                    </div>

                    <!--
                        La CALIDAD de la fuente. Sin esto, un 60 % se lee como si
                        fuera del semestre entero.
                    -->
                    <p v-if="alerta.calidad" class="mt-4 text-sm" :style="{ color: 'var(--color-suave)' }">
                        <strong>Cómo leer este número:</strong> {{ alerta.calidad }}
                    </p>

                    <div class="mt-4 overflow-x-auto">
                        <table class="w-full min-w-[28rem] text-sm">
                            <tbody>
                                <tr
                                    v-for="[etiqueta, valor] in renglones(alerta.evidencia)"
                                    :key="etiqueta"
                                    class="border-b border-borde/50"
                                >
                                    <td class="py-1.5 pr-4" :style="{ color: 'var(--color-suave)' }">{{ etiqueta }}</td>
                                    <td class="py-1.5">{{ valor }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </template>

                <p class="mt-4 text-xs" :style="{ color: 'var(--color-suave)' }">
                    Se detectó por primera vez el {{ alerta.primera_vez_en }} · última evaluación
                    {{ alerta.ultima_evaluacion_en }}
                    <span v-if="alerta.ventana?.desde">
                        · midiendo del {{ alerta.ventana.desde }} al {{ alerta.ventana.hasta }}
                    </span>
                </p>

                <!-- Si ya mejoró, con qué se comprobó. -->
                <div
                    v-if="alerta.evidencia_cierre"
                    class="mt-4 rounded-lg border border-borde p-4 text-sm"
                >
                    <p class="font-medium">Qué pasó después</p>
                    <p class="mt-1" :style="{ color: 'var(--color-suave)' }">
                        {{ alerta.evidencia_cierre.motivo }}
                        <span v-if="alerta.evidencia_cierre.valor_al_cerrar !== undefined">
                            Al cerrarse se observó {{ alerta.evidencia_cierre.valor_al_cerrar }}.
                        </span>
                    </p>
                </div>
            </section>

            <!-- ── Qué hacer ─────────────────────────────────────────────── -->
            <section class="space-y-4">
                <div class="tarjeta p-5">
                    <h3 class="font-semibold">Qué falta decidir</h3>

                    <template v-if="alerta.estado_triage === 'nueva'">
                        <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                            Nadie la ha revisado todavía. Valídala si amerita acompañamiento, o
                            descártala diciendo por qué.
                        </p>

                        <div v-if="puedeValidar" class="mt-4 space-y-3">
                            <CampoTextarea v-model="nota" etiqueta="Nota" :filas="2" ayuda="Opcional." />

                            <div class="flex flex-wrap gap-2">
                                <BotonPrincipal
                                    :procesando="procesando"
                                    texto="Amerita seguimiento"
                                    icono="guardar"
                                    tipo="button"
                                    @click="validar"
                                />
                                <button
                                    type="button"
                                    class="rounded-lg border border-borde px-4 py-2 text-sm"
                                    @click="descartando = !descartando"
                                >
                                    Descartar
                                </button>
                            </div>

                            <div v-if="descartando" class="space-y-3 rounded-lg border border-borde p-3">
                                <CampoSelect
                                    v-model="motivo"
                                    etiqueta="Motivo del descarte"
                                    :opciones="motivos.map((m) => ({ valor: m.id, texto: m.nombre }))"
                                    ayuda="Elige el que de verdad corresponda: es lo que dice si la regla está mal calibrada."
                                    requerido
                                />
                                <BotonPrincipal
                                    :procesando="procesando"
                                    :deshabilitado="!motivo"
                                    texto="Confirmar el descarte"
                                    icono="ninguno"
                                    tipo="button"
                                    @click="descartar"
                                />
                            </div>
                        </div>

                        <p v-else class="mt-3 text-xs" :style="{ color: 'var(--color-suave)' }">
                            Tu rol puede ver la bandeja pero no decidir sobre las señales.
                        </p>
                    </template>

                    <template v-else>
                        <p class="mt-1 text-sm">
                            <strong>{{ alerta.estado_triage === 'validada' ? 'Se validó' : 'Se descartó' }}</strong>
                            <span v-if="alerta.revisada_por"> · {{ alerta.revisada_por }}</span>
                            <span v-if="alerta.revisada_en"> · {{ alerta.revisada_en }}</span>
                        </p>
                        <p v-if="alerta.motivo_descarte" class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                            Motivo: {{ alerta.motivo_descarte }}
                        </p>
                        <p v-if="alerta.nota_triage" class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                            «{{ alerta.nota_triage }}»
                        </p>
                    </template>
                </div>

                <!-- ── Las otras señales del mismo alumno ────────────────── -->
                <div v-if="otras.length > 0" class="tarjeta p-5">
                    <h3 class="font-semibold">Otras señales de esta persona</h3>
                    <p class="mt-1 text-xs" :style="{ color: 'var(--color-suave)' }">
                        Para decidir con el panorama completo, no señal por señal.
                    </p>
                    <ul class="mt-3 space-y-2">
                        <li v-for="o in otras" :key="o.id" class="text-sm">
                            <Link :href="`/permanencia/alertas/${o.id}`" class="underline">
                                {{ o.regla }}
                            </Link>
                            <PildoraEstado
                                v-if="o.categoria"
                                :texto="o.categoria.nombre"
                                :color="o.categoria.color"
                                class="ml-1"
                            />
                        </li>
                    </ul>
                </div>

                <div class="tarjeta p-5">
                    <h3 class="font-semibold">Su expediente</h3>
                    <p class="mt-2 text-sm">
                        <Link :href="`/escolar/alumnos/${alerta.matricula_id}`" class="underline">
                            Abrir el expediente de {{ alerta.alumno }}
                        </Link>
                    </p>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
