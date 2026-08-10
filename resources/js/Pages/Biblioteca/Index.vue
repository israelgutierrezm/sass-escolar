<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

interface Enlace {
    id: number;
    titulo: string;
    descripcion: string | null;
    url: string;
    imagen_url: string | null;
}

defineProps<{ tarjetas: Enlace[]; directos: Enlace[] }>();
</script>

<template>
    <Head title="Biblioteca digital" />

    <AppLayout titulo="Biblioteca digital">
        <!--
            Las tarjetas, todas del mismo tamaño.

            La imagen va en una caja de proporción fija y con `object-cover`: las
            portadas llegan de sitios ajenos y cada una trae la suya, así que sin
            recortarlas la cuadrícula quedaría con tarjetas de alturas distintas
            —que es exactamente lo que se pidió evitar—. El título se reserva dos
            renglones para que dos tarjetas contiguas no se descuadren porque una
            tiene el nombre más largo.
        -->
        <section v-if="tarjetas.length" class="cuadricula-listado">
            <a
                v-for="enlace in tarjetas"
                :key="enlace.id"
                :href="enlace.url"
                target="_blank"
                rel="noopener noreferrer"
                class="tarjeta tarjeta-interactiva flex flex-col overflow-hidden"
            >
                <span class="portada">
                    <img :src="enlace.imagen_url!" :alt="''" loading="lazy" />
                </span>
                <span class="flex flex-1 flex-col gap-1 p-3">
                    <span class="line-clamp-2 text-sm font-medium">{{ enlace.titulo }}</span>
                    <span
                        v-if="enlace.descripcion"
                        class="line-clamp-2 text-xs"
                        :style="{ color: 'var(--color-suave)' }"
                    >
                        {{ enlace.descripcion }}
                    </span>
                </span>
            </a>
        </section>

        <!--
            Los que no traen imagen no se inventan una: salen como lista.

            Una tarjeta con un hueco gris donde debería ir la portada se lee como
            algo que falló al cargar, no como un enlace sin imagen.
        -->
        <section v-if="directos.length" class="tarjeta p-5">
            <h2 class="text-sm font-semibold">Otros enlaces</h2>
            <ul class="mt-3 space-y-2">
                <li v-for="enlace in directos" :key="enlace.id">
                    <a
                        :href="enlace.url"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="text-sm font-medium"
                        :style="{ color: 'var(--color-acento)' }"
                    >
                        {{ enlace.titulo }}
                    </a>
                    <p v-if="enlace.descripcion" class="text-xs" :style="{ color: 'var(--color-suave)' }">
                        {{ enlace.descripcion }}
                    </p>
                </li>
            </ul>
        </section>

        <section
            v-if="!tarjetas.length && !directos.length"
            class="tarjeta px-6 py-8 text-center text-sm"
            :style="{ color: 'var(--color-suave)' }"
        >
            Tu escuela todavía no publica recursos aquí.
        </section>
    </AppLayout>
</template>

<style scoped>
/*
 * La portada, en proporción fija.
 *
 * `aspect-ratio` sobre la caja y `object-cover` sobre la imagen: la caja manda
 * el alto y la imagen se recorta para llenarla. Al revés —dejando que la imagen
 * imponga su alto— cada tarjeta mediría distinto según qué portada le tocara.
 */
.portada {
    display: block;
    aspect-ratio: 16 / 9;
    background: color-mix(in srgb, var(--color-borde) 45%, transparent);
}

.portada img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
</style>
