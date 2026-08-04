<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';

/**
 * Las encuestas que le tocan a esta persona.
 *
 * Lo obligatorio arriba y separado: si una encuesta se interpone al entrar,
 * quien llega aquí necesita ver de inmediato cuál es y cuántas le faltan.
 */
interface Pendiente {
    aplicacion_id: number;
    sujeto_id: number | null;
    titulo: string;
    instrucciones: string | null;
    obligatoria: boolean;
    anonima: boolean;
    cierra_en: string | null;
    sujeto: { docente: string | null; materia: string | null; grupo: string | null; papel: string | null } | null;
}

const props = defineProps<{ pendientes: Pendiente[] }>();

const obligatorias = computed(() => props.pendientes.filter((p) => p.obligatoria));
const opcionales = computed(() => props.pendientes.filter((p) => ! p.obligatoria));

function enlace(p: Pendiente): string {
    return p.sujeto_id === null
        ? `/mis-encuestas/${p.aplicacion_id}`
        : `/mis-encuestas/${p.aplicacion_id}/${p.sujeto_id}`;
}

function cuando(fecha: string | null): string {
    if (fecha === null) return '';

    return new Date(fecha.replace(' ', 'T')).toLocaleString('es-MX', {
        day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit',
    });
}
</script>

<template>
    <Head title="Mis encuestas" />

    <AppLayout titulo="Mis encuestas">
        <template v-if="pendientes.length">
            <section v-if="obligatorias.length" class="mb-6 space-y-3">
                <h2 class="text-sm font-semibold text-contenido">
                    Obligatorias
                    <span class="ml-1 font-normal text-suave">({{ obligatorias.length }})</span>
                </h2>

                <Link
                    v-for="p in obligatorias"
                    :key="`${p.aplicacion_id}-${p.sujeto_id}`"
                    :href="enlace(p)"
                    class="tarjeta block border-l-4 border-l-red-500 p-5 transition hover:shadow-md"
                >
                    <div class="flex flex-wrap items-center gap-2 text-xs">
                        <span class="rounded-full bg-red-50 px-2.5 py-0.5 font-medium text-red-700">Obligatoria</span>
                        <span v-if="p.anonima" class="text-suave">Anónima</span>
                        <span v-if="p.cierra_en" class="text-suave">· cierra el {{ cuando(p.cierra_en) }}</span>
                    </div>

                    <h3 class="mt-2 font-semibold text-contenido">{{ p.titulo }}</h3>
                    <p v-if="p.sujeto" class="mt-1 text-sm text-suave">
                        {{ p.sujeto.docente }} — {{ p.sujeto.materia }}
                        <template v-if="p.sujeto.grupo"> · {{ p.sujeto.grupo }}</template>
                    </p>
                </Link>
            </section>

            <section v-if="opcionales.length" class="space-y-3">
                <h2 v-if="obligatorias.length" class="text-sm font-semibold text-suave">Puedes contestarlas cuando quieras</h2>

                <Link
                    v-for="p in opcionales"
                    :key="`${p.aplicacion_id}-${p.sujeto_id}`"
                    :href="enlace(p)"
                    class="tarjeta block p-5 transition hover:shadow-md"
                >
                    <div class="flex flex-wrap items-center gap-2 text-xs">
                        <span v-if="p.anonima" class="text-suave">Anónima</span>
                        <span v-if="p.cierra_en" class="text-suave">· cierra el {{ cuando(p.cierra_en) }}</span>
                    </div>

                    <h3 class="mt-1 font-semibold text-contenido">{{ p.titulo }}</h3>
                    <p v-if="p.sujeto" class="mt-1 text-sm text-suave">
                        {{ p.sujeto.docente }} — {{ p.sujeto.materia }}
                    </p>
                </Link>
            </section>
        </template>

        <div v-else class="tarjeta px-6 py-16 text-center">
            <p class="text-sm text-suave">
                No tienes encuestas pendientes. Aquí aparecerán las que la escuela te dirija.
            </p>
        </div>
    </AppLayout>
</template>
