<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import NavAcademico from '@/Components/NavAcademico.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoSelect from '@/Components/CampoSelect.vue';

const props = defineProps<{
    carrera: Record<string, any> | null;
    documentosSeleccionados: number[];
    niveles: { id: number; nombre: string }[];
    documentos: { id: number; nombre: string; obligatorio: boolean }[];
}>();

const esEdicion = computed(() => props.carrera !== null);

const form = useForm({
    identificador: props.carrera?.identificador ?? '',
    clave: props.carrera?.clave ?? '',
    nombre: props.carrera?.nombre ?? '',
    nivel_estudios_id: props.carrera?.nivel_estudios_id ?? null,
    clave_sat: props.carrera?.clave_sat ?? '',
    objetivo: props.carrera?.objetivo ?? '',
    imagen_url: props.carrera?.imagen_url ?? '',
    documentos: [...props.documentosSeleccionados],
});

const opcionesNivel = computed(() => props.niveles.map((n) => ({ valor: n.id, texto: n.nombre })));

function enviar(): void {
    esEdicion.value ? form.put(`/academico/carreras/${props.carrera!.id}`) : form.post('/academico/carreras');
}
</script>

<template>
    <Head :title="esEdicion ? 'Editar carrera' : 'Nueva carrera'" />

    <AppLayout :titulo="esEdicion ? 'Editar carrera' : 'Nueva carrera'">
        <NavAcademico />

        <form class="max-w-3xl space-y-6" @submit.prevent="enviar">
            <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
                <h2 class="text-base font-semibold text-slate-800">Datos de la carrera</h2>

                <div class="mt-5 grid gap-4 sm:grid-cols-2">
                    <CampoTexto
                        v-model="form.identificador"
                        etiqueta="Identificador"
                        requerido
                        :error="form.errors.identificador"
                        ayuda="ID estable, se conserva entre migraciones."
                    />
                    <CampoTexto v-model="form.clave" etiqueta="Clave" requerido :error="form.errors.clave" mono />
                    <div class="sm:col-span-2">
                        <CampoTexto v-model="form.nombre" etiqueta="Nombre" requerido :error="form.errors.nombre" />
                    </div>
                    <CampoSelect
                        v-model="form.nivel_estudios_id"
                        etiqueta="Nivel de estudios"
                        requerido
                        :opciones="opcionesNivel"
                        vacio="Selecciona…"
                        :error="form.errors.nivel_estudios_id"
                    />
                    <CampoTexto
                        v-model="form.clave_sat"
                        etiqueta="Clave SAT"
                        :error="form.errors.clave_sat"
                        ayuda="ClaveProdServ para el CFDI de colegiaturas."
                    />
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-sm font-medium text-slate-700">Objetivo</label>
                        <textarea
                            v-model="form.objetivo"
                            rows="3"
                            class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                        ></textarea>
                    </div>
                </div>
            </section>

            <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
                <h2 class="text-base font-semibold text-slate-800">Documentos de admisión</h2>
                <p class="mt-1 text-sm text-slate-500">
                    Qué se le pide al aspirante que quiere entrar a esta carrera.
                </p>

                <div class="mt-4 grid gap-2 sm:grid-cols-2">
                    <label
                        v-for="documento in documentos"
                        :key="documento.id"
                        class="flex items-center gap-2 rounded-lg border border-slate-200 px-3 py-2 text-sm"
                    >
                        <input
                            v-model="form.documentos"
                            type="checkbox"
                            :value="documento.id"
                            class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                        />
                        <span class="text-slate-700">{{ documento.nombre }}</span>
                        <span
                            v-if="documento.obligatorio"
                            class="ml-auto rounded bg-amber-100 px-1.5 py-0.5 text-xs text-amber-700"
                        >
                            Obligatorio
                        </span>
                    </label>
                </div>
            </section>

            <div class="flex items-center gap-3">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-60"
                >
                    {{ form.processing ? 'Guardando…' : esEdicion ? 'Guardar cambios' : 'Crear carrera' }}
                </button>
                <a
                    href="/academico/carreras"
                    class="rounded-lg border border-slate-300 px-5 py-2.5 text-sm text-slate-700 hover:bg-slate-50"
                >
                    Cancelar
                </a>
            </div>
        </form>
    </AppLayout>
</template>
