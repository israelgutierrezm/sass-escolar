<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import NavEscolar from '@/Components/NavEscolar.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoSelect from '@/Components/CampoSelect.vue';

const props = defineProps<{
    grupo: Record<string, any> | null;
    ciclos: { id: number; nombre: string }[];
    campus: { id: number; nombre: string }[];
    carreras: { id: number; nombre: string }[];
    planes: { id: number; nombre: string; clave: string; carrera_id: number }[];
    turnos: { id: number; nombre: string }[];
    situaciones: { id: number; nombre: string }[];
}>();

const esEdicion = computed(() => props.grupo !== null);

const form = useForm({
    ciclo_id: props.grupo?.ciclo_id ?? null,
    campus_id: props.grupo?.campus_id ?? null,
    plan_id: props.grupo?.plan_id ?? null,
    clave: props.grupo?.clave ?? '',
    nombre: props.grupo?.nombre ?? '',
    cupo: props.grupo?.cupo ?? null,
    turno_id: props.grupo?.turno_id ?? null,
    situacion_id: props.grupo?.situacion_id ?? props.situaciones[0]?.id ?? null,
});

const opciones = (lista: { id: number; nombre: string }[]) =>
    lista.map((item) => ({ valor: item.id, texto: item.nombre }));

/*
 * Carrera → plan, en cascada.
 *
 * El grupo solo guarda `plan_id`; la carrera es un filtro de la pantalla, no un
 * dato que se persista. Se ofrecía un único desplegable con TODOS los planes de
 * la escuela, donde "Plan 2026" de dos carreras distintas se ve idéntico y es
 * fácil atar el grupo a la carrera equivocada.
 *
 * Al editar, la carrera se deduce del plan que ya tiene guardado.
 */
const carreraId = ref<number | null>(
    props.planes.find((plan) => plan.id === props.grupo?.plan_id)?.carrera_id ?? null,
);

const planesDeLaCarrera = computed(() =>
    carreraId.value === null
        ? props.planes
        : props.planes.filter((plan) => plan.carrera_id === carreraId.value),
);

// Cambiar de carrera invalida el plan elegido si ya no pertenece a ella.
watch(carreraId, () => {
    const sigueSiendoValido = planesDeLaCarrera.value.some((plan) => plan.id === form.plan_id);

    if (!sigueSiendoValido) {
        form.plan_id = null;
    }
});

function enviar(): void {
    esEdicion.value ? form.put(`/escolar/grupos/${props.grupo!.id}`) : form.post('/escolar/grupos');
}
</script>

<template>
    <Head :title="esEdicion ? 'Editar grupo' : 'Nuevo grupo'" />

    <AppLayout :titulo="esEdicion ? 'Editar grupo' : 'Nuevo grupo'">
        <NavEscolar />

        <form class="max-w-3xl space-y-6" @submit.prevent="enviar">
            <section class="grid gap-4 rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 sm:grid-cols-2">
                <CampoSelect
                    v-model="form.ciclo_id"
                    etiqueta="Ciclo"
                    requerido
                    :opciones="opciones(ciclos)"
                    vacio="Selecciona…"
                    :error="form.errors.ciclo_id"
                />
                <CampoSelect
                    v-model="form.campus_id"
                    etiqueta="Campus"
                    requerido
                    :opciones="opciones(campus)"
                    vacio="Selecciona…"
                    :error="form.errors.campus_id"
                />
                <CampoTexto v-model="form.clave" etiqueta="Clave" requerido mono :error="form.errors.clave" />
                <CampoTexto v-model="form.nombre" etiqueta="Nombre" :error="form.errors.nombre" />
                <CampoSelect
                    v-model="carreraId"
                    etiqueta="Carrera"
                    :opciones="opciones(carreras)"
                    vacio="Todas las carreras"
                    ayuda="Filtra los planes de abajo. No se guarda en el grupo."
                />
                <CampoSelect
                    v-model="form.plan_id"
                    etiqueta="Plan de estudios"
                    :opciones="planesDeLaCarrera.map((p) => ({ valor: p.id, texto: `${p.clave} · ${p.nombre}` }))"
                    :vacio="carreraId === null ? 'Sin plan fijo' : 'Sin plan fijo (de esta carrera)'"
                    :error="form.errors.plan_id"
                    ayuda="Si lo fijas, solo se podrán abrir materias de ese plan."
                />
                <CampoSelect
                    v-model="form.turno_id"
                    etiqueta="Turno"
                    :opciones="opciones(turnos)"
                    vacio="Sin turno"
                    :error="form.errors.turno_id"
                />
                <CampoTexto
                    v-model="form.cupo"
                    etiqueta="Cupo"
                    tipo="number"
                    :error="form.errors.cupo"
                    ayuda="Se valida al inscribir."
                />
                <CampoSelect
                    v-model="form.situacion_id"
                    etiqueta="Situación"
                    requerido
                    :opciones="opciones(situaciones)"
                    :error="form.errors.situacion_id"
                />
            </section>

            <div class="flex items-center gap-3">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-60"
                >
                    {{ form.processing ? 'Guardando…' : esEdicion ? 'Guardar cambios' : 'Crear grupo' }}
                </button>
                <a
                    href="/escolar/grupos"
                    class="rounded-lg border border-slate-300 px-5 py-2.5 text-sm text-slate-700 hover:bg-slate-50"
                >
                    Cancelar
                </a>
            </div>
        </form>
    </AppLayout>
</template>
