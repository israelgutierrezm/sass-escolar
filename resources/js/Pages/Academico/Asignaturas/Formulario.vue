<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import NavAcademico from '@/Components/NavAcademico.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoSelect from '@/Components/CampoSelect.vue';

const props = defineProps<{
    asignatura: Record<string, any> | null;
    tiposAsignatura: { id: number; nombre: string }[];
    clasificaciones: { id: number; nombre: string }[];
    areas: { id: number; nombre: string }[];
}>();

const esEdicion = computed(() => props.asignatura !== null);

const form = useForm({
    identificador: props.asignatura?.identificador ?? '',
    clave: props.asignatura?.clave ?? '',
    nombre: props.asignatura?.nombre ?? '',
    creditos: props.asignatura?.creditos ?? null,
    tipo_asignatura_id: props.asignatura?.tipo_asignatura_id ?? null,
    clasificacion_id: props.asignatura?.clasificacion_id ?? null,
    area_id: props.asignatura?.area_id ?? null,
    horas_teoria: props.asignatura?.horas_teoria ?? null,
    horas_practica: props.asignatura?.horas_practica ?? null,
    horas_acompanamiento: props.asignatura?.horas_acompanamiento ?? null,
    horas_independientes: props.asignatura?.horas_independientes ?? null,
    objetivos_desc: props.asignatura?.objetivos_desc ?? '',
    bibliografia_desc: props.asignatura?.bibliografia_desc ?? '',
});

const opciones = (lista: { id: number; nombre: string }[]) =>
    lista.map((item) => ({ valor: item.id, texto: item.nombre }));

function enviar(): void {
    esEdicion.value
        ? form.put(`/academico/asignaturas/${props.asignatura!.id}`)
        : form.post('/academico/asignaturas');
}
</script>

<template>
    <Head :title="esEdicion ? 'Editar asignatura' : 'Nueva asignatura'" />

    <AppLayout :titulo="esEdicion ? 'Editar asignatura' : 'Nueva asignatura'">
        <NavAcademico />

        <form class="max-w-4xl space-y-6" @submit.prevent="enviar">
            <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
                <h2 class="text-base font-semibold text-slate-800">Identificación</h2>
                <p class="mt-1 text-sm text-slate-500">
                    La clave de acta se define después, al incluir la asignatura en un plan.
                </p>

                <div class="mt-5 grid gap-4 sm:grid-cols-3">
                    <CampoTexto
                        v-model="form.identificador"
                        etiqueta="Identificador"
                        requerido
                        :error="form.errors.identificador"
                    />
                    <CampoTexto
                        v-model="form.clave"
                        etiqueta="Clave de catálogo"
                        requerido
                        mono
                        :error="form.errors.clave"
                    />
                    <CampoTexto
                        v-model="form.creditos"
                        etiqueta="Créditos"
                        tipo="number"
                        requerido
                        :error="form.errors.creditos"
                    />
                    <div class="sm:col-span-3">
                        <CampoTexto v-model="form.nombre" etiqueta="Nombre" requerido :error="form.errors.nombre" />
                    </div>
                    <CampoSelect
                        v-model="form.tipo_asignatura_id"
                        etiqueta="Tipo"
                        requerido
                        :opciones="opciones(tiposAsignatura)"
                        vacio="Selecciona…"
                        :error="form.errors.tipo_asignatura_id"
                    />
                    <CampoSelect
                        v-model="form.clasificacion_id"
                        etiqueta="Clasificación"
                        :opciones="opciones(clasificaciones)"
                        vacio="Sin especificar"
                        :error="form.errors.clasificacion_id"
                    />
                    <CampoSelect
                        v-model="form.area_id"
                        etiqueta="Área"
                        :opciones="opciones(areas)"
                        vacio="Sin especificar"
                        :error="form.errors.area_id"
                    />
                </div>
            </section>

            <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
                <h2 class="text-base font-semibold text-slate-800">Carga horaria</h2>

                <div class="mt-5 grid gap-4 sm:grid-cols-4">
                    <CampoTexto
                        v-model="form.horas_teoria"
                        etiqueta="Teoría"
                        tipo="number"
                        :error="form.errors.horas_teoria"
                    />
                    <CampoTexto
                        v-model="form.horas_practica"
                        etiqueta="Práctica"
                        tipo="number"
                        :error="form.errors.horas_practica"
                    />
                    <CampoTexto
                        v-model="form.horas_acompanamiento"
                        etiqueta="Acompañamiento"
                        tipo="number"
                        :error="form.errors.horas_acompanamiento"
                    />
                    <CampoTexto
                        v-model="form.horas_independientes"
                        etiqueta="Independientes"
                        tipo="number"
                        :error="form.errors.horas_independientes"
                    />
                </div>
            </section>

            <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
                <h2 class="text-base font-semibold text-slate-800">Descriptores</h2>
                <p class="mt-1 text-sm text-slate-500">Alimentan el programa de estudios y el LMS.</p>

                <div class="mt-5 space-y-4">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">Objetivos</label>
                        <textarea
                            v-model="form.objetivos_desc"
                            rows="3"
                            class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                        ></textarea>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">Bibliografía</label>
                        <textarea
                            v-model="form.bibliografia_desc"
                            rows="3"
                            class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                        ></textarea>
                    </div>
                </div>
            </section>

            <div class="flex items-center gap-3">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-60"
                >
                    {{ form.processing ? 'Guardando…' : esEdicion ? 'Guardar cambios' : 'Crear asignatura' }}
                </button>
                <a
                    href="/academico/asignaturas"
                    class="rounded-lg border border-slate-300 px-5 py-2.5 text-sm text-slate-700 hover:bg-slate-50"
                >
                    Cancelar
                </a>
            </div>
        </form>
    </AppLayout>
</template>
