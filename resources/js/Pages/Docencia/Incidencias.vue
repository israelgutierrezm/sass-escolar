<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoTextarea from '@/Components/CampoTextarea.vue';
import Modal from '@/Components/Modal.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';
import { hoyLocal } from '@/utils/fechas';

interface Alumno {
    matricula_oferta_id: number;
    matricula: string | null;
    nombre: string | null;
    grupo: string | null;
}

const props = defineProps<{
    alumnos: Alumno[];
    tipos: { id: number; nombre: string }[];
    mias: { id: number; alumno: string | null; tipo: string | null; fecha: string | null; descripcion: string }[];
}>();

const registrando = ref(false);

const form = useForm({
    matricula_oferta_id: null as number | null,
    tipo_incidencia_id: null as number | null,
    fecha: hoyLocal(),
    descripcion: '',
});

function abrir(): void {
    form.reset();
    form.fecha = hoyLocal();
    form.clearErrors();
    registrando.value = true;
}

function guardar(): void {
    form.post('/docencia/incidencias', {
        preserveScroll: true,
        onSuccess: () => {
            registrando.value = false;
            form.reset();
        },
    });
}
</script>

<template>
    <Head title="Incidencias" />

    <AppLayout titulo="Incidencias">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <p class="max-w-2xl text-sm" :style="{ color: 'var(--color-suave)' }">
                Levanta una incidencia de conducta de un alumno de tus grupos. Control escolar decide
                si amerita una sanción.
            </p>

            <button
                v-if="alumnos.length"
                type="button"
                class="rounded-lg px-4 py-2 text-sm font-medium"
                :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                @click="abrir"
            >
                Levantar incidencia
            </button>
        </div>

        <TarjetaSeccion titulo="Las que he levantado" sin-relleno>
            <ul v-if="mias.length">
                <li
                    v-for="i in mias"
                    :key="i.id"
                    class="border-t px-6 py-3 text-sm"
                    :style="{ borderColor: 'var(--color-borde)' }"
                >
                    <p class="font-medium">{{ i.alumno }} <span class="text-xs font-normal" :style="{ color: 'var(--color-suave)' }">· {{ i.tipo }} · {{ i.fecha }}</span></p>
                    <p class="mt-0.5 whitespace-pre-line">{{ i.descripcion }}</p>
                </li>
            </ul>
            <p v-else class="px-6 py-8 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                <template v-if="alumnos.length">Todavía no has levantado ninguna incidencia.</template>
                <template v-else>No tienes alumnos en este momento.</template>
            </p>
        </TarjetaSeccion>

        <Modal v-if="registrando" etiqueta="Levantar incidencia" :formulario="form" @cerrar="registrando = false">
            <template #default="{ cerrar }">
                <form class="space-y-4 p-6" @submit.prevent="guardar">
                    <h2 class="text-base font-semibold">Levantar incidencia</h2>

                    <!-- Sólo TUS alumnos, no un buscador del padrón. -->
                    <CampoSelect
                        v-model="form.matricula_oferta_id"
                        etiqueta="Alumno"
                        :opciones="alumnos.map((a) => ({ valor: a.matricula_oferta_id, texto: `${a.nombre} · ${a.matricula}${a.grupo ? ' · ' + a.grupo : ''}` }))"
                        vacio="Elige a un alumno…"
                        :error="form.errors.matricula_oferta_id"
                    />

                    <div class="grid gap-4 sm:grid-cols-2">
                        <CampoSelect
                            v-model="form.tipo_incidencia_id"
                            etiqueta="Tipo"
                            :opciones="tipos.map((t) => ({ valor: t.id, texto: t.nombre }))"
                            vacio="Selecciona…"
                            :error="form.errors.tipo_incidencia_id"
                        />
                        <CampoTexto v-model="form.fecha" etiqueta="¿Cuándo ocurrió?" tipo="date" requerido :error="form.errors.fecha" />
                    </div>

                    <CampoTextarea
                        v-model="form.descripcion"
                        etiqueta="Descripción"
                        :filas="3"
                        ayuda="Qué pasó, con el detalle que haga falta."
                        :error="form.errors.descripcion"
                    />

                    <div class="flex items-center gap-3 pt-2">
                        <BotonPrincipal :procesando="form.processing" texto="Levantar" icono="crear" />
                        <button type="button" class="rounded-lg border border-borde px-4 py-2 text-sm" @click="cerrar">
                            Cancelar
                        </button>
                    </div>
                </form>
            </template>
        </Modal>
    </AppLayout>
</template>
