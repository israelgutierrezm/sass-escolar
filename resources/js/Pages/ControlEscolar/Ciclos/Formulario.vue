<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import NavEscolar from '@/Components/NavEscolar.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import CampoCasillas from '@/Components/CampoCasillas.vue';

const props = defineProps<{
    ciclo: Record<string, any> | null;
    campus: { id: number; nombre: string }[];
    situaciones: { id: number; nombre: string }[];
    alcanceAcotado: boolean;
}>();

const esEdicion = computed(() => props.ciclo !== null);

const campusAjenos = computed<string[]>(() => props.ciclo?.campus_ajenos ?? []);

const form = useForm({
    campus_ids: (props.ciclo?.campus_ids ?? []) as number[],
    clave: props.ciclo?.clave ?? '',
    nombre: props.ciclo?.nombre ?? '',
    fecha_inicio: props.ciclo?.fecha_inicio ?? '',
    fecha_fin: props.ciclo?.fecha_fin ?? '',
    situacion_id: props.ciclo?.situacion_id ?? props.situaciones[0]?.id ?? null,
    inscripcion_desde: props.ciclo?.inscripcion_desde ?? '',
    inscripcion_hasta: props.ciclo?.inscripcion_hasta ?? '',
    altas_bajas_hasta: props.ciclo?.altas_bajas_hasta ?? '',
    captura_calif_hasta: props.ciclo?.captura_calif_hasta ?? '',
});

const opciones = (lista: { id: number; nombre: string }[]) =>
    lista.map((item) => ({ valor: item.id, texto: item.nombre }));

function enviar(): void {
    esEdicion.value ? form.put(`/escolar/ciclos/${props.ciclo!.id}`) : form.post('/escolar/ciclos');
}
</script>

<template>
    <Head :title="esEdicion ? 'Editar ciclo' : 'Nuevo ciclo'" />

    <AppLayout :titulo="esEdicion ? 'Editar ciclo' : 'Nuevo ciclo'">
        <NavEscolar />

        <form class="max-w-3xl space-y-6" @submit.prevent="enviar">
            <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
                <h2 class="text-base font-semibold text-slate-800">Identificación y periodo</h2>

                <div class="mt-5 grid gap-4 sm:grid-cols-2">
                    <CampoTexto
                        v-model="form.clave"
                        etiqueta="Clave"
                        requerido
                        mono
                        marcador="2026-2027/1"
                        :error="form.errors.clave"
                    />
                    <CampoTexto v-model="form.nombre" etiqueta="Nombre" requerido :error="form.errors.nombre" />
                    <CampoSelect
                        v-model="form.situacion_id"
                        etiqueta="Situación"
                        requerido
                        :opciones="opciones(situaciones)"
                        :error="form.errors.situacion_id"
                    />
                    <CampoTexto
                        v-model="form.fecha_inicio"
                        etiqueta="Inicio del ciclo"
                        tipo="date"
                        requerido
                        :error="form.errors.fecha_inicio"
                    />
                    <CampoTexto
                        v-model="form.fecha_fin"
                        etiqueta="Fin del ciclo"
                        tipo="date"
                        requerido
                        :error="form.errors.fecha_fin"
                    />
                </div>

                <div class="mt-5">
                    <CampoCasillas
                        v-model="form.campus_ids"
                        etiqueta="Campus donde aplica"
                        :opciones="opciones(campus)"
                        :error="form.errors.campus_ids"
                        vacio="No tienes campus asignados."
                        :ayuda="
                            alcanceAcotado
                                ? 'Solo aparecen los campus de tu alcance. Sin marcar ninguno, el ciclo es global de la escuela.'
                                : 'Marca uno o varios. Sin marcar ninguno, el ciclo es global de la escuela.'
                        "
                    />

                    <!-- Campus del ciclo que este administrador no gestiona: se
                         muestran para que sepa que el ciclo es más amplio de lo
                         que ve, y se conservan intactos al guardar. -->
                    <p v-if="campusAjenos.length" class="mt-2 text-xs text-slate-500">
                        Este ciclo también aplica en
                        <span class="font-medium">{{ campusAjenos.join(', ') }}</span>, fuera de tu
                        alcance. No se modificarán al guardar.
                    </p>
                </div>
            </section>

            <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
                <h2 class="text-base font-semibold text-slate-800">Ventanas</h2>
                <p class="mt-1 text-sm text-slate-500">
                    Gobiernan qué se puede hacer y cuándo. Fuera de la ventana de inscripción, el sistema no
                    deja inscribir.
                </p>

                <div class="mt-5 grid gap-4 sm:grid-cols-2">
                    <CampoTexto
                        v-model="form.inscripcion_desde"
                        etiqueta="Inscripción desde"
                        tipo="date"
                        :error="form.errors.inscripcion_desde"
                    />
                    <CampoTexto
                        v-model="form.inscripcion_hasta"
                        etiqueta="Inscripción hasta"
                        tipo="date"
                        :error="form.errors.inscripcion_hasta"
                    />
                    <CampoTexto
                        v-model="form.altas_bajas_hasta"
                        etiqueta="Altas y bajas hasta"
                        tipo="date"
                        :error="form.errors.altas_bajas_hasta"
                    />
                    <CampoTexto
                        v-model="form.captura_calif_hasta"
                        etiqueta="Captura de calificaciones hasta"
                        tipo="date"
                        :error="form.errors.captura_calif_hasta"
                    />
                </div>
            </section>

            <div class="flex items-center gap-3">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-60"
                >
                    {{ form.processing ? 'Guardando…' : esEdicion ? 'Guardar cambios' : 'Crear ciclo' }}
                </button>
                <a
                    href="/escolar/ciclos"
                    class="rounded-lg border border-slate-300 px-5 py-2.5 text-sm text-slate-700 hover:bg-slate-50"
                >
                    Cancelar
                </a>
            </div>
        </form>
    </AppLayout>
</template>
