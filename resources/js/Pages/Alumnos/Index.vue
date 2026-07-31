<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import NavEscolar from '@/Components/NavEscolar.vue';
import BarraListado from '@/Components/BarraListado.vue';
import Paginacion from '@/Components/Paginacion.vue';
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

const colorEstatus: Record<string, string> = {
    activo: 'color-mix(in srgb, #16a34a 16%, transparent)',
    egresado: 'color-mix(in srgb, var(--color-acento) 14%, transparent)',
    baja: 'color-mix(in srgb, #dc2626 14%, transparent)',
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

        <!-- Carga masiva por Excel -->
        <section v-if="puedeEditar" class="tarjeta mb-4 p-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="text-sm" :style="{ color: 'var(--color-suave)' }">
                    Carga alumnos desde Excel. Con la variante «con calificaciones» también llenas su kárdex.
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

        <div v-if="puedeRegistrar" class="mb-3 flex justify-end">
            <Link
                href="/escolar/alumnos/registrar"
                class="inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-medium text-white"
                :style="{ backgroundColor: 'var(--color-acento)' }"
            >
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                Registrar alumno
            </Link>
        </div>

        <BarraListado
            v-model:vista="vista"
            url="/escolar/alumnos"
            vista-clave="alumnos"
            :valores="filtros"
            :filtros="definicionFiltros"
            placeholder="Matrícula, nombre o CURP…"
        />

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
        <section v-else class="tarjeta overflow-hidden">
            <div class="overflow-x-auto">
                <table v-if="alumnos.data.length" class="w-full text-sm">
                    <thead class="text-left text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">
                        <tr>
                            <th class="px-6 py-3 font-medium">Matrícula</th>
                            <th class="px-4 py-3 font-medium">Alumno</th>
                            <th class="px-4 py-3 font-medium">CURP</th>
                            <th class="px-4 py-3 font-medium">Carreras</th>
                            <th class="px-4 py-3 font-medium">Campus</th>
                            <th class="px-4 py-3 font-medium">Estatus</th>
                            <th class="px-6 py-3 font-medium text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="alumno in alumnos.data"
                            :key="alumno.id"
                            class="border-t"
                            :style="{ borderColor: 'var(--color-borde)' }"
                        >
                            <td class="px-6 py-3 font-mono text-xs">{{ alumno.matricula }}</td>
                            <td class="px-4 py-3">
                                <span class="flex items-center gap-2">
                                    <img v-if="alumno.foto" :src="alumno.foto" alt="" class="h-8 w-8 rounded-full object-cover" loading="lazy" />
                                    <span>
                                        <span class="font-medium">{{ alumno.nombre_completo }}</span>
                                        <span v-if="alumno.email" class="block text-xs" :style="{ color: 'var(--color-suave)' }">
                                            {{ alumno.email }}
                                        </span>
                                    </span>
                                </span>
                            </td>
                            <td class="px-4 py-3 font-mono text-xs">{{ alumno.curp ?? '—' }}</td>
                            <td class="px-4 py-3">
                                <template v-if="alumno.carreras_activas >= 2">
                                    <span class="font-medium">{{ alumno.carreras_activas }} carreras activas</span>
                                </template>
                                <template v-else>
                                    {{ alumno.carrera ?? '—' }}
                                    <span v-if="alumno.plan" class="block text-xs" :style="{ color: 'var(--color-suave)' }">
                                        {{ alumno.plan }}
                                    </span>
                                </template>
                            </td>
                            <td class="px-4 py-3">{{ textoCampus(alumno.campus) }}</td>
                            <td class="px-4 py-3">
                                <span class="rounded-full px-2 py-0.5 text-xs capitalize" :style="{ backgroundColor: colorEstatus[alumno.estatus] ?? 'transparent' }">
                                    {{ alumno.estatus }}
                                </span>
                            </td>
                            <td class="px-6 py-3 text-right">
                                <a :href="`/escolar/alumnos/${alumno.id}`" class="text-sm font-medium" :style="{ color: 'var(--color-acento)' }">
                                    Expediente
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
    </AppLayout>
</template>
