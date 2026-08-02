<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import PildoraEstado from '@/Components/PildoraEstado.vue';

interface Horario {
    dia: number;
    inicio: string;
    fin: string;
    aula: string | null;
}

interface Materia {
    id: number;
    clave_en_plan: string | null;
    materia: string | null;
    plan: string | null;
    grupo: string | null;
    campus: string | null;
    ciclo: string | null;
    soy: string | null;
    inscritos: number;
    horarios: Horario[];
    acta_cerrada: boolean;
    cortes_abiertos: number;
    cortes_totales: number;
    /** Entregas que llegaron y nadie ha revisado. */
    por_calificar: number;
    /** Mensajes que le escribieron y no ha leído. */
    sin_leer: number;
    /** Si ya se pasó lista hoy en esta materia. */
    lista_hoy: boolean;
}

const props = defineProps<{
    materias: Materia[];
    ciclos: { id: number; etiqueta: string }[];
    cicloId: number | null;
    puedeCapturar: boolean;
}>();

const cicloId = ref(props.cicloId);

/*
 * Sin ciclo elegido, el servidor muestra el VIGENTE: es lo que el docente da
 * hoy. Por eso «todos los ciclos» tiene que pedirse explícitamente —mandar
 * vacío significaría «decide tú» y volvería al vigente—.
 */
watch(cicloId, () => {
    router.get(
        '/docencia',
        { ciclo_id: cicloId.value ?? 'todos' },
        { preserveState: true, replace: true },
    );
});

const dias = ['', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo'];

function resumenHorario(horarios: Horario[]): string {
    if (horarios.length === 0) {
        return 'sin horario cargado';
    }

    return horarios.map((h) => `${dias[h.dia] ?? ''} ${h.inicio}–${h.fin}`).join(' · ');
}

const totalAlumnos = computed(() => props.materias.reduce((t, m) => t + m.inscritos, 0));

/** Lo que espera revisión en todas sus materias juntas. */
const totalPorCalificar = computed(() => props.materias.reduce((t, m) => t + m.por_calificar, 0));
</script>

<template>
    <Head title="Mis materias" />

    <AppLayout titulo="Mis materias">
        <!--
            Cabecera compacta: el selector de ciclo cabe en una línea junto al
            resumen. Ocupaba una tarjeta entera de alto para un desplegable, y
            lo que uno viene a ver son las materias, no el filtro.

            El aviso de trabajo pendiente va aquí porque es la única cifra que
            se mira antes de elegir a dónde entrar.
        -->
        <section class="tarjeta flex flex-wrap items-center justify-between gap-4 px-5 py-4">
            <div class="flex items-center gap-3">
                <label class="text-sm font-medium">Ciclo</label>
                <select
                    v-model="cicloId"
                    class="rounded-lg border px-3 py-2 text-sm"
                    :style="{ borderColor: 'var(--color-borde)' }"
                >
                    <option :value="null">Todos los ciclos</option>
                    <option v-for="c in ciclos" :key="c.id" :value="c.id">{{ c.etiqueta }}</option>
                </select>
            </div>

            <p v-if="materias.length" class="text-sm" :style="{ color: 'var(--color-suave)' }">
                <strong :style="{ color: 'var(--color-contenido)' }">{{ materias.length }}</strong>
                materia(s) · {{ totalAlumnos }} alumnos
                <template v-if="totalPorCalificar">
                    ·
                    <strong :style="{ color: '#d97706' }">{{ totalPorCalificar }} por calificar</strong>
                </template>
            </p>
        </section>

        <section v-if="materias.length" class="grid gap-4 lg:grid-cols-2 2xl:grid-cols-3">
            <article
                v-for="materia in materias"
                :key="materia.id"
                class="tarjeta flex flex-col justify-between p-5"
            >
                <div>
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <p class="font-mono text-xs" :style="{ color: 'var(--color-suave)' }">
                                {{ materia.clave_en_plan }}
                            </p>
                            <h2 class="text-base font-semibold">{{ materia.materia }}</h2>
                        </div>
                        <PildoraEstado
                            class="shrink-0"
                            :texto="materia.soy"
                            :color="materia.soy === 'titular' ? 'var(--color-acento)' : 'var(--color-suave)'"
                        />
                    </div>

                    <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                        Grupo {{ materia.grupo }} · ciclo {{ materia.ciclo }}
                        <span v-if="materia.campus"> · {{ materia.campus }}</span>
                    </p>
                    <p class="mt-0.5 text-xs" :style="{ color: 'var(--color-suave)' }">
                        {{ resumenHorario(materia.horarios) }}
                    </p>

                    <!--
                        Lo que reclama trabajo, con su cifra.

                        Antes decía «3 de 3 cortes abiertos», que es jerga y no
                        se puede accionar: el docente no entra a una materia
                        porque tenga cortes abiertos, entra porque hay trabajos
                        esperando o alguien le escribió.
                    -->
                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        <span
                            v-if="materia.por_calificar"
                            class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium"
                            :style="{ backgroundColor: 'color-mix(in srgb, #d97706 14%, transparent)', color: '#b45309' }"
                        >
                            ✎ {{ materia.por_calificar }} por calificar
                        </span>

                        <span
                            v-if="materia.sin_leer"
                            class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium"
                            :style="{ backgroundColor: 'color-mix(in srgb, var(--color-acento) 14%, transparent)', color: 'var(--color-acento)' }"
                        >
                            ✉ {{ materia.sin_leer }} sin leer
                        </span>

                        <!-- La pregunta de todas las mañanas. -->
                        <span
                            v-if="!materia.acta_cerrada"
                            class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs"
                            :style="materia.lista_hoy
                                ? { backgroundColor: 'color-mix(in srgb, #16a34a 12%, transparent)', color: '#15803d' }
                                : { backgroundColor: 'color-mix(in srgb, var(--color-suave) 12%, transparent)', color: 'var(--color-suave)' }"
                        >
                            {{ materia.lista_hoy ? '✓ lista de hoy' : 'sin lista hoy' }}
                        </span>

                        <span
                            v-if="materia.acta_cerrada"
                            class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium"
                            :style="{ backgroundColor: 'color-mix(in srgb, #16a34a 12%, transparent)', color: '#15803d' }"
                        >
                            ✓ acta asentada
                        </span>
                        <span v-else-if="materia.cortes_totales === 0" class="text-xs text-amber-600">
                            sin esquema de evaluación
                        </span>
                    </div>
                </div>

                <div class="mt-4 flex flex-wrap gap-2">
                    <!-- Dentro está todo lo de la materia: calificar, pasar
                         lista, la lista de alumnos y las actividades. «Ver
                         alumnos» nombraba solo una de las cuatro. -->
                    <a
                        :href="`/docencia/materias/${materia.id}`"
                        class="rounded-lg px-3 py-1.5 text-sm font-medium"
                        :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                    >
                        Entrar a la materia
                    </a>

                    <!-- Atajo directo a lo del día: pasar lista es lo que más
                         se repite y estaba a dos clics dentro de la materia. -->
                    <a
                        v-if="!materia.acta_cerrada"
                        :href="`/docencia/materias/${materia.id}?panel=asistencia`"
                        class="rounded-lg border px-3 py-1.5 text-sm"
                        :style="{ borderColor: 'var(--color-borde)' }"
                    >
                        Pasar lista
                    </a>

                    <!-- La captura del PARCIAL es otra cosa que el libro de
                         calificaciones: ahí se cierra el acta. -->
                    <a
                        v-if="puedeCapturar"
                        :href="`/captura/${materia.id}`"
                        class="rounded-lg border px-3 py-1.5 text-sm"
                        :style="{ borderColor: 'var(--color-borde)' }"
                    >
                        {{ materia.acta_cerrada ? 'Ver acta' : 'Capturar parcial' }}
                    </a>
                </div>
            </article>
        </section>

        <p v-else class="tarjeta px-6 py-12 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
            No tienes materias asignadas{{ cicloId ? ' en este ciclo' : '' }}. Control escolar es quien
            asigna docentes a las materias de cada grupo.
        </p>
    </AppLayout>
</template>
