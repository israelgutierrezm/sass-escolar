<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import NavEscolar from '@/Components/NavEscolar.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoSelect from '@/Components/CampoSelect.vue';

const props = defineProps<{
    grupo: Record<string, any> | null;
    ciclos: { id: number; nombre: string }[];
    campus: { id: number; nombre: string }[];
    planes: { id: number; nombre: string }[];
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
                    v-model="form.plan_id"
                    etiqueta="Plan de estudios"
                    :opciones="opciones(planes)"
                    vacio="Sin plan fijo"
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
