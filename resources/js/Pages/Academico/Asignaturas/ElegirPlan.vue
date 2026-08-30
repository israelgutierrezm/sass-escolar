<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import PestanasSeccion from '@/Components/PestanasSeccion.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import CampoSelect from '@/Components/CampoSelect.vue';

interface ProgramaAcademico {
    id: number;
    nombre: string;
    planes: { id: number; nombre: string }[];
}

const props = defineProps<{ programas_academicos: ProgramaAcademico[] }>();

// Una asignatura siempre nace dentro de un plan. Aquí se elige programa académico → plan y
// se cae en el alta de la malla de ese plan (la única alta de asignaturas).
const programaAcademicoId = ref<number | null>(null);
const planId = ref<number | null>(null);

const planesDeProgramaAcademico = computed(() => props.programas_academicos.find((c) => c.id === programaAcademicoId.value)?.planes ?? []);

function continuar(): void {
    if (planId.value === null) {
        return;
    }
    // `nueva=1` le dice a la malla que abra el formulario de alta al cargar.
    router.visit(`/academico/planes/${planId.value}/materias?nueva=1`);
}
</script>

<template>
    <Head title="Nueva asignatura" />

    <AppLayout titulo="Nueva asignatura">
        <PestanasSeccion />

        <section class="max-w-xl tarjeta p-6">
            <h2 class="text-base font-semibold">Elige el plan</h2>
            <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                Una asignatura se da de alta dentro de un plan de estudios. Elige el programa académico y su plan;
                capturarás los datos de la asignatura en la malla de ese plan.
            </p>

            <div class="mt-5 space-y-4">
                <CampoSelect
                    v-model="programaAcademicoId"
                    etiqueta="Programa académico"
                    requerido
                    :opciones="programas_academicos.map((c) => ({ valor: c.id, texto: c.nombre }))"
                    vacio="Selecciona…"
                />
                <CampoSelect
                    v-model="planId"
                    etiqueta="Plan de estudios"
                    requerido
                    :opciones="planesDeProgramaAcademico.map((p) => ({ valor: p.id, texto: p.nombre }))"
                    :vacio="programaAcademicoId ? 'Selecciona…' : 'Elige una programa_academico primero'"
                />
            </div>

            <div class="mt-6 flex items-center gap-3">
                <BotonPrincipal tipo="button" texto="Continuar" icono="ninguno" :deshabilitado="planId === null" @click="continuar" />
                <a
                    href="/academico/asignaturas"
                    class="rounded-lg border px-5 py-2.5 text-sm"
                    :style="{ borderColor: 'var(--color-borde)' }"
                >
                    Cancelar
                </a>
            </div>
        </section>
    </AppLayout>
</template>
