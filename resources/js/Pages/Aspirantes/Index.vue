<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import PanelFiltros from '@/Components/PanelFiltros.vue';
import Paginacion from '@/Components/Paginacion.vue';
import SelectorVista from '@/Components/SelectorVista.vue';
import TarjetaPersona from '@/Components/TarjetaPersona.vue';

interface FilaAspirante {
    id: number;
    nombre_completo: string | null;
    curp: string | null;
    email: string | null;
    celular: string | null;
    foto: string | null;
    situacion: string | null;
    etapa: string | null;
    campus: string | null;
    oferta: string | null;
    origen: string | null;
    paso: number;
    validado_admin: boolean;
}

const props = defineProps<{
    aspirantes: {
        data: FilaAspirante[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
        from: number | null;
        to: number | null;
    };
    filtros: Record<string, any>;
    situaciones: { id: number; nombre: string }[];
    etapas: { id: number; nombre: string }[];
    origenes: { id: number; nombre: string }[];
    campusDisponibles: { id: number; nombre: string }[];
    ofertas: { id: number; nombre: string }[];
    puedeCrear: boolean;
    puedeEditar: boolean;
}>();

const busqueda = ref(props.filtros.busqueda);
const vista = ref<'lista' | 'cuadricula'>('lista');

let temporizador: ReturnType<typeof setTimeout> | undefined;

/** La búsqueda espera a que dejes de teclear: sin la pausa, cada tecla consulta. */
watch(busqueda, () => {
    clearTimeout(temporizador);
    temporizador = setTimeout(() => consultar({}), 350);
});

function consultar(cambios: Record<string, any>): void {
    router.get(
        '/aspirantes',
        {
            busqueda: busqueda.value || undefined,
            situacion_id: props.filtros.situacion_id || undefined,
            etapa_crm_id: props.filtros.etapa_crm_id || undefined,
            origen_id: props.filtros.origen_id || undefined,
            campus_id: props.filtros.campus_id || undefined,
            oferta_id: props.filtros.oferta_id || undefined,
            ...cambios,
        },
        { preserveState: true, replace: true, preserveScroll: true },
    );
}

const definicionFiltros = [
    { clave: 'situacion_id', etiqueta: 'Situación', opciones: props.situaciones.map((s) => ({ valor: s.id, texto: s.nombre })) },
    { clave: 'etapa_crm_id', etiqueta: 'Etapa del embudo', opciones: props.etapas.map((e) => ({ valor: e.id, texto: e.nombre })) },
    { clave: 'origen_id', etiqueta: 'Cómo llegó', opciones: props.origenes.map((o) => ({ valor: o.id, texto: o.nombre })) },
    { clave: 'campus_id', etiqueta: 'Campus', opciones: props.campusDisponibles.map((c) => ({ valor: c.id, texto: c.nombre })) },
    { clave: 'oferta_id', etiqueta: 'Programa de interés', opciones: props.ofertas.map((o) => ({ valor: o.id, texto: o.nombre })) },
];
</script>

<template>
    <Head title="Aspirantes" />

    <AppLayout titulo="Aspirantes">
        <section class="tarjeta p-6">
            <div class="flex flex-wrap items-end gap-3">
                <div class="min-w-64 flex-1">
                    <label class="block text-sm font-medium">Buscar</label>
                    <input
                        v-model="busqueda"
                        type="search"
                        placeholder="Nombre o CURP…"
                        class="mt-1 w-full rounded-lg border px-3 py-2 text-sm"
                        :style="{ borderColor: 'var(--color-borde)' }"
                    />
                </div>
                <SelectorVista v-model="vista" clave="aspirantes" />
                <a
                    v-if="puedeCrear"
                    href="/aspirantes/nuevo"
                    class="rounded-lg px-4 py-2 text-sm font-medium"
                    :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                >
                    Nuevo aspirante
                </a>
            </div>

            <div class="mt-4">
                <PanelFiltros
                    :filtros="definicionFiltros"
                    :valores="filtros"
                    @cambio="(valores) => consultar(valores)"
                />
            </div>

            <p class="mt-4 text-sm" :style="{ color: 'var(--color-suave)' }">
                <template v-if="aspirantes.total">{{ aspirantes.from }}–{{ aspirantes.to }} de {{ aspirantes.total }}</template>
                <template v-else>Sin resultados</template>
            </p>
        </section>

        <!-- Cuadrícula -->
        <template v-if="vista === 'cuadricula'">
            <section v-if="aspirantes.data.length" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <TarjetaPersona
                    v-for="aspirante in aspirantes.data"
                    :key="aspirante.id"
                    :nombre="aspirante.nombre_completo"
                    :identificador="aspirante.curp"
                    :foto="aspirante.foto"
                    :lineas="[aspirante.oferta, aspirante.campus, aspirante.celular ?? aspirante.email, aspirante.etapa]"
                    :estado="aspirante.situacion"
                    :aviso="aspirante.etapa ? null : 'fuera del embudo'"
                    :url="`/aspirantes/${aspirante.id}`"
                />
            </section>

            <section v-if="aspirantes.links.length > 3" class="tarjeta">
                <Paginacion :enlaces="aspirantes.links" :total="aspirantes.total" :desde="aspirantes.from" :hasta="aspirantes.to" />
            </section>
        </template>

        <!-- Lista -->
        <section v-else class="tarjeta overflow-hidden">
            <div class="overflow-x-auto">
                <table v-if="aspirantes.data.length" class="w-full text-sm">
                    <thead class="text-left text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">
                        <tr>
                            <th class="px-6 py-3 font-medium">Aspirante</th>
                            <th class="px-4 py-3 font-medium">CURP</th>
                            <th class="px-4 py-3 font-medium">Interés</th>
                            <th class="px-4 py-3 font-medium">Etapa</th>
                            <th class="px-4 py-3 font-medium">Situación</th>
                            <th class="px-4 py-3 font-medium">Cómo llegó</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="aspirante in aspirantes.data"
                            :key="aspirante.id"
                            class="border-t"
                            :style="{ borderColor: 'var(--color-borde)' }"
                        >
                            <td class="px-6 py-3">
                                <span class="flex items-center gap-2">
                                    <img v-if="aspirante.foto" :src="aspirante.foto" alt="" class="h-8 w-8 rounded-full object-cover" loading="lazy" />
                                    <span>
                                        <a
                                            :href="`/aspirantes/${aspirante.id}`"
                                            class="font-medium"
                                            :style="{ color: 'var(--color-acento)' }"
                                        >
                                            {{ aspirante.nombre_completo }}
                                        </a>
                                        <span
                                            v-if="aspirante.celular || aspirante.email"
                                            class="block text-xs"
                                            :style="{ color: 'var(--color-suave)' }"
                                        >
                                            {{ aspirante.celular ?? aspirante.email }}
                                        </span>
                                    </span>
                                </span>
                            </td>
                            <td class="px-4 py-3 font-mono text-xs">{{ aspirante.curp ?? '—' }}</td>
                            <td class="px-4 py-3">
                                {{ aspirante.oferta ?? '—' }}
                                <span v-if="aspirante.campus" class="block text-xs" :style="{ color: 'var(--color-suave)' }">
                                    {{ aspirante.campus }}
                                </span>
                            </td>
                            <td class="px-4 py-3">{{ aspirante.etapa ?? '—' }}</td>
                            <td class="px-4 py-3">
                                <span
                                    class="rounded-full px-2 py-0.5 text-xs"
                                    style="background-color: color-mix(in srgb, currentColor 10%, transparent)"
                                >
                                    {{ aspirante.situacion }}
                                </span>
                            </td>
                            <td class="px-4 py-3" :style="{ color: 'var(--color-suave)' }">{{ aspirante.origen ?? '—' }}</td>
                            <td class="px-6 py-3 text-right">
                                <a
                                    v-if="puedeEditar"
                                    :href="`/aspirantes/${aspirante.id}/editar`"
                                    class="text-sm font-medium"
                                    :style="{ color: 'var(--color-acento)' }"
                                >
                                    Editar
                                </a>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <p v-else class="px-6 py-12 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                    {{ filtros.busqueda ? `Nadie coincide con "${filtros.busqueda}".` : 'Todavía no hay aspirantes registrados.' }}
                </p>
            </div>

            <Paginacion :enlaces="aspirantes.links" :total="aspirantes.total" :desde="aspirantes.from" :hasta="aspirantes.to" />
        </section>
    </AppLayout>
</template>
