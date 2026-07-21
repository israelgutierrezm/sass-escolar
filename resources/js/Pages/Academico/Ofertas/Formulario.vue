<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import NavAcademico from '@/Components/NavAcademico.vue';
import CampoSelect from '@/Components/CampoSelect.vue';

const props = defineProps<{
    oferta: Record<string, any> | null;
    carreras: { id: number; nombre: string }[];
    planes: { id: number; nombre: string; clave: string; carrera_id: number }[];
    campus: { id: number; nombre: string }[];
    turnos: { id: number; nombre: string }[];
}>();

const esEdicion = computed(() => props.oferta !== null);

const form = useForm({
    carrera_id: props.oferta?.carrera_id ?? null,
    plan_id: props.oferta?.plan_id ?? null,
    campus_id: props.oferta?.campus_id ?? null,
    turno_id: props.oferta?.turno_id ?? null,
    modalidad: props.oferta?.modalidad ?? 'presencial',
    estatus: props.oferta?.estatus ?? 'abierta',
});

/** El plan debe pertenecer a la carrera: se filtra el selector en consecuencia. */
const planesDeLaCarrera = computed(() =>
    props.planes
        .filter((plan) => plan.carrera_id === form.carrera_id)
        .map((plan) => ({ valor: plan.id, texto: `${plan.nombre} (${plan.clave})` })),
);

// Si cambia la carrera, un plan de la anterior dejaría de ser válido.
watch(
    () => form.carrera_id,
    () => {
        if (!planesDeLaCarrera.value.some((plan) => plan.valor === form.plan_id)) {
            form.plan_id = null;
        }
    },
);

const opciones = (lista: { id: number; nombre: string }[]) =>
    lista.map((item) => ({ valor: item.id, texto: item.nombre }));

function enviar(): void {
    esEdicion.value ? form.put(`/academico/ofertas/${props.oferta!.id}`) : form.post('/academico/ofertas');
}
</script>

<template>
    <Head :title="esEdicion ? 'Editar oferta' : 'Nueva oferta'" />

    <AppLayout :titulo="esEdicion ? 'Editar oferta' : 'Nueva oferta'">
        <NavAcademico />

        <form class="max-w-3xl space-y-6" @submit.prevent="enviar">
            <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
                <h2 class="text-base font-semibold text-slate-800">Qué se imparte y dónde</h2>
                <p class="mt-1 text-sm text-slate-500">
                    No puede repetirse la misma combinación de carrera, plan, campus y turno.
                </p>

                <div class="mt-5 grid gap-4 sm:grid-cols-2">
                    <CampoSelect
                        v-model="form.carrera_id"
                        etiqueta="Carrera"
                        requerido
                        :opciones="opciones(carreras)"
                        vacio="Selecciona…"
                        :error="form.errors.carrera_id"
                    />
                    <CampoSelect
                        v-model="form.plan_id"
                        etiqueta="Plan de estudios"
                        requerido
                        :opciones="planesDeLaCarrera"
                        vacio="Selecciona…"
                        :error="form.errors.plan_id"
                        :ayuda="
                            form.carrera_id === null
                                ? 'Elige primero una carrera.'
                                : planesDeLaCarrera.length === 0
                                  ? 'Esa carrera no tiene planes registrados.'
                                  : undefined
                        "
                    />
                    <CampoSelect
                        v-model="form.campus_id"
                        etiqueta="Campus"
                        requerido
                        :opciones="opciones(campus)"
                        vacio="Selecciona…"
                        :error="form.errors.campus_id"
                    />
                    <CampoSelect
                        v-model="form.turno_id"
                        etiqueta="Turno"
                        :opciones="opciones(turnos)"
                        vacio="Sin turno específico"
                        :error="form.errors.turno_id"
                    />
                    <CampoSelect
                        v-model="form.modalidad"
                        etiqueta="Modalidad"
                        requerido
                        :opciones="[
                            { valor: 'presencial', texto: 'Presencial' },
                            { valor: 'online', texto: 'En línea' },
                            { valor: 'mixta', texto: 'Mixta' },
                        ]"
                        :error="form.errors.modalidad"
                    />
                    <CampoSelect
                        v-model="form.estatus"
                        etiqueta="Estatus"
                        requerido
                        :opciones="[
                            { valor: 'abierta', texto: 'Abierta' },
                            { valor: 'cerrada', texto: 'Cerrada' },
                        ]"
                        :error="form.errors.estatus"
                        ayuda="Solo las abiertas aparecen al registrar aspirantes."
                    />
                </div>
            </section>

            <div class="flex items-center gap-3">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-60"
                >
                    {{ form.processing ? 'Guardando…' : esEdicion ? 'Guardar cambios' : 'Crear oferta' }}
                </button>
                <a
                    href="/academico/ofertas"
                    class="rounded-lg border border-slate-300 px-5 py-2.5 text-sm text-slate-700 hover:bg-slate-50"
                >
                    Cancelar
                </a>
            </div>
        </form>
    </AppLayout>
</template>
