<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import NavAcademico from '@/Components/NavAcademico.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import CampoCheckbox from '@/Components/CampoCheckbox.vue';

const props = defineProps<{
    campus: Record<string, any> | null;
    tiposCampus: { id: number; nombre: string }[];
    entidades: { id: number; nombre: string }[];
}>();

const esEdicion = computed(() => props.campus !== null);

const form = useForm({
    clave: props.campus?.clave ?? '',
    nombre: props.campus?.nombre ?? '',
    tipo_campus_id: props.campus?.tipo_campus_id ?? null,
    online: props.campus?.online ?? false,
    entidad_id: props.campus?.entidad_id ?? null,
});

const opcionesTipo = computed(() => props.tiposCampus.map((t) => ({ valor: t.id, texto: t.nombre })));
const opcionesEntidad = computed(() => props.entidades.map((e) => ({ valor: e.id, texto: e.nombre })));

function enviar(): void {
    esEdicion.value ? form.put(`/academico/campus/${props.campus!.id}`) : form.post('/academico/campus');
}
</script>

<template>
    <Head :title="esEdicion ? 'Editar campus' : 'Nuevo campus'" />

    <AppLayout :titulo="esEdicion ? 'Editar campus' : 'Nuevo campus'">
        <NavAcademico />

        <form class="max-w-3xl space-y-6" @submit.prevent="enviar">
            <section class="grid gap-4 rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 sm:grid-cols-2">
                <CampoTexto v-model="form.clave" etiqueta="Clave" requerido :error="form.errors.clave" mono />
                <CampoTexto v-model="form.nombre" etiqueta="Nombre" requerido :error="form.errors.nombre" />
                <CampoSelect
                    v-model="form.tipo_campus_id"
                    etiqueta="Tipo de campus"
                    requerido
                    :opciones="opcionesTipo"
                    vacio="Selecciona…"
                    :error="form.errors.tipo_campus_id"
                />
                <CampoSelect
                    v-model="form.entidad_id"
                    etiqueta="Entidad federativa"
                    :opciones="opcionesEntidad"
                    vacio="Sin especificar"
                    :error="form.errors.entidad_id"
                    ayuda="Catálogo compartido entre todas las escuelas."
                />
                <div class="sm:col-span-2">
                    <CampoCheckbox
                        v-model="form.online"
                        etiqueta="Campus 100% en línea"
                        ayuda="Sin sede física; su oferta se imparte a distancia."
                    />
                </div>
            </section>

            <div class="flex items-center gap-3">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-60"
                >
                    {{ form.processing ? 'Guardando…' : esEdicion ? 'Guardar cambios' : 'Crear campus' }}
                </button>
                <a
                    href="/academico/campus"
                    class="rounded-lg border border-slate-300 px-5 py-2.5 text-sm text-slate-700 hover:bg-slate-50"
                >
                    Cancelar
                </a>
            </div>
        </form>
    </AppLayout>
</template>
