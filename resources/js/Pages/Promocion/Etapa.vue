<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Paginacion from '@/Components/Paginacion.vue';

defineProps<{
    etapa: { id: number; nombre: string; clave: string };
    aspirantes: {
        data: {
            id: number;
            nombre: string | null;
            telefono: string | null;
            email: string | null;
            carrera: string | null;
            origen: string | null;
            titular: string | null;
            ultimo_contacto: string | null;
        }[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
        from: number | null;
        to: number | null;
    };
    etapas: { id: number; nombre: string }[];
}>();
</script>

<template>
    <Head :title="`Promoción · ${etapa.nombre}`" />

    <AppLayout :titulo="etapa.nombre">
        <section class="tarjeta p-6">
            <div class="flex flex-wrap items-center gap-2">
                <a href="/promocion" class="text-sm" :style="{ color: 'var(--color-acento)' }">← Embudo</a>
                <span :style="{ color: 'var(--color-suave)' }">·</span>
                <a
                    v-for="e in etapas"
                    :key="e.id"
                    :href="`/promocion/etapas/${e.id}`"
                    class="rounded-full border px-3 py-1 text-xs"
                    :style="{
                        borderColor: 'var(--color-borde)',
                        backgroundColor: e.id === etapa.id ? 'var(--color-acento)' : 'transparent',
                        color: e.id === etapa.id ? 'var(--color-acento-texto)' : 'var(--color-suave)',
                    }"
                >
                    {{ e.nombre }}
                </a>
            </div>
        </section>

        <section class="tarjeta overflow-hidden">
            <table v-if="aspirantes.data.length" class="w-full text-sm">
                <thead class="text-left text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">
                    <tr>
                        <th class="px-6 py-3 font-medium">Prospecto</th>
                        <th class="px-4 py-3 font-medium">Contacto</th>
                        <th class="px-4 py-3 font-medium">Interés</th>
                        <th class="px-4 py-3 font-medium">Origen</th>
                        <th class="px-4 py-3 font-medium">Promotor</th>
                        <th class="px-4 py-3 font-medium">Último contacto</th>
                        <th class="px-6 py-3"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="a in aspirantes.data" :key="a.id" class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
                        <td class="px-6 py-3 font-medium">{{ a.nombre }}</td>
                        <td class="px-4 py-3 text-xs" :style="{ color: 'var(--color-suave)' }">
                            <span v-if="a.telefono" class="block">{{ a.telefono }}</span>
                            <span v-if="a.email" class="block">{{ a.email }}</span>
                            <span v-if="!a.telefono && !a.email">Sin datos de contacto</span>
                        </td>
                        <td class="px-4 py-3">{{ a.carrera ?? '—' }}</td>
                        <td class="px-4 py-3" :style="{ color: 'var(--color-suave)' }">{{ a.origen ?? '—' }}</td>
                        <td class="px-4 py-3">
                            <span v-if="a.titular">{{ a.titular }}</span>
                            <span v-else class="text-xs text-amber-700">sin asignar</span>
                        </td>
                        <td class="px-4 py-3 tabular-nums" :style="{ color: 'var(--color-suave)' }">
                            {{ a.ultimo_contacto ?? 'nunca' }}
                        </td>
                        <td class="px-6 py-3 text-right">
                            <a :href="`/aspirantes/${a.id}`" class="text-sm font-medium" :style="{ color: 'var(--color-acento)' }">
                                Abrir
                            </a>
                        </td>
                    </tr>
                </tbody>
            </table>

            <p v-else class="px-6 py-10 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                No hay prospectos en esta etapa.
            </p>

            <Paginacion :enlaces="aspirantes.links" :total="aspirantes.total" :desde="aspirantes.from" :hasta="aspirantes.to" />
        </section>
    </AppLayout>
</template>
