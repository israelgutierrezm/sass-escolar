<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps<{
    etapas: { id: number; clave: string; nombre: string; orden: number; total: number }[];
    origenes: { nombre: string; autogestivo: boolean; total: number }[];
    pendientes: { id: number; nombre: string | null; etapa: string | null; proximo_contacto: string | null; dias: number }[];
    total: number;
    esCoordinador: boolean;
}>();

// El ancho de la barra es relativo a la etapa más poblada, no al total: con el
// total, un embudo que arranca con 200 y termina con 3 deja las últimas etapas
// invisibles, que son justo las que interesan.
const mayor = Math.max(1, ...props.etapas.map((e) => e.total));
const mayorOrigen = Math.max(1, ...props.origenes.map((o) => o.total));
</script>

<template>
    <Head title="Promoción" />

    <AppLayout titulo="Promoción">
        <section class="tarjeta p-6">
            <h2 class="text-base font-semibold">
                {{ esCoordinador ? 'Embudo de admisión' : 'Mis prospectos' }}
            </h2>
            <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                {{ total }} prospectos.
                <template v-if="!esCoordinador">Ves solo los que te asignaron.</template>
            </p>
        </section>

        <section class="tarjeta p-6">
            <h3 class="text-sm font-semibold">Por etapa</h3>
            <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                Las etapas vacías también se muestran: donde no hay nadie es donde se están cayendo.
            </p>

            <ul class="mt-4 space-y-3">
                <li v-for="etapa in etapas" :key="etapa.id">
                    <a :href="`/promocion/etapas/${etapa.id}`" class="block">
                        <div class="flex items-center justify-between text-sm">
                            <span class="font-medium">{{ etapa.nombre }}</span>
                            <span class="tabular-nums" :style="{ color: 'var(--color-suave)' }">{{ etapa.total }}</span>
                        </div>
                        <div class="mt-1 h-2 w-full rounded-full" :style="{ backgroundColor: 'var(--color-borde)' }">
                            <div
                                class="h-2 rounded-full"
                                :style="{
                                    width: `${Math.round((etapa.total / mayor) * 100)}%`,
                                    backgroundColor: 'var(--color-acento)',
                                }"
                            ></div>
                        </div>
                    </a>
                </li>
            </ul>
        </section>

        <section class="tarjeta p-6">
            <h3 class="text-sm font-semibold">De dónde llegan</h3>
            <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                Los marcados como <strong>autogestivos</strong> se registraron solos desde la web, sin que
                nadie los capturara.
            </p>

            <ul v-if="origenes.length" class="mt-4 space-y-3">
                <li v-for="origen in origenes" :key="origen.nombre">
                    <div class="flex items-center justify-between text-sm">
                        <span>
                            {{ origen.nombre }}
                            <span v-if="origen.autogestivo" class="ml-2 rounded bg-emerald-50 px-2 py-0.5 text-xs text-emerald-700">
                                autogestivo
                            </span>
                        </span>
                        <span class="tabular-nums" :style="{ color: 'var(--color-suave)' }">{{ origen.total }}</span>
                    </div>
                    <div class="mt-1 h-2 w-full rounded-full" :style="{ backgroundColor: 'var(--color-borde)' }">
                        <div
                            class="h-2 rounded-full"
                            :style="{
                                width: `${Math.round((origen.total / mayorOrigen) * 100)}%`,
                                backgroundColor: origen.autogestivo ? '#059669' : 'var(--color-acento)',
                            }"
                        ></div>
                    </div>
                </li>
            </ul>
            <p v-else class="mt-4 text-sm" :style="{ color: 'var(--color-suave)' }">Sin datos todavía.</p>
        </section>

        <section class="tarjeta overflow-hidden">
            <header class="px-6 py-4">
                <h3 class="text-sm font-semibold">Contactar hoy</h3>
                <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                    Prospectos cuyo próximo contacto ya venció o es hoy. Se toma el último seguimiento con
                    fecha, no cualquiera: si ya se reagendó, deja de aparecer.
                </p>
            </header>

            <table v-if="pendientes.length" class="w-full text-sm">
                <thead class="text-left text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">
                    <tr>
                        <th class="px-6 py-3 font-medium">Prospecto</th>
                        <th class="px-4 py-3 font-medium">Etapa</th>
                        <th class="px-4 py-3 font-medium">Tocaba</th>
                        <th class="px-6 py-3"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="p in pendientes" :key="p.id" class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
                        <td class="px-6 py-3 font-medium">{{ p.nombre }}</td>
                        <td class="px-4 py-3" :style="{ color: 'var(--color-suave)' }">{{ p.etapa ?? '—' }}</td>
                        <td class="px-4 py-3">
                            {{ p.proximo_contacto }}
                            <span v-if="p.dias > 0" class="ml-1 text-xs font-medium text-red-600">
                                ({{ p.dias }} d de retraso)
                            </span>
                        </td>
                        <td class="px-6 py-3 text-right">
                            <a :href="`/aspirantes/${p.id}`" class="text-sm font-medium" :style="{ color: 'var(--color-acento)' }">
                                Abrir ficha
                            </a>
                        </td>
                    </tr>
                </tbody>
            </table>

            <p v-else class="px-6 py-10 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                Nada pendiente de contactar.
            </p>
        </section>
    </AppLayout>
</template>
