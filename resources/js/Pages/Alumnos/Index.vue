<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import NavEscolar from '@/Components/NavEscolar.vue';
import PanelFiltros from '@/Components/PanelFiltros.vue';
import Paginacion from '@/Components/Paginacion.vue';
import SelectorVista from '@/Components/SelectorVista.vue';
import TarjetaPersona from '@/Components/TarjetaPersona.vue';

interface Alumno {
    id: number;
    matricula: string | null;
    nombre_completo: string | null;
    curp: string | null;
    email: string | null;
    foto: string | null;
    carrera: string | null;
    plan: string | null;
    campus: string | null;
    situacion: string | null;
    estatus: string;
    generacion: string | null;
}

const props = defineProps<{
    alumnos: { data: Alumno[]; links: { url: string | null; label: string; active: boolean }[]; total: number; from: number | null; to: number | null };
    filtros: Record<string, any>;
    carreras: { id: number; nombre: string }[];
    campus: { id: number; nombre: string }[];
    situaciones: { id: number; nombre: string }[];
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
        '/escolar/alumnos',
        {
            busqueda: busqueda.value || undefined,
            carrera_id: props.filtros.carrera_id || undefined,
            campus_id: props.filtros.campus_id || undefined,
            situacion_id: props.filtros.situacion_id || undefined,
            estatus: props.filtros.estatus || undefined,
            ...cambios,
        },
        { preserveState: true, replace: true, preserveScroll: true },
    );
}

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
</script>

<template>
    <Head title="Alumnos" />

    <AppLayout titulo="Alumnos">
        <NavEscolar
            :secciones="[
                { etiqueta: 'Listado', url: '/escolar/alumnos', permiso: 'ver-alumnos' },
                { etiqueta: 'Inscripciones', url: '/escolar/inscripciones', permiso: 'inscribir-alumnos' },
            ]"
        />

        <section class="tarjeta p-6">
            <div class="flex flex-wrap items-end gap-3">
                <div class="min-w-64 flex-1">
                    <label class="block text-sm font-medium">Buscar</label>
                    <input
                        v-model="busqueda"
                        type="search"
                        placeholder="Matrícula, nombre o CURP…"
                        class="mt-1 w-full rounded-lg border px-3 py-2 text-sm"
                        :style="{ borderColor: 'var(--color-borde)' }"
                    />
                </div>
                <SelectorVista v-model="vista" clave="alumnos" />
            </div>

            <div class="mt-4">
                <PanelFiltros
                    :filtros="definicionFiltros"
                    :valores="filtros"
                    @cambio="(valores) => consultar(valores)"
                />
            </div>

            <p class="mt-4 text-sm" :style="{ color: 'var(--color-suave)' }">
                <template v-if="alumnos.total">{{ alumnos.from }}–{{ alumnos.to }} de {{ alumnos.total }}</template>
                <template v-else>Sin resultados</template>
            </p>
        </section>

        <!-- Cuadrícula -->
        <template v-if="vista === 'cuadricula'">
            <section v-if="alumnos.data.length" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <TarjetaPersona
                    v-for="alumno in alumnos.data"
                    :key="alumno.id"
                    :nombre="alumno.nombre_completo"
                    :identificador="alumno.matricula"
                    :foto="alumno.foto"
                    :lineas="[alumno.carrera, alumno.campus]"
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
                            <th class="px-4 py-3 font-medium">Carrera</th>
                            <th class="px-4 py-3 font-medium">Campus</th>
                            <th class="px-4 py-3 font-medium">Estatus</th>
                            <th class="px-6 py-3"></th>
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
                                {{ alumno.carrera ?? '—' }}
                                <span v-if="alumno.plan" class="block text-xs" :style="{ color: 'var(--color-suave)' }">
                                    {{ alumno.plan }}
                                </span>
                            </td>
                            <td class="px-4 py-3">{{ alumno.campus ?? '—' }}</td>
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
