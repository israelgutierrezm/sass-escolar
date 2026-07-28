<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import NavAcademico from '@/Components/NavAcademico.vue';

interface Institucion {
    id: number;
    clave: string;
    nombre: string;
    logo: string | null;
    campus_count: number;
}

const props = defineProps<{
    institucion: Institucion | null;
    puedeEditar: boolean;
}>();

// Edición directa: los campos viven en un form que se envía al guardar. El logo
// se sube al vuelo (multipart) contra el mismo endpoint de actualización.
const form = useForm<{ clave: string; nombre: string; logo: File | null }>({
    clave: props.institucion?.clave ?? '',
    nombre: props.institucion?.nombre ?? '',
    logo: null,
});

const vistaPrevia = ref<string | null>(props.institucion?.logo ?? null);
const entradaLogo = ref<HTMLInputElement | null>(null);

function elegirLogo(evento: Event): void {
    const archivo = (evento.target as HTMLInputElement).files?.[0];
    if (!archivo) {
        return;
    }
    form.logo = archivo;
    vistaPrevia.value = URL.createObjectURL(archivo);
}

function guardar(): void {
    if (!props.institucion) {
        return;
    }
    // `useForm` con un File hace multipart automáticamente; se usa POST + _method
    // para que PHP reciba bien el archivo en un PUT.
    form
        .transform((datos) => ({ ...datos, _method: 'put' }))
        .post(`/academico/instituciones/${props.institucion.id}`, {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => (form.logo = null),
        });
}
</script>

<template>
    <Head title="Institución" />

    <AppLayout titulo="Catálogo académico">
        <NavAcademico />

        <!-- Alta: solo si aún no existe. -->
        <div
            v-if="!institucion"
            class="tarjeta flex flex-col items-center gap-4 px-6 py-14 text-center"
        >
            <div class="grid h-20 w-20 place-items-center rounded-2xl" :style="{ backgroundColor: 'var(--color-fondo)', color: 'var(--color-suave)' }">
                <svg class="h-9 w-9" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21" />
                </svg>
            </div>
            <div>
                <h2 class="text-base font-semibold">Aún no hay institución registrada</h2>
                <p class="mt-1 max-w-md text-sm" :style="{ color: 'var(--color-suave)' }">
                    La persona moral educativa dueña de los campus. Solo puede haber una; su nombre y logo
                    membretan lo que la escuela emite y la pantalla de acceso.
                </p>
            </div>
            <a
                v-if="puedeEditar"
                href="/academico/instituciones/create"
                class="rounded-lg px-4 py-2 text-sm font-medium"
                :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
            >
                Registrar institución
            </a>
        </div>

        <!-- Ficha de la institución, con edición directa. -->
        <div v-else class="tarjeta overflow-hidden">
            <!-- Cabecera con acento del tema. -->
            <div class="h-24 w-full" :style="{ background: 'linear-gradient(120deg, var(--color-acento), color-mix(in srgb, var(--color-acento) 55%, #000))' }" />

            <div class="px-6 pb-6">
                <div class="-mt-12 flex flex-col gap-4 sm:flex-row sm:items-end">
                    <!-- Logo -->
                    <div class="relative">
                        <div
                            class="grid h-24 w-24 shrink-0 place-items-center overflow-hidden rounded-2xl ring-4"
                            :style="{ backgroundColor: 'var(--color-superficie)', '--tw-ring-color': 'var(--color-superficie)' }"
                        >
                            <img v-if="vistaPrevia" :src="vistaPrevia" :alt="institucion.nombre" class="h-full w-full object-contain" />
                            <span v-else class="text-3xl font-bold" :style="{ color: 'var(--color-acento)' }">
                                {{ institucion.nombre?.[0]?.toUpperCase() ?? 'I' }}
                            </span>
                        </div>
                        <button
                            v-if="puedeEditar"
                            type="button"
                            class="absolute -bottom-1 -right-1 grid h-8 w-8 place-items-center rounded-full shadow-md ring-2"
                            :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)', '--tw-ring-color': 'var(--color-superficie)' }"
                            title="Cambiar logo"
                            @click="entradaLogo?.click()"
                        >
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Z" />
                            </svg>
                        </button>
                        <input ref="entradaLogo" type="file" accept="image/*" class="hidden" @change="elegirLogo" />
                    </div>

                    <div class="min-w-0 flex-1 pt-2 sm:pt-0">
                        <p class="text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">Institución</p>
                        <p class="text-sm" :style="{ color: 'var(--color-suave)' }">
                            {{ institucion.campus_count }} campus · La escuela ES una institución: solo se edita.
                        </p>
                    </div>
                </div>

                <!-- Campos con edición directa -->
                <form class="mt-6 grid gap-4 sm:grid-cols-3" @submit.prevent="guardar">
                    <label class="text-sm sm:col-span-2">
                        <span class="mb-1 block font-medium">Nombre</span>
                        <input
                            v-model="form.nombre"
                            type="text"
                            :readonly="!puedeEditar"
                            class="w-full rounded-lg border px-3 py-2 text-sm read-only:opacity-70"
                            :style="{ borderColor: 'var(--color-borde)' }"
                        />
                        <span v-if="form.errors.nombre" class="text-xs text-red-600">{{ form.errors.nombre }}</span>
                    </label>
                    <label class="text-sm">
                        <span class="mb-1 block font-medium">Clave</span>
                        <input
                            v-model="form.clave"
                            type="text"
                            :readonly="!puedeEditar"
                            class="w-full rounded-lg border px-3 py-2 font-mono text-sm read-only:opacity-70"
                            :style="{ borderColor: 'var(--color-borde)' }"
                        />
                        <span v-if="form.errors.clave" class="text-xs text-red-600">{{ form.errors.clave }}</span>
                    </label>

                    <div v-if="puedeEditar" class="sm:col-span-3">
                        <button
                            type="submit"
                            :disabled="form.processing"
                            class="rounded-lg px-5 py-2.5 text-sm font-medium disabled:opacity-60"
                            :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                        >
                            {{ form.processing ? 'Guardando…' : 'Guardar cambios' }}
                        </button>
                        <span v-if="form.logo" class="ml-3 text-xs" :style="{ color: 'var(--color-suave)' }">
                            Logo nuevo listo para guardar.
                        </span>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
