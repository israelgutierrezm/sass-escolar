<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Paginacion from '@/Components/Paginacion.vue';
import PanelFiltros from '@/Components/PanelFiltros.vue';
import SelectorVista from '@/Components/SelectorVista.vue';
import TarjetaPersona from '@/Components/TarjetaPersona.vue';

interface Prospecto {
    id: number;
    nombre: string | null;
    telefono: string | null;
    email: string | null;
    carrera: string | null;
    origen: string | null;
    foto: string | null;
    titular: string | null;
    ultimo_contacto: string | null;
}

const props = defineProps<{
    etapa: { id: number; nombre: string; clave: string };
    aspirantes: {
        data: Prospecto[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
        from: number | null;
        to: number | null;
    };
    etapas: { id: number; nombre: string }[];
    filtros: Record<string, any>;
    origenes: { id: number; nombre: string }[];
    ofertas: { id: number; nombre: string }[];
    promotores: { id: number; nombre: string }[];
}>();

const busqueda = ref(props.filtros.busqueda);
const vista = ref<'lista' | 'cuadricula'>('lista');

let temporizador: ReturnType<typeof setTimeout> | undefined;

watch(busqueda, () => {
    clearTimeout(temporizador);
    temporizador = setTimeout(() => consultar({}), 350);
});

function consultar(cambios: Record<string, any>): void {
    router.get(
        `/promocion/etapas/${props.etapa.id}`,
        {
            busqueda: busqueda.value || undefined,
            origen_id: props.filtros.origen_id || undefined,
            oferta_id: props.filtros.oferta_id || undefined,
            promotor_id: props.filtros.promotor_id || undefined,
            ...cambios,
        },
        { preserveState: true, replace: true, preserveScroll: true },
    );
}

const definicionFiltros = [
    { clave: 'origen_id', etiqueta: 'Cómo llegó', opciones: props.origenes.map((o) => ({ valor: o.id, texto: o.nombre })) },
    { clave: 'oferta_id', etiqueta: 'Programa de interés', opciones: props.ofertas.map((o) => ({ valor: o.id, texto: o.nombre })) },
    { clave: 'promotor_id', etiqueta: 'Promotor', opciones: props.promotores.map((p) => ({ valor: p.id, texto: p.nombre })) },
];
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

            <div class="mt-4 flex flex-wrap items-end gap-3">
                <div class="min-w-64 flex-1">
                    <input
                        v-model="busqueda"
                        type="search"
                        placeholder="Nombre, teléfono o correo…"
                        class="w-full rounded-lg border px-3 py-2 text-sm"
                        :style="{ borderColor: 'var(--color-borde)' }"
                    />
                </div>
                <SelectorVista v-model="vista" clave="promocion-etapa" />
            </div>

            <div class="mt-3">
                <PanelFiltros :filtros="definicionFiltros" :valores="filtros" @cambio="(valores) => consultar(valores)" />
            </div>
        </section>

        <!-- Cuadrícula: útil cuando se reparte trabajo entre promotores. -->
        <template v-if="vista === 'cuadricula'">
            <section v-if="aspirantes.data.length" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <TarjetaPersona
                    v-for="a in aspirantes.data"
                    :key="a.id"
                    :nombre="a.nombre"
                    :foto="a.foto"
                    :lineas="[a.carrera, a.telefono ?? a.email, a.origen]"
                    :estado="a.titular ?? 'sin promotor'"
                    :aviso="a.ultimo_contacto ? null : 'nunca contactado'"
                    :url="`/aspirantes/${a.id}`"
                />
            </section>

            <section v-if="aspirantes.links.length > 3" class="tarjeta">
                <Paginacion :enlaces="aspirantes.links" :total="aspirantes.total" :desde="aspirantes.from" :hasta="aspirantes.to" />
            </section>

            <section v-if="!aspirantes.data.length" class="tarjeta px-6 py-10 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                No hay prospectos que coincidan en esta etapa.
            </section>
        </template>

        <section v-else class="tarjeta overflow-hidden">
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
                        <td class="px-6 py-3">
                            <span class="flex items-center gap-2">
                                <img v-if="a.foto" :src="a.foto" alt="" class="h-8 w-8 rounded-full object-cover" loading="lazy" />
                                <span class="font-medium">{{ a.nombre }}</span>
                            </span>
                        </td>
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
                No hay prospectos que coincidan en esta etapa.
            </p>

            <Paginacion :enlaces="aspirantes.links" :total="aspirantes.total" :desde="aspirantes.from" :hasta="aspirantes.to" />
        </section>
    </AppLayout>
</template>
