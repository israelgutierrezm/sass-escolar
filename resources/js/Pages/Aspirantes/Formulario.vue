<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import CamposIdentidad from '@/Components/CamposIdentidad.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import CampoTexto from '@/Components/CampoTexto.vue';

interface Opcion {
    id: number;
    nombre: string;
}

interface AspiranteEditable {
    id: number;
    persona_id: number;
    nombre: string;
    primer_apellido: string;
    segundo_apellido: string | null;
    curp: string | null;
    fecha_nacimiento: string | null;
    genero_id: number | null;
    entidad_nacimiento_id: number | null;
    pais_nacimiento_id: number | null;
    email: string | null;
    celular: string | null;
    oferta_interes_id: number | null;
    campus_id: number | null;
    situacion_id: number | null;
    origen_id: number | null;
    origen: string | null;
}

const props = defineProps<{
    aspirante: AspiranteEditable | null;
    generos: Opcion[];
    entidades: Opcion[];
    entidadExtranjero: Opcion | null;
    paises: Opcion[];
    situaciones: Opcion[];
    origenes: Opcion[];
    campus: Opcion[];
    ofertas: { id: number; etiqueta: string }[];
}>();

const esEdicion = computed(() => props.aspirante !== null);

const form = useForm({
    nombre: props.aspirante?.nombre ?? '',
    primer_apellido: props.aspirante?.primer_apellido ?? '',
    segundo_apellido: props.aspirante?.segundo_apellido ?? '',
    curp: props.aspirante?.curp ?? '',
    fecha_nacimiento: props.aspirante?.fecha_nacimiento ?? '',
    genero_id: props.aspirante?.genero_id ?? null,
    entidad_nacimiento_id: props.aspirante?.entidad_nacimiento_id ?? null,
    pais_nacimiento_id: props.aspirante?.pais_nacimiento_id ?? null,
    email: props.aspirante?.email ?? '',
    celular: props.aspirante?.celular ?? '',
    oferta_interes_id: props.aspirante?.oferta_interes_id ?? null,
    campus_id: props.aspirante?.campus_id ?? null,
    situacion_id: props.aspirante?.situacion_id ?? props.situaciones[0]?.id ?? null,
    origen_id: props.aspirante?.origen_id ?? null,
    origen: props.aspirante?.origen ?? '',
});

function enviar(): void {
    if (esEdicion.value) {
        form.put(`/aspirantes/${props.aspirante!.id}`);

        return;
    }

    form.post('/aspirantes');
}
</script>

<template>
    <Head :title="esEdicion ? 'Editar aspirante' : 'Nuevo aspirante'" />

    <AppLayout :titulo="esEdicion ? 'Editar aspirante' : 'Nuevo aspirante'">
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
                        :persona-id="aspirante?.persona_id ?? null"
                        correo-requerido
                    />
                </div>
            </section>

            <section class="tarjeta p-6">
                <h2 class="text-base font-semibold">Proceso de admisión</h2>
                <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                    La matrícula NO se genera aquí: se asigna al convertirlo en alumno.
                </p>

                <div class="mt-5 grid gap-4 sm:grid-cols-2">
                    <CampoSelect
                        v-model="form.oferta_interes_id"
                        etiqueta="Oferta de interés"
                        vacio="Sin definir"
                        :opciones="ofertas.map((o) => ({ valor: o.id, texto: o.etiqueta }))"
                        :error="form.errors.oferta_interes_id"
                        :ayuda="ofertas.length ? undefined : 'No hay ofertas abiertas registradas todavía.'"
                    />

                    <CampoSelect
                        v-model="form.campus_id"
                        etiqueta="Campus"
                        vacio="Sin definir"
                        :opciones="campus.map((c) => ({ valor: c.id, texto: c.nombre }))"
                        :error="form.errors.campus_id"
                    />

                    <CampoSelect
                        v-model="form.situacion_id"
                        etiqueta="Situación"
                        requerido
                        :opciones="situaciones.map((s) => ({ valor: s.id, texto: s.nombre }))"
                        :error="form.errors.situacion_id"
                    />

                    <div>
                        <CampoSelect
                            v-model="form.origen_id"
                            etiqueta="Cómo llegó"
                            vacio="Sin especificar"
                            :opciones="origenes.map((o) => ({ valor: o.id, texto: o.nombre }))"
                            :error="form.errors.origen_id"
                        />
                        <!-- El texto libre se conserva para no perder lo ya
                             capturado, pero deja de ser lo principal: el
                             catálogo es lo que el CRM sabe contar. -->
                        <CampoTexto
                            v-model="form.origen"
                            etiqueta=""
                            marcador="Detalle (campaña, quién refirió…)"
                            class="mt-2"
                        />
                    </div>
                </div>

                <!-- Los términos NO se aceptan desde aquí. Consentir el proceso
                     de admisión es un acto del interesado; quien captura no
                     puede hacerlo en su nombre. Lo firma el aspirante en su
                     portal. -->
                <p class="mt-4 text-xs" :style="{ color: 'var(--color-suave)' }">
                    Los términos del proceso los acepta el propio aspirante desde su portal; no pueden
                    aceptarse por él desde aquí.
                </p>
            </section>

            <div class="flex items-center gap-3">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="rounded-lg px-5 py-2.5 text-sm font-medium disabled:cursor-not-allowed disabled:opacity-60"
                    :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                >
                    {{ form.processing ? 'Guardando…' : esEdicion ? 'Guardar cambios' : 'Registrar aspirante' }}
                </button>
                <a
                    href="/aspirantes"
                    class="rounded-lg border px-5 py-2.5 text-sm"
                    :style="{ borderColor: 'var(--color-borde)' }"
                >
                    Cancelar
                </a>
            </div>
        </form>
    </AppLayout>
</template>
