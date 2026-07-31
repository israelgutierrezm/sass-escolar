<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BarraListado from '@/Components/BarraListado.vue';
import Paginacion from '@/Components/Paginacion.vue';
import TarjetaPersona from '@/Components/TarjetaPersona.vue';
import ZonaArchivo from '@/Components/ZonaArchivo.vue';

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

const vista = ref<'lista' | 'cuadricula'>('lista');

const definicionFiltros = [
    { clave: 'situacion_id', etiqueta: 'Situación', opciones: props.situaciones.map((s) => ({ valor: s.id, texto: s.nombre })) },
    { clave: 'tipo_docente_id', etiqueta: 'Tipo', opciones: props.tipos.map((t) => ({ valor: t.id, texto: t.nombre })) },
    { clave: 'campus_id', etiqueta: 'Campus', opciones: props.campus.map((c) => ({ valor: c.id, texto: c.nombre })) },
];

// --- Carga masiva por Excel ---
const page = usePage();
const erroresCarga = computed(() => ((page.props as any).flash?.erroresCarga ?? []) as { hoja: string; fila: number; mensaje: string }[]);
const mostrarCarga = ref(false);
const carga = useForm<{ archivo: File | null }>({ archivo: null });

function subirExcel(archivo: File | null): void {
    if (!archivo) return;
    carga.archivo = archivo;
    carga.post('/escolar/docentes/carga/importar', {
        forceFormData: true,
        preserveScroll: true,
        onFinish: () => carga.reset(),
    });
}
</script>

<template>
    <Head title="Docentes" />

    <AppLayout titulo="Docentes">

        <BarraListado
            v-model:vista="vista"
            url="/escolar/docentes"
            vista-clave="docentes"
            :valores="filtros"
            :filtros="definicionFiltros"
            placeholder="Nombre, clave, cédula o CURP…"
            :puede-crear="puedeGestionar"
            nuevo-texto="Nuevo docente"
            nuevo-href="/escolar/docentes/nuevo"
        />

        <!-- Carga masiva por Excel -->
        <section v-if="puedeGestionar" class="tarjeta mb-4 p-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="text-sm" :style="{ color: 'var(--color-suave)' }">
                    ¿Muchos docentes? Cárgalos desde una plantilla de Excel.
                </p>
                <button
                    type="button"
                    class="rounded-lg border px-4 py-2 text-sm font-medium"
                    :style="{ borderColor: 'var(--color-acento)', color: 'var(--color-acento)' }"
                    @click="mostrarCarga = !mostrarCarga"
                >
                    {{ mostrarCarga ? 'Ocultar' : 'Cargar desde Excel' }}
                </button>
            </div>

            <div v-if="mostrarCarga" class="mt-4 space-y-4 border-t pt-4" :style="{ borderColor: 'var(--color-borde)' }">
                <a href="/escolar/docentes/carga/plantilla" class="inline-flex items-center gap-2 text-sm font-medium" :style="{ color: 'var(--color-acento)' }">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-6L12 15m0 0 4.5-4.5M12 15V3" /></svg>
                    Descargar plantilla (.xlsx)
                </a>
                <ZonaArchivo
                    accept=".xlsx"
                    texto="Arrastra la plantilla llena (.xlsx) o haz clic para seleccionarla"
                    ayuda="Se valida todo antes de crear nada."
                    :cargado="null"
                    :ocupado="carga.processing"
                    @archivo="subirExcel"
                />
                <div
                    v-if="erroresCarga.length"
                    class="rounded-lg border p-3 text-sm"
                    :style="{ borderColor: '#f59e0b', backgroundColor: 'color-mix(in srgb, #f59e0b 8%, transparent)' }"
                >
                    <p class="font-medium">El archivo tiene {{ erroresCarga.length }} error(es); corrígelos y vuelve a subirlo:</p>
                    <ul class="mt-2 max-h-64 space-y-1 overflow-auto text-xs">
                        <li v-for="(e, i) in erroresCarga" :key="i"><span class="font-medium">{{ e.hoja }} · fila {{ e.fila }}:</span> {{ e.mensaje }}</li>
                    </ul>
                </div>
            </div>
        </section>

        <template v-if="vista === 'cuadricula'">
            <section v-if="docentes.data.length" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
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
                            <th class="px-6 py-3 font-medium text-right">Acciones</th>
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
