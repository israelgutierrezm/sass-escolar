<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import PestanasSeccion from '@/Components/PestanasSeccion.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';
import { ICONOS } from '@/iconos';

const props = defineProps<{
    plan: Record<string, any> | null;
    programas_academicos: { id: number; nombre: string }[];
    autorizaciones: { id: number; nombre: string }[];
    tiposPeriodo: { id: number; nombre: string }[];
}>();

const esEdicion = computed(() => props.plan !== null);

const form = useForm({
    programa_academico_id: props.plan?.programa_academico_id ?? null,
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
        <PestanasSeccion />

        <form class="space-y-6" @submit.prevent="enviar">
            <TarjetaSeccion titulo="Identificación" descripcion="A qué programa académico pertenece y cómo se identifica." :icono="ICONOS.birrete">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <CampoSelect
                        v-model="form.programa_academico_id"
                        etiqueta="Programa académico"
                        requerido
                        :opciones="opciones(programas_academicos)"
                        vacio="Selecciona…"
                        :error="form.errors.programa_academico_id"
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
            </TarjetaSeccion>

            <TarjetaSeccion titulo="Reconocimiento oficial" descripcion="Datos que exige la SEP para el título electrónico." :icono="ICONOS.escudo">
                <!-- Tres columnas para tres campos: eran cuatro cuando aquí
                     vivía también el CURP del responsable, y al retirarlo quedó
                     un hueco al final del renglón. -->
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <CampoTexto v-model="form.rvoe" etiqueta="RVOE" requerido :error="form.errors.rvoe" mono />
                    <CampoTexto
                        v-model="form.fecha_rvoe"
                        etiqueta="Fecha de RVOE"
                        tipo="date"
                        :error="form.errors.fecha_rvoe"
                    />
                    <CampoSelect
                        v-model="form.autorizacion_reconocimiento_id"
                        etiqueta="Autorización o Reconocimiento"
                        requerido
                        :opciones="opciones(autorizaciones)"
                        vacio="Selecciona…"
                        :error="form.errors.autorizacion_reconocimiento_id"
                    />
                </div>
            </TarjetaSeccion>

            <TarjetaSeccion titulo="Reglas académicas" descripcion="Periodos y escala de calificación del plan." :icono="ICONOS.ajustes">
                <div class="grid gap-4 sm:grid-cols-3">
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

                    <CampoTexto
                        v-model="form.calificacion_minima"
                        etiqueta="Calificación mínima"
                        tipo="number"
                        requerido
                        :error="form.errors.calificacion_minima"
                    />
                    <CampoTexto
                        v-model="form.calificacion_maxima"
                        etiqueta="Calificación máxima asignable"
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
                        etiqueta="Créditos para completar el plan"
                        tipo="number"
                        requerido
                        :error="form.errors.minimo_creditos"
                    />
                    <CampoTexto
                        v-model="form.minimo_asignaturas"
                        etiqueta="Materias para completar el plan"
                        tipo="number"
                        :error="form.errors.minimo_asignaturas"
                    />
                </div>
            </TarjetaSeccion>

            <div class="flex items-center gap-3">
                <BotonPrincipal :procesando="form.processing" :texto="esEdicion ? 'Guardar cambios' : 'Crear plan'" />
                <a
                    href="/academico/planes"
                    class="rounded-lg border border-borde px-5 py-2.5 text-sm text-contenido hover:bg-fondo"
                >
                    Cancelar
                </a>
            </div>
        </form>
    </AppLayout>
</template>
