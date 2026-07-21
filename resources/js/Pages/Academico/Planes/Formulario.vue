<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import NavAcademico from '@/Components/NavAcademico.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import CampoCheckbox from '@/Components/CampoCheckbox.vue';

const props = defineProps<{
    plan: Record<string, any> | null;
    carreras: { id: number; nombre: string }[];
    autorizaciones: { id: number; nombre: string }[];
    tiposPeriodo: { id: number; nombre: string }[];
}>();

const esEdicion = computed(() => props.plan !== null);

const form = useForm({
    carrera_id: props.plan?.carrera_id ?? null,
    clave: props.plan?.clave ?? '',
    abreviacion: props.plan?.abreviacion ?? '',
    nombre: props.plan?.nombre ?? '',
    rvoe: props.plan?.rvoe ?? '',
    fecha_rvoe: props.plan?.fecha_rvoe ?? '',
    autorizacion_reconocimiento_id: props.plan?.autorizacion_reconocimiento_id ?? null,
    tipo_periodo_id: props.plan?.tipo_periodo_id ?? null,
    total_periodos: props.plan?.total_periodos ?? null,
    calificacion_minima: props.plan?.calificacion_minima ?? 0,
    calificacion_maxima: props.plan?.calificacion_maxima ?? 10,
    calificacion_minima_aprobatoria: props.plan?.calificacion_minima_aprobatoria ?? 6,
    minimo_creditos: props.plan?.minimo_creditos ?? null,
    minimo_asignaturas: props.plan?.minimo_asignaturas ?? null,
    total_creditos: props.plan?.total_creditos ?? null,
    curp_responsable: props.plan?.curp_responsable ?? '',
    vigente: props.plan?.vigente ?? true,
});

const opciones = (lista: { id: number; nombre: string }[]) =>
    lista.map((item) => ({ valor: item.id, texto: item.nombre }));

function enviar(): void {
    esEdicion.value ? form.put(`/academico/planes/${props.plan!.id}`) : form.post('/academico/planes');
}
</script>

<template>
    <Head :title="esEdicion ? 'Editar plan' : 'Nuevo plan'" />

    <AppLayout :titulo="esEdicion ? 'Editar plan de estudios' : 'Nuevo plan de estudios'">
        <NavAcademico />

        <form class="max-w-4xl space-y-6" @submit.prevent="enviar">
            <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
                <h2 class="text-base font-semibold text-slate-800">Identificación</h2>

                <div class="mt-5 grid gap-4 sm:grid-cols-2">
                    <CampoSelect
                        v-model="form.carrera_id"
                        etiqueta="Carrera"
                        requerido
                        :opciones="opciones(carreras)"
                        vacio="Selecciona…"
                        :error="form.errors.carrera_id"
                    />
                    <CampoTexto v-model="form.clave" etiqueta="Clave" requerido :error="form.errors.clave" mono />
                    <CampoTexto v-model="form.nombre" etiqueta="Nombre" requerido :error="form.errors.nombre" />
                    <CampoTexto
                        v-model="form.abreviacion"
                        etiqueta="Abreviación"
                        :error="form.errors.abreviacion"
                        ayuda="Se imprime en el título."
                    />
                </div>
            </section>

            <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
                <h2 class="text-base font-semibold text-slate-800">Reconocimiento oficial</h2>
                <p class="mt-1 text-sm text-slate-500">Datos que exige la SEP para el título electrónico.</p>

                <div class="mt-5 grid gap-4 sm:grid-cols-2">
                    <CampoTexto v-model="form.rvoe" etiqueta="RVOE" requerido :error="form.errors.rvoe" mono />
                    <CampoTexto
                        v-model="form.fecha_rvoe"
                        etiqueta="Fecha de RVOE"
                        tipo="date"
                        :error="form.errors.fecha_rvoe"
                    />
                    <CampoSelect
                        v-model="form.autorizacion_reconocimiento_id"
                        etiqueta="Tipo de autorización"
                        requerido
                        :opciones="opciones(autorizaciones)"
                        vacio="Selecciona…"
                        :error="form.errors.autorizacion_reconocimiento_id"
                    />
                    <CampoTexto
                        v-model="form.curp_responsable"
                        etiqueta="CURP del responsable"
                        :error="form.errors.curp_responsable"
                        mono
                        :maximo="18"
                    />
                </div>
            </section>

            <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
                <h2 class="text-base font-semibold text-slate-800">Reglas académicas</h2>

                <div class="mt-5 grid gap-4 sm:grid-cols-3">
                    <CampoSelect
                        v-model="form.tipo_periodo_id"
                        etiqueta="Tipo de periodo"
                        requerido
                        :opciones="opciones(tiposPeriodo)"
                        vacio="Selecciona…"
                        :error="form.errors.tipo_periodo_id"
                    />
                    <CampoTexto
                        v-model="form.total_periodos"
                        etiqueta="Total de periodos"
                        tipo="number"
                        :error="form.errors.total_periodos"
                    />
                    <div></div>

                    <CampoTexto
                        v-model="form.calificacion_minima"
                        etiqueta="Calificación mínima"
                        tipo="number"
                        requerido
                        :error="form.errors.calificacion_minima"
                    />
                    <CampoTexto
                        v-model="form.calificacion_maxima"
                        etiqueta="Calificación máxima"
                        tipo="number"
                        requerido
                        :error="form.errors.calificacion_maxima"
                    />
                    <CampoTexto
                        v-model="form.calificacion_minima_aprobatoria"
                        etiqueta="Mínima aprobatoria"
                        tipo="number"
                        requerido
                        :error="form.errors.calificacion_minima_aprobatoria"
                    />

                    <CampoTexto
                        v-model="form.minimo_creditos"
                        etiqueta="Créditos para titularse"
                        tipo="number"
                        requerido
                        :error="form.errors.minimo_creditos"
                    />
                    <CampoTexto
                        v-model="form.total_creditos"
                        etiqueta="Créditos totales del plan"
                        tipo="number"
                        requerido
                        :error="form.errors.total_creditos"
                    />
                    <CampoTexto
                        v-model="form.minimo_asignaturas"
                        etiqueta="Mínimo de asignaturas"
                        tipo="number"
                        :error="form.errors.minimo_asignaturas"
                    />
                </div>

                <div class="mt-5 border-t border-slate-100 pt-4">
                    <CampoCheckbox
                        v-model="form.vigente"
                        etiqueta="Plan vigente"
                        ayuda="Los no vigentes siguen vivos para quienes los cursan, pero no reciben alumnos nuevos."
                    />
                </div>
            </section>

            <div class="flex items-center gap-3">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-60"
                >
                    {{ form.processing ? 'Guardando…' : esEdicion ? 'Guardar cambios' : 'Crear plan' }}
                </button>
                <a
                    href="/academico/planes"
                    class="rounded-lg border border-slate-300 px-5 py-2.5 text-sm text-slate-700 hover:bg-slate-50"
                >
                    Cancelar
                </a>
            </div>
        </form>
    </AppLayout>
</template>
