<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import NavEscolar from '@/Components/NavEscolar.vue';
import PanelFiltros from '@/Components/PanelFiltros.vue';
import Paginacion from '@/Components/Paginacion.vue';
import SelectorVista from '@/Components/SelectorVista.vue';
import TarjetaRegistro from '@/Components/TarjetaRegistro.vue';

interface FilaGrupo {
    id: number;
    clave: string;
    nombre: string | null;
    ciclo: string | null;
    campus: string | null;
    plan: string | null;
    turno: string | null;
    situacion: string | null;
    cupo: number | null;
    materias_count: number;
}

const props = defineProps<{
    grupos: {
        data: FilaGrupo[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
        from: number | null;
        to: number | null;
    };
    filtros: Record<string, any>;
    ciclos: { id: number; nombre: string }[];
    campus: { id: number; nombre: string }[];
    planes: { id: number; nombre: string }[];
    turnos: { id: number; nombre: string }[];
    situaciones: { id: number; nombre: string }[];
    puedeEditar: boolean;
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
        '/escolar/grupos',
        {
            busqueda: busqueda.value || undefined,
            ciclo_id: props.filtros.ciclo_id || undefined,
            campus_id: props.filtros.campus_id || undefined,
            plan_id: props.filtros.plan_id || undefined,
            turno_id: props.filtros.turno_id || undefined,
            situacion_id: props.filtros.situacion_id || undefined,
            ...cambios,
        },
        { preserveState: true, replace: true, preserveScroll: true },
    );
}

const definicionFiltros = [
    { clave: 'ciclo_id', etiqueta: 'Ciclo', opciones: props.ciclos.map((c) => ({ valor: c.id, texto: c.nombre })) },
    { clave: 'campus_id', etiqueta: 'Campus', opciones: props.campus.map((c) => ({ valor: c.id, texto: c.nombre })) },
    { clave: 'plan_id', etiqueta: 'Plan de estudios', opciones: props.planes.map((p) => ({ valor: p.id, texto: p.nombre })) },
    { clave: 'turno_id', etiqueta: 'Turno', opciones: props.turnos.map((t) => ({ valor: t.id, texto: t.nombre })) },
    { clave: 'situacion_id', etiqueta: 'Situación', opciones: props.situaciones.map((s) => ({ valor: s.id, texto: s.nombre })) },
];

function eliminar(id: number, clave: string): void {
    if (!confirm(`¿Eliminar el grupo "${clave}"?`)) {
        return;
    }

    router.delete(`/escolar/grupos/${id}`, { preserveScroll: true });
}
</script>

<template>
    <Head title="Grupos" />

    <AppLayout titulo="Control escolar">
        <NavEscolar />

        <section class="tarjeta p-6">
            <div class="flex flex-wrap items-end gap-3">
                <div class="min-w-64 flex-1">
                    <label class="block text-sm font-medium">Buscar</label>
                    <input
                        v-model="busqueda"
                        type="search"
                        placeholder="Clave o nombre del grupo…"
                        class="mt-1 w-full rounded-lg border px-3 py-2 text-sm"
                        :style="{ borderColor: 'var(--color-borde)' }"
                    />
                </div>
                <SelectorVista v-model="vista" clave="grupos" />
                <a
                    v-if="puedeEditar"
                    href="/escolar/grupos/create"
                    class="rounded-lg px-4 py-2 text-sm font-medium"
                    :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                >
                    Nuevo grupo
                </a>
            </div>

            <div class="mt-4">
                <PanelFiltros :filtros="definicionFiltros" :valores="filtros" @cambio="(valores) => consultar(valores)" />
            </div>

            <p class="mt-4 text-sm" :style="{ color: 'var(--color-suave)' }">
                Contenedor de materias en un ciclo. Una materia solo es cursable si está abierta en un grupo.
                <template v-if="grupos.total">
                    · {{ grupos.from }}–{{ grupos.to }} de {{ grupos.total }}
                </template>
            </p>
        </section>

        <!-- Cuadrícula -->
        <template v-if="vista === 'cuadricula'">
            <section v-if="grupos.data.length" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <TarjetaRegistro
                    v-for="grupo in grupos.data"
                    :key="grupo.id"
                    :titulo="grupo.clave"
                    :subtitulo="grupo.nombre"
                    :estado="grupo.situacion"
                    :url="`/escolar/grupos/${grupo.id}`"
                    :datos="[
                        { etiqueta: 'Ciclo', valor: grupo.ciclo },
                        { etiqueta: 'Campus', valor: grupo.campus },
                        { etiqueta: 'Plan', valor: grupo.plan ?? 'Sin plan fijo' },
                        { etiqueta: 'Turno', valor: grupo.turno },
                        { etiqueta: 'Cupo', valor: grupo.cupo },
                        { etiqueta: 'Materias', valor: grupo.materias_count },
                    ]"
                >
                    <template #acciones>
                        <a :href="`/escolar/grupos/${grupo.id}`" :style="{ color: 'var(--color-acento)' }">Abrir</a>
                        <template v-if="puedeEditar">
                            <a :href="`/escolar/grupos/${grupo.id}/edit`" :style="{ color: 'var(--color-suave)' }">Editar</a>
                            <button type="button" :style="{ color: 'var(--color-suave)' }" @click="eliminar(grupo.id, grupo.clave)">
                                Eliminar
                            </button>
                        </template>
                    </template>
                </TarjetaRegistro>
            </section>

            <section v-if="grupos.links.length > 3" class="tarjeta">
                <Paginacion :enlaces="grupos.links" :total="grupos.total" :desde="grupos.from" :hasta="grupos.to" />
            </section>
        </template>

        <!-- Lista -->
        <section v-else class="tarjeta overflow-hidden">
            <div class="overflow-x-auto">
                <table v-if="grupos.data.length" class="w-full text-sm">
                    <thead class="text-left text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">
                        <tr>
                            <th class="px-6 py-3 font-medium">Clave</th>
                            <th class="px-4 py-3 font-medium">Ciclo</th>
                            <th class="px-4 py-3 font-medium">Campus</th>
                            <th class="px-4 py-3 font-medium">Plan</th>
                            <th class="px-4 py-3 font-medium">Turno</th>
                            <th class="px-4 py-3 font-medium">Cupo</th>
                            <th class="px-4 py-3 font-medium">Materias</th>
                            <th class="px-4 py-3 font-medium">Situación</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="grupo in grupos.data"
                            :key="grupo.id"
                            class="border-t"
                            :style="{ borderColor: 'var(--color-borde)' }"
                        >
                            <td class="px-6 py-3">
                                <a
                                    :href="`/escolar/grupos/${grupo.id}`"
                                    class="font-mono text-xs font-medium"
                                    :style="{ color: 'var(--color-acento)' }"
                                >
                                    {{ grupo.clave }}
                                </a>
                                <span v-if="grupo.nombre" class="block text-xs" :style="{ color: 'var(--color-suave)' }">
                                    {{ grupo.nombre }}
                                </span>
                            </td>
                            <td class="px-4 py-3 font-mono text-xs">{{ grupo.ciclo }}</td>
                            <td class="px-4 py-3">{{ grupo.campus }}</td>
                            <td class="px-4 py-3">{{ grupo.plan ?? 'Sin plan fijo' }}</td>
                            <td class="px-4 py-3">{{ grupo.turno ?? '—' }}</td>
                            <td class="px-4 py-3">{{ grupo.cupo ?? '—' }}</td>
                            <td class="px-4 py-3">{{ grupo.materias_count }}</td>
                            <td class="px-4 py-3">
                                <span
                                    class="rounded-full px-2 py-0.5 text-xs"
                                    style="background-color: color-mix(in srgb, currentColor 10%, transparent)"
                                >
                                    {{ grupo.situacion }}
                                </span>
                            </td>
                            <td class="px-6 py-3 text-right whitespace-nowrap">
                                <a :href="`/escolar/grupos/${grupo.id}`" class="text-sm font-medium" :style="{ color: 'var(--color-acento)' }">
                                    Abrir
                                </a>
                                <template v-if="puedeEditar">
                                    <a
                                        :href="`/escolar/grupos/${grupo.id}/edit`"
                                        class="ml-3 text-sm"
                                        :style="{ color: 'var(--color-suave)' }"
                                    >
                                        Editar
                                    </a>
                                    <button
                                        type="button"
                                        class="ml-3 text-sm"
                                        :style="{ color: 'var(--color-suave)' }"
                                        @click="eliminar(grupo.id, grupo.clave)"
                                    >
                                        Eliminar
                                    </button>
                                </template>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <p v-else class="px-6 py-12 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                    {{
                        filtros.busqueda
                            ? `Ningún grupo coincide con "${filtros.busqueda}".`
                            : 'Aún no hay grupos. Necesitas al menos un ciclo y un campus.'
                    }}
                </p>
            </div>

            <Paginacion :enlaces="grupos.links" :total="grupos.total" :desde="grupos.from" :hasta="grupos.to" />
        </section>
    </AppLayout>
</template>
