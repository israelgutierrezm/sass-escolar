<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import CampoCasillas from '@/Components/CampoCasillas.vue';

const props = defineProps<{
    docente: Record<string, any> | null;
    situaciones: { id: number; nombre: string }[];
    tipos: { id: number; nombre: string }[];
    campus: { id: number; nombre: string }[];
    sexos: { id: number; nombre: string }[];
    generos: { id: number; nombre: string }[];
}>();

const form = useForm({
    nombre: '',
    primer_apellido: '',
    segundo_apellido: '',
    curp: '',
    rfc: '',
    fecha_nacimiento: '',
    sexo_id: null as number | null,
    genero_id: null as number | null,
    email: '',
    correo_institucional: '',
    celular: '',
    clave_profesor: '',
    cedula_profesional: '',
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

        <form class="max-w-4xl space-y-6" @submit.prevent="enviar">
            <section class="tarjeta p-6">
                <h2 class="text-base font-semibold">Identidad</h2>
                <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                    Si la CURP ya está registrada —fue alumno, es tutor o ya estuvo dado de alta— se
                    reutiliza esa persona y solo se le crea el registro docente. No se duplica gente.
                </p>

                <div class="mt-5 grid gap-4 sm:grid-cols-3">
                    <CampoTexto v-model="form.nombre" etiqueta="Nombre(s)" requerido :error="form.errors.nombre" />
                    <CampoTexto v-model="form.primer_apellido" etiqueta="Primer apellido" requerido :error="form.errors.primer_apellido" />
                    <CampoTexto v-model="form.segundo_apellido" etiqueta="Segundo apellido" :error="form.errors.segundo_apellido" />

                    <CampoTexto v-model="form.curp" etiqueta="CURP" mono :error="form.errors.curp" maximo="18" />
                    <CampoTexto v-model="form.rfc" etiqueta="RFC" mono :error="form.errors.rfc" />
                    <CampoTexto v-model="form.fecha_nacimiento" etiqueta="Fecha de nacimiento" tipo="date" :error="form.errors.fecha_nacimiento" />

                    <CampoSelect
                        v-model="form.sexo_id"
                        etiqueta="Sexo"
                        requerido
                        :opciones="sexos.map((s) => ({ valor: s.id, texto: s.nombre }))"
                        vacio="Selecciona…"
                        :error="form.errors.sexo_id"
                    />
                    <CampoSelect
                        v-model="form.genero_id"
                        etiqueta="Género"
                        :opciones="generos.map((g) => ({ valor: g.id, texto: g.nombre }))"
                        vacio="Sin especificar"
                        :error="form.errors.genero_id"
                    />
                    <div></div>

                    <CampoTexto v-model="form.email" etiqueta="Correo personal" tipo="email" :error="form.errors.email" />
                    <CampoTexto v-model="form.correo_institucional" etiqueta="Correo institucional" tipo="email" :error="form.errors.correo_institucional" />
                    <CampoTexto v-model="form.celular" etiqueta="Celular" :error="form.errors.celular" />
                </div>
            </section>

            <section class="tarjeta p-6">
                <h2 class="text-base font-semibold">Registro docente</h2>

                <div class="mt-5 grid gap-4 sm:grid-cols-3">
                    <CampoTexto v-model="form.clave_profesor" etiqueta="Clave de profesor" mono :error="form.errors.clave_profesor" />
                    <CampoTexto v-model="form.cedula_profesional" etiqueta="Cédula profesional" mono :error="form.errors.cedula_profesional" />
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
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="rounded-lg px-5 py-2.5 text-sm font-medium disabled:opacity-60"
                    :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                >
                    {{ form.processing ? 'Guardando…' : 'Dar de alta' }}
                </button>
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
