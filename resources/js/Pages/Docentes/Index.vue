<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BarraListado from '@/Components/BarraListado.vue';
import Paginacion from '@/Components/Paginacion.vue';
import TarjetaPersona from '@/Components/TarjetaPersona.vue';
import ZonaArchivo from '@/Components/ZonaArchivo.vue';

// Tabla con el mismo lenguaje visual de las tarjetas de formulario
// (encabezado con ícono, avatares, chips y pills). Ícono academic-cap.
const ICONO_DOCENTE =
    'M4.26 10.147a60.438 60.438 0 0 0-.491 6.347A48.627 48.627 0 0 1 12 20.904a48.627 48.627 0 0 1 8.232-4.41 60.46 60.46 0 0 0-.491-6.347m-15.482 0a50.57 50.57 0 0 0-2.658-.813A59.905 59.905 0 0 1 12 3.493a59.902 59.902 0 0 1 10.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.697 50.697 0 0 1 12 13.489a50.702 50.702 0 0 1 7.74-3.342M6.75 15a.75.75 0 1 0 0-1.5.75.75 0 0 0 0 1.5Zm0 0v-3.675A55.378 55.378 0 0 1 12 8.443m-7.007 11.55A5.981 5.981 0 0 0 6.75 15.75v-1.5';

function iniciales(nombre: string | null): string {
    if (!nombre) return '—';
    const partes = nombre.trim().split(/\s+/);
    return ((partes[0]?.[0] ?? '') + (partes[1]?.[0] ?? '')).toUpperCase() || '—';
}

function colorSituacion(clave: string | null): string {
    return clave === 'baja' ? '#dc2626' : '#16a34a';
}

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
            titulo="Docentes"
            descripcion="Listado del personal docente"
            :icono="ICONO_DOCENTE"
        >
            <template #conteo>
                <span class="rounded-full px-3 py-1 text-xs font-medium" :style="{ backgroundColor: 'color-mix(in srgb, var(--color-acento) 12%, transparent)', color: 'var(--color-acento)' }">
                    {{ docentes.total }} en total
                </span>
            </template>
        </BarraListado>

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

        <template v-else>
            <section class="tarjeta overflow-hidden">
                <div class="overflow-x-auto">
                    <table v-if="docentes.data.length" class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-[11px] uppercase tracking-wider" :style="{ color: 'var(--color-suave)', backgroundColor: 'color-mix(in srgb, var(--color-suave) 6%, transparent)' }">
                                <th class="px-6 py-3 font-semibold">Docente</th>
                                <th class="px-4 py-3 font-semibold">Clave / Cédula</th>
                                <th class="px-4 py-3 font-semibold">Tipo</th>
                                <th class="px-4 py-3 font-semibold">Campus</th>
                                <th class="px-4 py-3 font-semibold text-center">Materias</th>
                                <th class="px-4 py-3 font-semibold">Situación</th>
                                <th class="px-6 py-3 font-semibold text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="docente in docentes.data"
                                :key="docente.id"
                                class="fila-nueva group border-t transition-colors"
                                :class="docente.situacion_clave === 'baja' ? 'opacity-60' : ''"
                                :style="{ borderColor: 'var(--color-borde)' }"
                            >
                                <!-- Docente: avatar + nombre + email -->
                                <td class="px-6 py-4">
                                    <a :href="`/escolar/docentes/${docente.id}`" class="flex items-center gap-3">
                                        <img v-if="docente.foto" :src="docente.foto" alt="" class="h-10 w-10 rounded-full object-cover ring-2 ring-white/60" loading="lazy" />
                                        <span
                                            v-else
                                            class="grid h-10 w-10 shrink-0 place-items-center rounded-full text-xs font-semibold"
                                            :style="{ backgroundColor: 'color-mix(in srgb, var(--color-acento) 15%, transparent)', color: 'var(--color-acento)' }"
                                        >{{ iniciales(docente.nombre_completo) }}</span>
                                        <span class="min-w-0">
                                            <span class="block truncate font-semibold text-contenido">{{ docente.nombre_completo ?? '—' }}</span>
                                            <span v-if="docente.email" class="block truncate text-xs" :style="{ color: 'var(--color-suave)' }">{{ docente.email }}</span>
                                        </span>
                                    </a>
                                </td>

                                <!-- Clave / Cédula -->
                                <td class="px-4 py-4">
                                    <span class="inline-block rounded-md px-2 py-0.5 font-mono text-xs" :style="{ backgroundColor: 'color-mix(in srgb, var(--color-suave) 12%, transparent)' }">{{ docente.clave_profesor ?? '—' }}</span>
                                    <span class="mt-1 block font-mono text-[11px]" :style="{ color: 'var(--color-suave)' }">Céd. {{ docente.cedula_profesional ?? '—' }}</span>
                                </td>

                                <!-- Tipo -->
                                <td class="px-4 py-4">
                                    <span v-if="docente.tipo" class="text-xs">{{ docente.tipo }}</span>
                                    <span v-else :style="{ color: 'var(--color-suave)' }">—</span>
                                </td>

                                <!-- Campus (chips) -->
                                <td class="px-4 py-4">
                                    <span v-if="!docente.campus.length" :style="{ color: 'var(--color-suave)' }">—</span>
                                    <span v-else class="flex flex-wrap gap-1">
                                        <span
                                            v-for="c in docente.campus.slice(0, 2)"
                                            :key="c"
                                            class="rounded-full px-2 py-0.5 text-[11px]"
                                            :style="{ backgroundColor: 'color-mix(in srgb, var(--color-acento) 10%, transparent)', color: 'var(--color-acento)' }"
                                        >{{ c }}</span>
                                        <span v-if="docente.campus.length > 2" class="rounded-full px-2 py-0.5 text-[11px]" :style="{ color: 'var(--color-suave)' }">+{{ docente.campus.length - 2 }}</span>
                                    </span>
                                </td>

                                <!-- Materias (badge) -->
                                <td class="px-4 py-4 text-center">
                                    <span class="inline-grid h-7 min-w-7 place-items-center rounded-full px-2 text-xs font-semibold" :style="{ backgroundColor: 'color-mix(in srgb, var(--color-suave) 12%, transparent)' }">{{ docente.materias }}</span>
                                </td>

                                <!-- Situación (pill de color) + por revisar -->
                                <td class="px-4 py-4">
                                    <span
                                        class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium"
                                        :style="{ color: colorSituacion(docente.situacion_clave), backgroundColor: `color-mix(in srgb, ${colorSituacion(docente.situacion_clave)} 14%, transparent)` }"
                                    >
                                        <span class="inline-block h-1.5 w-1.5 rounded-full" :style="{ backgroundColor: colorSituacion(docente.situacion_clave) }" />
                                        {{ docente.situacion ?? '—' }}
                                    </span>
                                    <span
                                        v-if="docente.documentos_pendientes"
                                        class="mt-1 block text-[11px] font-medium"
                                        :style="{ color: '#b45309' }"
                                        :title="`${docente.documentos_pendientes} documento(s) por revisar`"
                                    >⚠ {{ docente.documentos_pendientes }} por revisar</span>
                                </td>

                                <!-- Acción -->
                                <td class="px-6 py-4 text-right">
                                    <a
                                        :href="`/escolar/docentes/${docente.id}`"
                                        class="inline-flex items-center gap-1 rounded-lg border px-3 py-1.5 text-xs font-medium transition-colors group-hover:border-transparent"
                                        :style="{ borderColor: 'var(--color-borde)', color: 'var(--color-acento)' }"
                                    >
                                        Ver ficha
                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
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
        </template>
    </AppLayout>
</template>

<style scoped>
/* Hover de fila en la tabla nueva (experimento): usa el token de acento, que no
   es expresable como clase utilitaria. */
.fila-nueva {
    cursor: default;
}
.fila-nueva:hover {
    background-color: color-mix(in srgb, var(--color-acento) 5%, transparent);
}
</style>
