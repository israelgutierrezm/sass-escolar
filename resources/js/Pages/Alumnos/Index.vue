<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import NavEscolar from '@/Components/NavEscolar.vue';
import CampoSelect from '@/Components/CampoSelect.vue';

interface Alumno {
    id: number;
    matricula: string | null;
    nombre_completo: string | null;
    curp: string | null;
    email: string | null;
    carrera: string | null;
    plan: string | null;
    campus: string | null;
    situacion: string | null;
    estatus: string;
    generacion: string | null;
}

const props = defineProps<{
    alumnos: { data: Alumno[]; links: { url: string | null; label: string; active: boolean }[]; total: number; from: number | null; to: number | null };
    filtros: { busqueda: string; carrera_id: number | null; campus_id: number | null; situacion_id: number | null; estatus: string | null };
    carreras: { id: number; nombre: string }[];
    campus: { id: number; nombre: string }[];
    situaciones: { id: number; nombre: string }[];
    puedeEditar: boolean;
}>();

const busqueda = ref(props.filtros.busqueda);
const carreraId = ref(props.filtros.carrera_id);
const campusId = ref(props.filtros.campus_id);
const situacionId = ref(props.filtros.situacion_id);
const estatus = ref(props.filtros.estatus);

let temporizador: ReturnType<typeof setTimeout> | undefined;

/**
 * La búsqueda espera a que dejes de teclear. Sin esta pausa, escribir una
 * matrícula de diez dígitos dispararía diez consultas y la lista parpadearía
 * en cada tecla.
 */
watch(busqueda, () => {
    clearTimeout(temporizador);
    temporizador = setTimeout(consultar, 350);
});

watch([carreraId, campusId, situacionId, estatus], consultar);

function consultar(): void {
    router.get(
        '/escolar/alumnos',
        {
            busqueda: busqueda.value || undefined,
            carrera_id: carreraId.value || undefined,
            campus_id: campusId.value || undefined,
            situacion_id: situacionId.value || undefined,
            estatus: estatus.value || undefined,
        },
        { preserveState: true, replace: true, preserveScroll: true },
    );
}

function limpiar(): void {
    busqueda.value = '';
    carreraId.value = null;
    campusId.value = null;
    situacionId.value = null;
    estatus.value = null;
}

const colorEstatus: Record<string, string> = {
    activo: 'color-mix(in srgb, #16a34a 16%, transparent)',
    egresado: 'color-mix(in srgb, var(--color-acento) 14%, transparent)',
    baja: 'color-mix(in srgb, #dc2626 14%, transparent)',
};
</script>

<template>
    <Head title="Alumnos" />

    <AppLayout titulo="Alumnos">
        <NavEscolar />

        <section class="tarjeta p-6">
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                <div class="lg:col-span-2">
                    <label class="block text-sm font-medium">Buscar</label>
                    <input
                        v-model="busqueda"
                        type="search"
                        placeholder="Matrícula, nombre o CURP…"
                        class="mt-1 w-full rounded-lg border px-3 py-2 text-sm"
                        :style="{ borderColor: 'var(--color-borde)' }"
                    />
                </div>

                <CampoSelect
                    v-model="carreraId"
                    etiqueta="Carrera"
                    :opciones="carreras.map((c) => ({ valor: c.id, texto: c.nombre }))"
                    vacio="Todas"
                />
                <CampoSelect
                    v-model="campusId"
                    etiqueta="Campus"
                    :opciones="campus.map((c) => ({ valor: c.id, texto: c.nombre }))"
                    vacio="Todos"
                />
                <CampoSelect
                    v-model="estatus"
                    etiqueta="Estatus"
                    :opciones="[
                        { valor: 'activo', texto: 'Activo' },
                        { valor: 'egresado', texto: 'Egresado' },
                        { valor: 'baja', texto: 'Baja' },
                    ]"
                    vacio="Cualquiera"
                />
            </div>

            <div class="mt-4 flex flex-wrap items-center justify-between gap-3 text-sm">
                <span :style="{ color: 'var(--color-suave)' }">
                    <template v-if="alumnos.total">
                        {{ alumnos.from }}–{{ alumnos.to }} de {{ alumnos.total }}
                    </template>
                    <template v-else>Sin resultados</template>
                </span>
                <button
                    v-if="filtros.busqueda || filtros.carrera_id || filtros.campus_id || filtros.estatus"
                    type="button"
                    class="text-sm"
                    :style="{ color: 'var(--color-acento)' }"
                    @click="limpiar"
                >
                    Limpiar filtros
                </button>
            </div>
        </section>

        <section class="tarjeta overflow-hidden">
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
                                <span class="font-medium">{{ alumno.nombre_completo }}</span>
                                <span v-if="alumno.email" class="block text-xs" :style="{ color: 'var(--color-suave)' }">
                                    {{ alumno.email }}
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
                                <span
                                    class="rounded-full px-2 py-0.5 text-xs capitalize"
                                    :style="{ backgroundColor: colorEstatus[alumno.estatus] ?? 'transparent' }"
                                >
                                    {{ alumno.estatus }}
                                </span>
                            </td>
                            <td class="px-6 py-3 text-right">
                                <a
                                    :href="`/escolar/alumnos/${alumno.id}`"
                                    class="text-sm font-medium"
                                    :style="{ color: 'var(--color-acento)' }"
                                >
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

            <nav v-if="alumnos.links.length > 3" class="flex flex-wrap gap-1 border-t px-6 py-3" :style="{ borderColor: 'var(--color-borde)' }">
                <component
                    :is="enlace.url ? 'a' : 'span'"
                    v-for="enlace in alumnos.links"
                    :key="enlace.label"
                    :href="enlace.url ?? undefined"
                    class="rounded-lg px-3 py-1.5 text-sm"
                    :class="enlace.url ? '' : 'opacity-40'"
                    :style="
                        enlace.active
                            ? { backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }
                            : { color: 'var(--color-suave)' }
                    "
                    v-html="enlace.label"
                />
            </nav>
        </section>
    </AppLayout>
</template>
