<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

interface Hijo {
    id: number;
    nombre: string;
    foto: string | null;
    parentesco: string;
    carreras: string[];
    puede_ver_academico: boolean;
    puede_ver_finanzas: boolean;
}

defineProps<{ hijos: Hijo[] }>();

function iniciales(nombre: string): string {
    return nombre.split(' ').filter(Boolean).slice(0, 2).map((p) => p[0]?.toUpperCase()).join('');
}
</script>

<template>
    <Head title="Mis hijos" />

    <AppLayout titulo="Mis hijos">
        <p class="max-w-2xl text-sm" :style="{ color: 'var(--color-suave)' }">
            Aquí ves la información de los alumnos que la escuela tiene vinculados contigo.
        </p>

        <section v-if="hijos.length" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <Link
                v-for="hijo in hijos"
                :key="hijo.id"
                :href="`/mis-hijos/${hijo.id}`"
                class="tarjeta tarjeta-interactiva flex flex-col gap-3 p-5"
            >
                <div class="flex items-center gap-3">
                    <img
                        v-if="hijo.foto"
                        :src="hijo.foto"
                        alt=""
                        class="h-12 w-12 rounded-full object-cover"
                        loading="lazy"
                    />
                    <span
                        v-else
                        class="flex h-12 w-12 items-center justify-center rounded-full text-sm font-semibold"
                        :style="{ backgroundColor: 'color-mix(in srgb, var(--color-acento) 15%, transparent)', color: 'var(--color-acento)' }"
                    >
                        {{ iniciales(hijo.nombre) }}
                    </span>
                    <div class="min-w-0">
                        <h3 class="truncate font-medium">{{ hijo.nombre }}</h3>
                        <p class="text-xs capitalize" :style="{ color: 'var(--color-suave)' }">{{ hijo.parentesco }}</p>
                    </div>
                </div>

                <p v-if="hijo.carreras.length" class="text-sm" :style="{ color: 'var(--color-suave)' }">
                    {{ hijo.carreras.join(' · ') }}
                </p>
                <p v-else class="text-sm" :style="{ color: 'var(--color-suave)' }">Sin carreras registradas.</p>

                <div class="mt-auto flex flex-wrap gap-1.5 text-xs">
                    <span
                        v-if="hijo.puede_ver_academico"
                        class="rounded-full px-2 py-0.5"
                        :style="{ backgroundColor: 'var(--color-borde)' }"
                    >
                        Académico
                    </span>
                    <span
                        v-if="hijo.puede_ver_finanzas"
                        class="rounded-full px-2 py-0.5"
                        :style="{ backgroundColor: 'var(--color-borde)' }"
                    >
                        Finanzas
                    </span>
                </div>
            </Link>
        </section>

        <p v-else class="tarjeta px-6 py-12 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
            Todavía no tienes alumnos vinculados. Pídele a la escuela que te vincule con tus hijos.
        </p>
    </AppLayout>
</template>
