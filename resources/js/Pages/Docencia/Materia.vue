<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';

interface Alumno {
    matricula: string | null;
    nombre: string | null;
    email: string | null;
    celular: string | null;
    tipo: string;
    situacion: string | null;
    de_baja: boolean;
    calificacion_final: string | null;
}

const props = defineProps<{
    materia: Record<string, any>;
    horarios: { dia: number; inicio: string; fin: string; aula: string | null }[];
    companeros: { nombre: string | null; tipo: string }[];
    alumnos: Alumno[];
    calendario: Record<string, { abierto: boolean; motivo: string | null }>;
    puedeCapturar: boolean;
}>();

const dias = ['', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo'];

const busqueda = ref('');

/** Con cuarenta alumnos, buscar por apellido es más rápido que recorrer. */
const visibles = computed(() => {
    const termino = busqueda.value.trim().toLowerCase();

    if (termino === '') {
        return props.alumnos;
    }

    return props.alumnos.filter(
        (a) =>
            (a.nombre ?? '').toLowerCase().includes(termino) ||
            (a.matricula ?? '').toLowerCase().includes(termino),
    );
});

const activos = computed(() => props.alumnos.filter((a) => !a.de_baja).length);

const cortesCerrados = computed(() =>
    Object.values(props.calendario)
        .filter((c) => !c.abierto)
        .map((c) => c.motivo)
        .filter((m): m is string => m !== null),
);
</script>

<template>
    <Head :title="materia.nombre ?? 'Materia'" />

    <AppLayout :titulo="materia.nombre ?? 'Materia'">
        <section class="tarjeta p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="font-mono text-sm" :style="{ color: 'var(--color-suave)' }">
                        {{ materia.clave_en_plan }}
                    </p>
                    <h2 class="text-lg font-semibold">{{ materia.nombre }}</h2>
                    <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                        Grupo {{ materia.grupo }} · ciclo {{ materia.ciclo }}
                        <span v-if="materia.campus"> · {{ materia.campus }}</span>
                        <span v-if="materia.plan"> · {{ materia.plan }}</span>
                    </p>
                    <p class="mt-1 text-sm">
                        Eres <span class="font-medium">{{ materia.soy }}</span> de esta materia.
                        <span v-if="materia.soy === 'adjunto'" :style="{ color: 'var(--color-suave)' }">
                            Puedes capturar, pero el acta la firma el titular.
                        </span>
                    </p>
                </div>

                <div class="flex gap-2">
                    <a href="/docencia" class="text-sm" :style="{ color: 'var(--color-acento)' }">← Mis materias</a>
                </div>
            </div>

            <div
                class="mt-4 flex flex-wrap items-center gap-x-6 gap-y-1 border-t pt-4 text-sm"
                :style="{ borderColor: 'var(--color-borde)' }"
            >
                <span v-if="horarios.length" :style="{ color: 'var(--color-suave)' }">
                    {{ horarios.map((h) => `${dias[h.dia] ?? ''} ${h.inicio}–${h.fin}${h.aula ? ' · ' + h.aula : ''}`).join(' | ') }}
                </span>
                <span v-else :style="{ color: 'var(--color-suave)' }">Sin horario cargado</span>

                <span v-if="companeros.length" :style="{ color: 'var(--color-suave)' }">
                    Con: {{ companeros.map((c) => `${c.nombre} (${c.tipo})`).join(', ') }}
                </span>
            </div>
        </section>

        <div v-if="cortesCerrados.length" class="tarjeta border-l-4 border-amber-500 p-4 text-sm">
            <p class="font-medium text-amber-700">Hay cortes fuera de fecha de captura.</p>
            <ul class="mt-1 space-y-0.5" :style="{ color: 'var(--color-suave)' }">
                <li v-for="motivo in cortesCerrados" :key="motivo">{{ motivo }}</li>
            </ul>
        </div>

        <section class="tarjeta overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b px-6 py-3" :style="{ borderColor: 'var(--color-borde)' }">
                <div>
                    <h2 class="text-base font-semibold">Alumnos ({{ activos }})</h2>
                    <p v-if="alumnos.length !== activos" class="mt-0.5 text-xs" :style="{ color: 'var(--color-suave)' }">
                        {{ alumnos.length - activos }} de baja, se muestran al final.
                    </p>
                </div>

                <div class="flex items-center gap-2">
                    <input
                        v-model="busqueda"
                        type="search"
                        placeholder="Buscar por nombre o matrícula…"
                        class="rounded-lg border px-3 py-1.5 text-sm"
                        :style="{ borderColor: 'var(--color-borde)' }"
                    />
                    <a
                        v-if="puedeCapturar"
                        :href="`/captura/${materia.id}`"
                        class="rounded-lg px-3 py-1.5 text-sm font-medium"
                        :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                    >
                        Capturar
                    </a>
                </div>
            </div>

            <table v-if="visibles.length" class="w-full text-sm">
                <thead class="text-left text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">
                    <tr>
                        <th class="px-6 py-3 font-medium">Matrícula</th>
                        <th class="px-4 py-3 font-medium">Alumno</th>
                        <th class="px-4 py-3 font-medium">Contacto</th>
                        <th class="px-4 py-3 font-medium">Situación</th>
                        <th class="px-4 py-3 font-medium">Final</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="alumno in visibles"
                        :key="alumno.matricula ?? alumno.nombre ?? ''"
                        class="border-t"
                        :class="alumno.de_baja ? 'opacity-50' : ''"
                        :style="{ borderColor: 'var(--color-borde)' }"
                    >
                        <td class="px-6 py-2 font-mono text-xs">{{ alumno.matricula }}</td>
                        <td class="px-4 py-2">
                            {{ alumno.nombre }}
                            <span
                                v-if="alumno.tipo === 'recursamiento'"
                                class="ml-1 rounded-full px-2 py-0.5 text-xs"
                                style="background-color: color-mix(in srgb, #f59e0b 18%, transparent)"
                            >
                                recursa
                            </span>
                        </td>
                        <td class="px-4 py-2 text-xs" :style="{ color: 'var(--color-suave)' }">
                            <span v-if="alumno.email">{{ alumno.email }}</span>
                            <span v-if="alumno.email && alumno.celular"> · </span>
                            <span v-if="alumno.celular">{{ alumno.celular }}</span>
                            <span v-if="!alumno.email && !alumno.celular">—</span>
                        </td>
                        <td class="px-4 py-2">{{ alumno.situacion }}</td>
                        <td class="px-4 py-2">{{ alumno.calificacion_final ?? '—' }}</td>
                    </tr>
                </tbody>
            </table>

            <p v-else class="px-6 py-10 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                {{ busqueda ? 'Nadie coincide con la búsqueda.' : 'Todavía no hay alumnos inscritos.' }}
            </p>
        </section>
    </AppLayout>
</template>
