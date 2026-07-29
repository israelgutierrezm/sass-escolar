<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import NavAcademico from '@/Components/NavAcademico.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import CampoSelect from '@/Components/CampoSelect.vue';

interface Carrera {
    id: number;
    nombre: string;
    planes: { id: number; nombre: string }[];
}

const props = defineProps<{ carreras: Carrera[] }>();

// Una asignatura siempre nace dentro de un plan. Aquí se elige carrera → plan y
// se cae en el alta de la malla de ese plan (la única alta de asignaturas).
const carreraId = ref<number | null>(null);
const planId = ref<number | null>(null);

const planesDeCarrera = computed(() => props.carreras.find((c) => c.id === carreraId.value)?.planes ?? []);

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
        <NavAcademico />

        <section class="max-w-xl tarjeta p-6">
            <h2 class="text-base font-semibold">Elige el plan</h2>
            <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                Una asignatura se da de alta dentro de un plan de estudios. Elige la carrera y su plan;
                capturarás los datos de la asignatura en la malla de ese plan.
            </p>

            <div class="mt-5 space-y-4">
                <CampoSelect
                    v-model="carreraId"
                    etiqueta="Carrera"
                    requerido
                    :opciones="carreras.map((c) => ({ valor: c.id, texto: c.nombre }))"
                    vacio="Selecciona…"
                />
                <CampoSelect
                    v-model="planId"
                    etiqueta="Plan de estudios"
                    requerido
                    :opciones="planesDeCarrera.map((p) => ({ valor: p.id, texto: p.nombre }))"
                    :vacio="carreraId ? 'Selecciona…' : 'Elige una carrera primero'"
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
