<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoCheckbox from '@/Components/CampoCheckbox.vue';

interface Formulario {
    id: number;
    clave: string;
    titulo: string;
    version: number;
    obligatorio: boolean;
    orden: number;
    campos: number;
    asignaciones: number;
    respuestas: number;
    es_ultima: boolean;
}

const props = defineProps<{ formularios: Formulario[]; puedeEditar: boolean }>();

const creando = ref(false);

const form = useForm({ clave: '', titulo: '', instruccion: '', obligatorio: false, orden: 0 });

function crear(): void {
    form.post('/formularios', { onSuccess: () => { form.reset(); creando.value = false; } });
}

function eliminar(f: Formulario): void {
    if (!confirm(`Eliminar "${f.titulo}" v${f.version}?`)) return;
    router.delete(`/formularios/${f.id}`);
}

/** Agrupados por clave: las versiones de un mismo formulario van juntas. */
const porClave = computed(() => {
    const grupos = new Map<string, Formulario[]>();

    props.formularios.forEach((f) => grupos.set(f.clave, [...(grupos.get(f.clave) ?? []), f]));

    return [...grupos.entries()].map(([clave, versiones]) => ({ clave, versiones }));
});
</script>

<template>
    <Head title="Formularios" />

    <AppLayout titulo="Formularios">
        <section class="tarjeta p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="max-w-2xl">
                    <h2 class="text-base font-semibold">Qué datos pide la escuela</h2>
                    <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                        Bloques de preguntas que se le muestran a un rol, a una carrera o a una oferta.
                        Un formulario que ya tiene respuestas se congela: para cambiarlo se publica una
                        versión nueva, y la anterior conserva lo que la gente contestó.
                    </p>
                </div>

                <button
                    v-if="puedeEditar && !creando"
                    type="button"
                    class="rounded-lg px-4 py-2 text-sm font-medium"
                    :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                    @click="creando = true"
                >
                    Nuevo formulario
                </button>
            </div>

            <form v-if="creando" class="mt-5 border-t pt-5" :style="{ borderColor: 'var(--color-borde)' }" @submit.prevent="crear">
                <div class="grid gap-4 sm:grid-cols-2">
                    <CampoTexto
                        v-model="form.clave"
                        etiqueta="Clave"
                        requerido
                        mono
                        marcador="datos_generales"
                        :error="form.errors.clave"
                        ayuda="Identifica al formulario a través de sus versiones. No se puede cambiar después."
                    />
                    <CampoTexto v-model="form.titulo" etiqueta="Título" requerido :error="form.errors.titulo" />
                </div>
                <div class="mt-4">
                    <CampoTexto
                        v-model="form.instruccion"
                        etiqueta="Instrucción"
                        :error="form.errors.instruccion"
                        ayuda="Lo que se lee arriba del formulario al llenarlo."
                    />
                </div>
                <div class="mt-4">
                    <CampoCheckbox v-model="form.obligatorio" etiqueta="Obligatorio" ayuda="Sin él, el expediente queda incompleto." />
                </div>
                <div class="mt-4 flex gap-2">
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="rounded-lg px-4 py-2 text-sm font-medium disabled:opacity-50"
                        :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                    >
                        Crear
                    </button>
                    <button
                        type="button"
                        class="rounded-lg border px-4 py-2 text-sm"
                        :style="{ borderColor: 'var(--color-borde)' }"
                        @click="creando = false"
                    >
                        Cancelar
                    </button>
                </div>
            </form>
        </section>

        <section v-for="grupo in porClave" :key="grupo.clave" class="tarjeta overflow-hidden">
            <div class="border-b px-6 py-3" :style="{ borderColor: 'var(--color-borde)' }">
                <h2 class="font-mono text-sm">{{ grupo.clave }}</h2>
            </div>

            <ul>
                <li
                    v-for="f in grupo.versiones"
                    :key="f.id"
                    class="flex flex-wrap items-center justify-between gap-3 border-t px-6 py-3 text-sm"
                    :class="f.es_ultima ? '' : 'opacity-60'"
                    :style="{ borderColor: 'var(--color-borde)' }"
                >
                    <div>
                        <span class="font-medium">{{ f.titulo }}</span>
                        <span class="ml-2 rounded-full px-2 py-0.5 text-xs" style="background-color: color-mix(in srgb, currentColor 10%, transparent)">
                            v{{ f.version }}
                        </span>
                        <span v-if="!f.es_ultima" class="ml-1 text-xs" :style="{ color: 'var(--color-suave)' }">
                            (versión anterior)
                        </span>
                        <span v-if="f.obligatorio" class="ml-1 rounded-full px-2 py-0.5 text-xs" style="background-color: color-mix(in srgb, #dc2626 14%, transparent)">
                            obligatorio
                        </span>
                        <p class="mt-0.5 text-xs" :style="{ color: 'var(--color-suave)' }">
                            {{ f.campos }} campos · {{ f.asignaciones }} asignaciones ·
                            <span :class="f.respuestas ? 'text-amber-600' : ''">
                                {{ f.respuestas }} respuestas{{ f.respuestas ? ' (congelado)' : '' }}
                            </span>
                        </p>
                    </div>

                    <div class="flex items-center gap-3">
                        <a :href="`/formularios/${f.id}`" class="text-sm font-medium" :style="{ color: 'var(--color-acento)' }">
                            {{ f.respuestas ? 'Ver' : 'Editar' }}
                        </a>
                        <button
                            v-if="puedeEditar && !f.respuestas"
                            type="button"
                            class="text-sm transition hover:text-red-600"
                            :style="{ color: 'var(--color-suave)' }"
                            @click="eliminar(f)"
                        >
                            Eliminar
                        </button>
                    </div>
                </li>
            </ul>
        </section>

        <p v-if="!formularios.length" class="tarjeta px-6 py-12 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
            Todavía no hay formularios.
        </p>
    </AppLayout>
</template>
