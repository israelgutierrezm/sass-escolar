<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import CampoSelect from '@/Components/CampoSelect.vue';

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
}

const props = defineProps<{
    materias: Materia[];
    ciclos: { id: number; etiqueta: string }[];
    cicloId: number | null;
    puedeCapturar: boolean;
}>();

const cicloId = ref(props.cicloId);

watch(cicloId, () => {
    router.get('/docencia', { ciclo_id: cicloId.value }, { preserveState: true, replace: true });
});

const dias = ['', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo'];

function resumenHorario(horarios: Horario[]): string {
    if (horarios.length === 0) {
        return 'sin horario cargado';
    }

    return horarios.map((h) => `${dias[h.dia] ?? ''} ${h.inicio}–${h.fin}`).join(' · ');
}

const totalAlumnos = computed(() => props.materias.reduce((t, m) => t + m.inscritos, 0));
</script>

<template>
    <Head title="Mis materias" />

    <AppLayout titulo="Mis materias">
        <section class="tarjeta p-6">
            <div class="grid gap-4 sm:grid-cols-2">
                <CampoSelect
                    v-model="cicloId"
                    etiqueta="Ciclo"
                    :opciones="ciclos.map((c) => ({ valor: c.id, texto: c.etiqueta }))"
                    vacio="Todos los ciclos"
                />
            </div>

            <p v-if="materias.length" class="mt-4 text-sm" :style="{ color: 'var(--color-suave)' }">
                {{ materias.length }} materia(s) · {{ totalAlumnos }} alumnos en total.
            </p>
        </section>

        <section v-if="materias.length" class="grid gap-4 sm:grid-cols-2">
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
                        <span
                            class="shrink-0 rounded-full px-2 py-0.5 text-xs capitalize"
                            :style="{
                                backgroundColor:
                                    materia.soy === 'titular'
                                        ? 'color-mix(in srgb, var(--color-acento) 14%, transparent)'
                                        : 'color-mix(in srgb, #64748b 16%, transparent)',
                            }"
                        >
                            {{ materia.soy }}
                        </span>
                    </div>

                    <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                        Grupo {{ materia.grupo }} · ciclo {{ materia.ciclo }}
                        <span v-if="materia.campus"> · {{ materia.campus }}</span>
                    </p>
                    <p class="mt-0.5 text-xs" :style="{ color: 'var(--color-suave)' }">
                        {{ resumenHorario(materia.horarios) }}
                    </p>

                    <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
                        <span>{{ materia.inscritos }} alumnos</span>

                        <span v-if="materia.acta_cerrada" class="text-green-600">acta asentada</span>
                        <span
                            v-else-if="materia.cortes_totales > 0"
                            :class="materia.cortes_abiertos > 0 ? '' : 'text-amber-600'"
                            :style="materia.cortes_abiertos > 0 ? { color: 'var(--color-suave)' } : {}"
                        >
                            {{ materia.cortes_abiertos }} de {{ materia.cortes_totales }} cortes abiertos
                        </span>
                        <span v-else class="text-amber-600">sin esquema de evaluación</span>
                    </div>
                </div>

                <div class="mt-4 flex gap-2">
                    <a
                        :href="`/docencia/materias/${materia.id}`"
                        class="rounded-lg border px-3 py-1.5 text-sm"
                        :style="{ borderColor: 'var(--color-borde)' }"
                    >
                        Ver alumnos
                    </a>
                    <a
                        v-if="puedeCapturar"
                        :href="`/captura/${materia.id}`"
                        class="rounded-lg px-3 py-1.5 text-sm font-medium"
                        :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                    >
                        {{ materia.acta_cerrada ? 'Ver acta' : 'Capturar' }}
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
