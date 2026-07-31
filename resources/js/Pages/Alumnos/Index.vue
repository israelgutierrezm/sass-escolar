<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import NavEscolar from '@/Components/NavEscolar.vue';
import BarraListado from '@/Components/BarraListado.vue';
import Paginacion from '@/Components/Paginacion.vue';
import PildoraEstado from '@/Components/PildoraEstado.vue';
import TarjetaPersona from '@/Components/TarjetaPersona.vue';
import ZonaArchivo from '@/Components/ZonaArchivo.vue';

interface Alumno {
    id: number;
    matricula: string | null;
    nombre_completo: string | null;
    curp: string | null;
    email: string | null;
    foto: string | null;
    carrera: string | null;
    plan: string | null;
    carreras_activas: number;
    campus: string[];
    situacion: string | null;
    estatus: string;
    generacion: string | null;
}

const ICONO_ALUMNO =
    'M4.26 10.147a60.438 60.438 0 0 0-.491 6.347A48.627 48.627 0 0 1 12 20.904a48.627 48.627 0 0 1 8.232-4.41 60.46 60.46 0 0 0-.491-6.347m-15.482 0a50.57 50.57 0 0 0-2.658-.813A59.905 59.905 0 0 1 12 3.493a59.902 59.902 0 0 1 10.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.697 50.697 0 0 1 12 13.489a50.702 50.702 0 0 1 7.74-3.342M6.75 15a.75.75 0 1 0 0-1.5.75.75 0 0 0 0 1.5Zm0 0v-3.675A55.378 55.378 0 0 1 12 8.443m-7.007 11.55A5.981 5.981 0 0 0 6.75 15.75v-1.5';

function iniciales(nombre: string | null): string {
    if (!nombre) return '—';
    const partes = nombre.trim().split(/\s+/);
    return ((partes[0]?.[0] ?? '') + (partes[1]?.[0] ?? '')).toUpperCase() || '—';
}

// Texto de campus: uno → "Campus: X"; varios → "Múltiples campus: A, B". Se
// acota a los campus que el rol del usuario puede ver (lo resuelve el backend).
function textoCampus(campus: string[]): string {
    if (campus.length === 0) return '—';
    if (campus.length === 1) return `Campus: ${campus[0]}`;
    return `Múltiples campus: ${campus.join(', ')}`;
}

const props = defineProps<{
    alumnos: { data: Alumno[]; links: { url: string | null; label: string; active: boolean }[]; total: number; from: number | null; to: number | null };
    filtros: Record<string, any>;
    carreras: { id: number; nombre: string }[];
    campus: { id: number; nombre: string }[];
    situaciones: { id: number; nombre: string }[];
    puedeEditar: boolean;
    puedeRegistrar: boolean;
}>();

const vista = ref<'lista' | 'cuadricula'>('lista');

const definicionFiltros = [
    { clave: 'carrera_id', etiqueta: 'Carrera', opciones: props.carreras.map((c) => ({ valor: c.id, texto: c.nombre })) },
    { clave: 'campus_id', etiqueta: 'Campus', opciones: props.campus.map((c) => ({ valor: c.id, texto: c.nombre })) },
    { clave: 'situacion_id', etiqueta: 'Situación', opciones: props.situaciones.map((s) => ({ valor: s.id, texto: s.nombre })) },
    {
        clave: 'estatus',
        etiqueta: 'Estatus',
        opciones: [
            { valor: 'activo', texto: 'Activo' },
            { valor: 'egresado', texto: 'Egresado' },
            { valor: 'baja', texto: 'Baja' },
        ],
    },
];

// Color SÓLIDO por estatus (texto + punto de la píldora; el fondo es su tinte).
const colorEstatus: Record<string, string> = {
    activo: '#16a34a',
    egresado: 'var(--color-acento)',
    baja: '#dc2626',
};

// --- Carga masiva por Excel ---
const page = usePage();
const erroresCarga = computed(() => ((page.props as any).flash?.erroresCarga ?? []) as { hoja: string; fila: number; mensaje: string }[]);
const mostrarCarga = ref(false);
const carga = useForm<{ archivo: File | null }>({ archivo: null });

function subirExcel(archivo: File | null): void {
    if (!archivo) return;
    carga.archivo = archivo;
    carga.post('/escolar/alumnos/carga/importar', {
        forceFormData: true,
        preserveScroll: true,
        onFinish: () => carga.reset(),
    });
}
</script>

<template>
    <Head title="Alumnos" />

    <AppLayout titulo="Alumnos">
        <NavEscolar
            :secciones="[
                { etiqueta: 'Listado', url: '/escolar/alumnos', permiso: 'ver-alumnos' },
            ]"
        />

        <BarraListado
            v-model:vista="vista"
            url="/escolar/alumnos"
            vista-clave="alumnos"
            :valores="filtros"
            :filtros="definicionFiltros"
            placeholder="Matrícula, nombre o CURP…"
            :puede-crear="puedeRegistrar"
            nuevo-texto="Registrar alumno"
            nuevo-href="/escolar/alumnos/registrar"
            titulo="Alumnos"
            descripcion="Listado del alumnado"
            :icono="ICONO_ALUMNO"
        >
            <template #conteo>
                <span class="rounded-full px-3 py-1 text-xs font-medium" :style="{ backgroundColor: 'color-mix(in srgb, var(--color-acento) 12%, transparent)', color: 'var(--color-acento)' }">
                    {{ alumnos.total }} en total
                </span>
            </template>
        </BarraListado>

        <!-- Carga masiva por Excel -->
        <section v-if="puedeEditar" class="tarjeta p-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="text-sm" :style="{ color: 'var(--color-suave)' }">
                    Carga alumnos desde Excel. Con la variante «con calificaciones» también llenas su kárdex.
                </p>
                <button
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-xs font-medium"
                    :style="{ borderColor: '#16a34a', color: '#16a34a', backgroundColor: 'color-mix(in srgb, #16a34a 8%, transparent)' }"
                    @click="mostrarCarga = !mostrarCarga"
                >
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5" /></svg>
                    {{ mostrarCarga ? 'Ocultar' : 'Cargar desde Excel' }}
                </button>
            </div>

            <div v-if="mostrarCarga" class="mt-4 space-y-4 border-t pt-4" :style="{ borderColor: 'var(--color-borde)' }">
                <div class="flex flex-wrap gap-4">
                    <a href="/escolar/alumnos/carga/plantilla" class="inline-flex items-center gap-2 text-sm font-medium" :style="{ color: 'var(--color-acento)' }">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-6L12 15m0 0 4.5-4.5M12 15V3" /></svg>
                        Plantilla solo alumnos (.xlsx)
                    </a>
                    <a href="/escolar/alumnos/carga/plantilla?variante=calificaciones" class="inline-flex items-center gap-2 text-sm font-medium" :style="{ color: 'var(--color-acento)' }">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-6L12 15m0 0 4.5-4.5M12 15V3" /></svg>
                        Plantilla con calificaciones (.xlsx)
                    </a>
                </div>
                <ZonaArchivo
                    accept=".xlsx"
                    texto="Arrastra la plantilla llena (.xlsx) o haz clic para seleccionarla"
                    ayuda="Se valida todo antes de crear nada. Detecta sola si trae calificaciones."
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

        <!-- Cuadrícula -->
        <template v-if="vista === 'cuadricula'">
            <section v-if="alumnos.data.length" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                <TarjetaPersona
                    v-for="alumno in alumnos.data"
                    :key="alumno.id"
                    :nombre="alumno.nombre_completo"
                    :identificador="alumno.matricula"
                    :foto="alumno.foto"
                    :lineas="[alumno.carreras_activas >= 2 ? `${alumno.carreras_activas} carreras activas` : alumno.carrera, textoCampus(alumno.campus)]"
                    :estado="alumno.estatus"
                    :color-estado="colorEstatus[alumno.estatus]"
                    :atenuada="alumno.estatus === 'baja'"
                    :url="`/escolar/alumnos/${alumno.id}`"
                />
            </section>

            <section v-if="alumnos.links.length > 3" class="tarjeta">
                <Paginacion :enlaces="alumnos.links" :total="alumnos.total" :desde="alumnos.from" :hasta="alumnos.to" />
            </section>
        </template>

        <!-- Lista -->
        <template v-else>
            <section class="tarjeta overflow-hidden">
                <div class="overflow-x-auto">
                    <table v-if="alumnos.data.length" class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-[11px] uppercase tracking-wider" :style="{ color: 'var(--color-suave)', backgroundColor: 'color-mix(in srgb, var(--color-suave) 6%, transparent)' }">
                                <th class="px-6 py-3 font-semibold">Alumno</th>
                                <th class="px-4 py-3 font-semibold">Matrícula / CURP</th>
                                <th class="px-4 py-3 font-semibold">Carreras</th>
                                <th class="px-4 py-3 font-semibold">Campus</th>
                                <th class="px-4 py-3 font-semibold">Estatus</th>
                                <th class="px-6 py-3 font-semibold text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="alumno in alumnos.data"
                                :key="alumno.id"
                                class="fila-nueva group border-t transition-colors"
                                :class="alumno.estatus === 'baja' ? 'opacity-60' : ''"
                                :style="{ borderColor: 'var(--color-borde)' }"
                            >
                                <!-- Alumno: avatar + nombre + email -->
                                <td class="px-6 py-4">
                                    <a :href="`/escolar/alumnos/${alumno.id}`" class="flex items-center gap-3">
                                        <img v-if="alumno.foto" :src="alumno.foto" alt="" class="h-10 w-10 rounded-full object-cover ring-1 ring-black/5" loading="lazy" />
                                        <span
                                            v-else
                                            class="grid h-10 w-10 shrink-0 place-items-center rounded-full text-xs font-semibold"
                                            :style="{ backgroundColor: 'color-mix(in srgb, var(--color-acento) 15%, transparent)', color: 'var(--color-acento)' }"
                                        >{{ iniciales(alumno.nombre_completo) }}</span>
                                        <span class="min-w-0">
                                            <span class="block truncate font-semibold text-contenido">{{ alumno.nombre_completo ?? '—' }}</span>
                                            <span v-if="alumno.email" class="block truncate text-xs" :style="{ color: 'var(--color-suave)' }">{{ alumno.email }}</span>
                                        </span>
                                    </a>
                                </td>

                                <!-- Matrícula / CURP -->
                                <td class="px-4 py-4">
                                    <span class="inline-block rounded-md px-2 py-0.5 font-mono text-xs" :style="{ backgroundColor: 'color-mix(in srgb, var(--color-suave) 12%, transparent)' }">{{ alumno.matricula ?? '—' }}</span>
                                    <span v-if="alumno.curp" class="mt-1 block font-mono text-[11px]" :style="{ color: 'var(--color-suave)' }">{{ alumno.curp }}</span>
                                </td>

                                <!-- Carreras -->
                                <td class="px-4 py-4">
                                    <template v-if="alumno.carreras_activas >= 2">
                                        <span class="rounded-full px-2 py-0.5 text-[11px] font-medium" :style="{ backgroundColor: 'color-mix(in srgb, var(--color-acento) 12%, transparent)', color: 'var(--color-acento)' }">{{ alumno.carreras_activas }} carreras</span>
                                    </template>
                                    <template v-else>
                                        <span class="text-xs">{{ alumno.carrera ?? '—' }}</span>
                                        <span v-if="alumno.plan" class="mt-0.5 block text-[11px]" :style="{ color: 'var(--color-suave)' }">{{ alumno.plan }}</span>
                                    </template>
                                </td>

                                <!-- Campus (chips) -->
                                <td class="px-4 py-4">
                                    <span v-if="!alumno.campus.length" :style="{ color: 'var(--color-suave)' }">—</span>
                                    <span v-else class="flex flex-wrap gap-1">
                                        <span
                                            v-for="c in alumno.campus.slice(0, 2)"
                                            :key="c"
                                            class="rounded-full px-2 py-0.5 text-[11px]"
                                            :style="{ backgroundColor: 'color-mix(in srgb, var(--color-acento) 10%, transparent)', color: 'var(--color-acento)' }"
                                        >{{ c }}</span>
                                        <span v-if="alumno.campus.length > 2" class="rounded-full px-2 py-0.5 text-[11px]" :style="{ color: 'var(--color-suave)' }">+{{ alumno.campus.length - 2 }}</span>
                                    </span>
                                </td>

                                <!-- Estatus -->
                                <td class="px-4 py-4">
                                    <PildoraEstado :texto="alumno.estatus" :color="colorEstatus[alumno.estatus]" />
                                </td>

                                <!-- Acción -->
                                <td class="px-6 py-4 text-right">
                                    <a
                                        :href="`/escolar/alumnos/${alumno.id}`"
                                        class="btn-ficha inline-flex items-center gap-1 rounded-lg border px-3 py-1.5 text-xs font-medium transition-colors"
                                        :style="{ borderColor: 'var(--color-borde)', color: 'var(--color-acento)' }"
                                    >
                                        Expediente
                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
                                    </a>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <p v-else class="px-6 py-12 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                        {{ filtros.busqueda ? `Nadie coincide con "${filtros.busqueda}".` : 'Todavía no hay alumnos matriculados.' }}
                    </p>
                </div>

                <Paginacion :enlaces="alumnos.links" :total="alumnos.total" :desde="alumnos.from" :hasta="alumnos.to" />
            </section>
        </template>
    </AppLayout>
</template>

<style scoped>
.fila-nueva:hover {
    background-color: color-mix(in srgb, var(--color-acento) 5%, transparent);
}
.fila-nueva:hover .btn-ficha {
    border-color: transparent;
    background-color: color-mix(in srgb, var(--color-acento) 12%, transparent);
}
</style>
