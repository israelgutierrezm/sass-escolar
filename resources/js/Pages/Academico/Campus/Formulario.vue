<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import NavAcademico from '@/Components/NavAcademico.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoSelect from '@/Components/CampoSelect.vue';

const props = defineProps<{
    campus: Record<string, any> | null;
    tiposCampus: { id: number; nombre: string }[];
    entidades: { id: number; nombre: string }[];
    instituciones: { id: number; nombre: string }[];
}>();

const esEdicion = computed(() => props.campus !== null);

const form = useForm({
    clave: props.campus?.clave ?? '',
    nombre: props.campus?.nombre ?? '',
    // Si solo hay una institución, se preselecciona: con una sola no hay
    // decisión que tomar y obligar a elegirla es un clic vacío.
    institucion_id: props.campus?.institucion_id ?? (props.instituciones.length === 1 ? props.instituciones[0].id : null),
    tipo_campus_id: props.campus?.tipo_campus_id ?? null,
    entidad_id: props.campus?.entidad_id ?? null,
});

const opcionesTipo = computed(() => props.tiposCampus.map((t) => ({ valor: t.id, texto: t.nombre })));
const opcionesEntidad = computed(() => props.entidades.map((e) => ({ valor: e.id, texto: e.nombre })));
const opcionesInstitucion = computed(() => props.instituciones.map((i) => ({ valor: i.id, texto: i.nombre })));

function enviar(): void {
    esEdicion.value ? form.put(`/academico/campus/${props.campus!.id}`) : form.post('/academico/campus');
}
</script>

<template>
    <Head :title="esEdicion ? 'Editar campus' : 'Nuevo campus'" />

    <AppLayout :titulo="esEdicion ? 'Editar campus' : 'Nuevo campus'">
        <NavAcademico />

        <form class="max-w-3xl space-y-6" @submit.prevent="enviar">
            <section class="tarjeta grid gap-4 p-6 sm:grid-cols-2">
                <CampoTexto v-model="form.clave" etiqueta="Clave" requerido :error="form.errors.clave" mono />
                <CampoTexto v-model="form.nombre" etiqueta="Nombre" requerido :error="form.errors.nombre" />
                <CampoSelect
                    v-model="form.tipo_campus_id"
                    etiqueta="Tipo de campus"
                    :opciones="opcionesTipo"
                    vacio="Sin especificar"
                    :error="form.errors.tipo_campus_id"
                    ayuda="Opcional: clasifica el plantel (matriz, extensión, en línea…)."
                />
                <CampoSelect
                    v-model="form.entidad_id"
                    etiqueta="Entidad federativa"
                    requerido
                    :opciones="opcionesEntidad"
                    vacio="Selecciona…"
                    :error="form.errors.entidad_id"
                    ayuda="Dónde está el plantel. Catálogo compartido entre escuelas."
                />
                <CampoSelect
                    v-model="form.institucion_id"
                    etiqueta="Institución"
                    :opciones="opcionesInstitucion"
                    vacio="Sin especificar"
                    :error="form.errors.institucion_id"
                    ayuda="Informativo: la persona moral a la que pertenece este plantel."
                />
            </section>

            <div class="flex items-center gap-3">
                <BotonPrincipal :procesando="form.processing" :texto="esEdicion ? 'Guardar cambios' : 'Crear campus'" />
                <a
                    href="/academico/campus"
                    class="rounded-lg border border-borde px-5 py-2.5 text-sm text-contenido hover:bg-fondo"
                >
                    Cancelar
                </a>
            </div>
        </form>
    </AppLayout>
</template>
