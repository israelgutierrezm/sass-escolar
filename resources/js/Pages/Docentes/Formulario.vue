<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import CampoCasillas from '@/Components/CampoCasillas.vue';
import CamposIdentidad from '@/Components/CamposIdentidad.vue';

const props = defineProps<{
    docente: Record<string, any> | null;
    situaciones: { id: number; nombre: string }[];
    tipos: { id: number; nombre: string }[];
    campus: { id: number; nombre: string }[];
    generos: { id: number; nombre: string }[];
    entidades: { id: number; nombre: string }[];
    entidadExtranjero: { id: number; nombre: string } | null;
    paises: { id: number; nombre: string }[];
    mexicoId: number | null;
}>();

const form = useForm({
    nombre: '',
    primer_apellido: '',
    segundo_apellido: '',
    curp: '',
    rfc: '',
    fecha_nacimiento: '',
    genero_id: null as number | null,
    entidad_nacimiento_id: null as number | null,
    pais_nacimiento_id: null as number | null,
    email: '',
    correo_institucional: '',
    celular: '',
    telefono_local: '',
    clave_profesor: '',
    tipo_docente_id: null as number | null,
    situacion_id: props.situaciones[0]?.id ?? null,
    edicion_contenido: 1,
    campus_ids: [] as number[],
});

function enviar(): void {
    form.post('/escolar/docentes');
}
</script>

<template>
    <Head title="Nuevo docente" />

    <AppLayout titulo="Nuevo docente">

        <form class="space-y-6" @submit.prevent="enviar">
            <section class="tarjeta p-6">
                <h2 class="text-base font-semibold">Datos de la persona</h2>
                <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                    Si la CURP ya está registrada, se reutiliza esa persona en lugar de duplicarla.
                </p>

                <div class="mt-5">
                    <CamposIdentidad
                        :form="form"
                        :generos="generos"
                        :entidades="entidades"
                        :entidad-extranjero="entidadExtranjero"
                        :paises="paises"
                        :mexico-id="mexicoId"
                        :persona-id="null"
                        con-rfc
                    />
                </div>
            </section>

            <section class="tarjeta p-6">
                <h2 class="text-base font-semibold">Registro docente</h2>

                <div class="mt-5 grid gap-4 sm:grid-cols-3">
                    <CampoTexto v-model="form.clave_profesor" etiqueta="Clave de profesor" mono :error="form.errors.clave_profesor" />
                    <CampoSelect
                        v-model="form.tipo_docente_id"
                        etiqueta="Tipo de docente"
                        :opciones="tipos.map((t) => ({ valor: t.id, texto: t.nombre }))"
                        vacio="Sin especificar"
                        :error="form.errors.tipo_docente_id"
                    />
                    <CampoSelect
                        v-model="form.situacion_id"
                        etiqueta="Situación"
                        requerido
                        :opciones="situaciones.map((s) => ({ valor: s.id, texto: s.nombre }))"
                        :error="form.errors.situacion_id"
                    />
                    <CampoSelect
                        v-model="form.edicion_contenido"
                        etiqueta="Edición de contenido"
                        :opciones="[
                            { valor: 0, texto: 'Ninguna' },
                            { valor: 1, texto: 'Solo sus grupos' },
                            { valor: 2, texto: 'Todos los grupos' },
                        ]"
                        :error="form.errors.edicion_contenido"
                        ayuda="Alcance en el LMS."
                    />
                </div>

                <div class="mt-5">
                    <CampoCasillas
                        v-model="form.campus_ids"
                        etiqueta="Campus donde imparte"
                        :opciones="campus.map((c) => ({ valor: c.id, texto: c.nombre }))"
                        :error="form.errors.campus_ids"
                        vacio="No hay campus dados de alta."
                    />
                </div>
            </section>

            <div class="flex items-center gap-3">
                <BotonPrincipal :procesando="form.processing" texto="Dar de alta" />
                <a
                    href="/escolar/docentes"
                    class="rounded-lg border px-5 py-2.5 text-sm"
                    :style="{ borderColor: 'var(--color-borde)' }"
                >
                    Cancelar
                </a>
            </div>
        </form>
    </AppLayout>
</template>
