<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import NavEscolar from '@/Components/NavEscolar.vue';
import PanelFiltros from '@/Components/PanelFiltros.vue';
import Paginacion from '@/Components/Paginacion.vue';
import SelectorVista from '@/Components/SelectorVista.vue';
import TarjetaPersona from '@/Components/TarjetaPersona.vue';

interface Docente {
    id: number;
    nombre_completo: string | null;
    clave_profesor: string | null;
    cedula_profesional: string | null;
    curp: string | null;
    email: string | null;
    tipo: string | null;
    situacion: string | null;
    situacion_clave: string | null;
    campus: string[];
    materias: number;
    documentos_pendientes: number;
    foto: string | null;
}

const props = defineProps<{
    docentes: { data: Docente[]; links: { url: string | null; label: string; active: boolean }[]; total: number; from: number | null; to: number | null };
    filtros: Record<string, any>;
    situaciones: { id: number; nombre: string }[];
    tipos: { id: number; nombre: string }[];
    campus: { id: number; nombre: string }[];
    puedeGestionar: boolean;
}>();

const busqueda = ref(props.filtros.busqueda);
const vista = ref<'lista' | 'cuadricula'>('lista');

let temporizador: ReturnType<typeof setTimeout> | undefined;

/** La busqueda espera a que dejes de teclear: sin la pausa, cada tecla consulta. */
watch(busqueda, () => {
    clearTimeout(temporizador);
    temporizador = setTimeout(() => consultar({}), 350);
});

function consultar(cambios: Record<string, any>): void {
    router.get(
        '/escolar/docentes',
        {
            busqueda: busqueda.value || undefined,
            situacion_id: props.filtros.situacion_id || undefined,
            tipo_docente_id: props.filtros.tipo_docente_id || undefined,
            campus_id: props.filtros.campus_id || undefined,
            ...cambios,
        },
        { preserveState: true, replace: true, preserveScroll: true },
    );
}

const definicionFiltros = [
    { clave: 'situacion_id', etiqueta: 'Situacion', opciones: props.situaciones.map((s) => ({ valor: s.id, texto: s.nombre })) },
    { clave: 'tipo_docente_id', etiqueta: 'Tipo', opciones: props.tipos.map((t) => ({ valor: t.id, texto: t.nombre })) },
    { clave: 'campus_id', etiqueta: 'Campus', opciones: props.campus.map((c) => ({ valor: c.id, texto: c.nombre })) },
];
</script>

<template>
    <Head title="Docentes" />

    <AppLayout titulo="Docentes">
        <NavEscolar />

        <section class="tarjeta p-6">
            <div class="flex flex-wrap items-end gap-3">
                <div class="min-w-64 flex-1">
                    <label class="block text-sm font-medium">Buscar</label>
                    <input
                        v-model="busqueda"
                        type="search"
                        placeholder="Nombre, clave, cedula o CURP..."
                        class="mt-1 w-full rounded-lg border px-3 py-2 text-sm"
                        :style="{ borderColor: 'var(--color-borde)' }"
                    />
                </div>
                <SelectorVista v-model="vista" clave="docentes" />
                <a
                    v-if="puedeGestionar"
                    href="/escolar/docentes/nuevo"
                    class="rounded-lg px-4 py-2 text-sm font-medium"
                    :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                >
                    Nuevo docente
                </a>
            </div>

            <div class="mt-4">
                <PanelFiltros :filtros="definicionFiltros" :valores="filtros" @cambio="(valores) => consultar(valores)" />
            </div>

            <p class="mt-4 text-sm" :style="{ color: 'var(--color-suave)' }">
                <template v-if="docentes.total">{{ docentes.from }}-{{ docentes.to }} de {{ docentes.total }}</template>
                <template v-else>Sin resultados</template>
            </p>
        </section>

        <template v-if="vista === 'cuadricula'">
            <section v-if="docentes.data.length" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <TarjetaPersona
                    v-for="docente in docentes.data"
                    :key="docente.id"
                    :nombre="docente.nombre_completo"
                    :identificador="docente.clave_profesor"
                    :foto="docente.foto"
                    :lineas="[docente.tipo, docente.campus.join(', ') || null, docente.materias + ' materia(s)']"
                    :estado="docente.situacion"
                    :atenuada="docente.situacion_clave === 'baja'"
                    :aviso="docente.documentos_pendientes ? docente.documentos_pendientes + ' por revisar' : null"
                    :url="`/escolar/docentes/${docente.id}`"
                />
            </section>

            <section v-if="docentes.links.length > 3" class="tarjeta">
                <Paginacion :enlaces="docentes.links" :total="docentes.total" :desde="docentes.from" :hasta="docentes.to" />
            </section>
        </template>

        <section v-else class="tarjeta overflow-hidden">
            <div class="overflow-x-auto">
                <table v-if="docentes.data.length" class="w-full text-sm">
                    <thead class="text-left text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">
                        <tr>
                            <th class="px-6 py-3 font-medium">Clave</th>
                            <th class="px-4 py-3 font-medium">Docente</th>
                            <th class="px-4 py-3 font-medium">Cédula</th>
                            <th class="px-4 py-3 font-medium">Tipo</th>
                            <th class="px-4 py-3 font-medium">Campus</th>
                            <th class="px-4 py-3 font-medium">Materias</th>
                            <th class="px-4 py-3 font-medium">Situación</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="docente in docentes.data"
                            :key="docente.id"
                            class="border-t"
                            :class="docente.situacion_clave === 'baja' ? 'opacity-50' : ''"
                            :style="{ borderColor: 'var(--color-borde)' }"
                        >
                            <td class="px-6 py-3 font-mono text-xs">{{ docente.clave_profesor ?? '—' }}</td>
                            <td class="px-4 py-3">
                                <span class="flex items-center gap-2">
                                    <img v-if="docente.foto" :src="docente.foto" alt="" class="h-8 w-8 rounded-full object-cover" loading="lazy" />
                                    <span>
                                        <span class="font-medium">{{ docente.nombre_completo }}</span>
                                        <span v-if="docente.email" class="block text-xs" :style="{ color: 'var(--color-suave)' }">
                                            {{ docente.email }}
                                        </span>
                                    </span>
                                </span>
                            </td>
                            <td class="px-4 py-3 font-mono text-xs">{{ docente.cedula_profesional ?? '—' }}</td>
                            <td class="px-4 py-3">{{ docente.tipo ?? '—' }}</td>
                            <td class="px-4 py-3 text-xs">
                                {{ docente.campus.length ? docente.campus.join(', ') : '—' }}
                            </td>
                            <td class="px-4 py-3">{{ docente.materias }}</td>
                            <td class="px-4 py-3">
                                {{ docente.situacion }}
                                <!-- Lo que el docente subió y nadie ha revisado: es
                                     la acción pendiente de control escolar. -->
                                <span
                                    v-if="docente.documentos_pendientes"
                                    class="ml-1 rounded-full px-2 py-0.5 text-xs"
                                    style="background-color: color-mix(in srgb, #f59e0b 20%, transparent)"
                                    :title="`${docente.documentos_pendientes} documento(s) por revisar`"
                                >
                                    {{ docente.documentos_pendientes }} por revisar
                                </span>
                            </td>
                            <td class="px-6 py-3 text-right">
                                <a
                                    :href="`/escolar/docentes/${docente.id}`"
                                    class="text-sm font-medium"
                                    :style="{ color: 'var(--color-acento)' }"
                                >
                                    Ficha
                                </a>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <p v-else class="px-6 py-12 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                    {{ filtros.busqueda ? `Nadie coincide con "${filtros.busqueda}".` : 'Todavía no hay docentes dados de alta.' }}
                </p>
            </div>

            <Paginacion :enlaces="docentes.links" :total="docentes.total" :desde="docentes.from" :hasta="docentes.to" />
        </section>
    </AppLayout>
</template>
