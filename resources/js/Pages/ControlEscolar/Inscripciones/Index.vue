<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import NavEscolar from '@/Components/NavEscolar.vue';
import CampoSelect from '@/Components/CampoSelect.vue';

interface Disponible {
    id: number;
    materia: string | null;
    clave_en_plan: string | null;
    periodo: number | null;
    grupo: string | null;
    titular: string | null;
    inscritos: number;
    cupo: number | null;
    impedimentos: string[];
    inscribible: boolean;
}

const props = defineProps<{
    alumnos: { id: number; etiqueta: string }[];
    ciclos: { id: number; etiqueta: string }[];
    seleccion: { matricula_oferta_id: number | null; ciclo_id: number | null };
    alumno: { matricula: string; nombre: string | null; carrera: string | null; plan: string | null } | null;
    inscritas: {
        id: number;
        materia: string | null;
        clave_en_plan: string | null;
        grupo: string | null;
        tipo: string;
        tipo_evaluacion: string | null;
        situacion: string | null;
        calificacion_final: string | null;
    }[];
    disponibles: Disponible[];
    tiposEvaluacion: { id: number; nombre: string }[];
    puedeInscribir: boolean;
}>();

const matriculaId = ref(props.seleccion.matricula_oferta_id);
const cicloId = ref(props.seleccion.ciclo_id);

watch([matriculaId, cicloId], () => {
    router.get(
        '/escolar/inscripciones',
        { matricula_oferta_id: matriculaId.value, ciclo_id: cicloId.value },
        { preserveState: true, replace: true },
    );
});

// Tipo de evaluación con el que se inscribe (ordinaria por defecto). Un solo
// selector para toda la lista de materias abiertas.
const tipoEvaluacionId = ref<number | null>(
    props.tiposEvaluacion.find((t) => t.nombre.toLowerCase().startsWith('ordinaria'))?.id
        ?? props.tiposEvaluacion[0]?.id
        ?? null,
);

const form = useForm({
    matricula_oferta_id: null as number | null,
    asignatura_grupo_id: null as number | null,
    tipo_evaluacion_id: null as number | null,
});

function inscribir(materia: Disponible): void {
    form.matricula_oferta_id = matriculaId.value;
    form.asignatura_grupo_id = materia.id;
    form.tipo_evaluacion_id = tipoEvaluacionId.value;
    form.post('/escolar/inscripciones', { preserveScroll: true });
}

function darDeBaja(id: number): void {
    if (!confirm('¿Dar de baja esta inscripción? Se conserva en el historial.')) {
        return;
    }

    router.put(`/escolar/inscripciones/${id}/baja`, {}, { preserveScroll: true });
}
</script>

<template>
    <Head title="Inscripciones" />

    <AppLayout titulo="Inscripciones">
        <NavEscolar />

        <!-- Selección -->
        <section class="tarjeta p-6">
            <div class="grid gap-4 sm:grid-cols-2">
                <CampoSelect
                    v-model="matriculaId"
                    etiqueta="Alumno"
                    :opciones="alumnos.map((a) => ({ valor: a.id, texto: a.etiqueta }))"
                    vacio="Selecciona un alumno…"
                />
                <CampoSelect
                    v-model="cicloId"
                    etiqueta="Ciclo"
                    :opciones="ciclos.map((c) => ({ valor: c.id, texto: c.etiqueta }))"
                    vacio="Selecciona un ciclo…"
                />
            </div>

            <div
                v-if="alumno"
                class="mt-4 flex flex-wrap items-center gap-x-6 gap-y-1 border-t pt-4 text-sm"
                :style="{ borderColor: 'var(--color-borde)' }"
            >
                <span class="font-mono text-xs" :style="{ color: 'var(--color-suave)' }">
                    {{ alumno.matricula }}
                </span>
                <span class="font-medium">{{ alumno.nombre }}</span>
                <span :style="{ color: 'var(--color-suave)' }">{{ alumno.carrera }}</span>
                <span :style="{ color: 'var(--color-suave)' }">{{ alumno.plan }}</span>
            </div>
        </section>

        <p
            v-if="!alumno || !seleccion.ciclo_id"
            class="tarjeta px-4 py-12 text-center text-sm"
            :style="{ color: 'var(--color-suave)' }"
        >
            Elige un alumno y un ciclo para ver su carga y las materias disponibles.
        </p>

        <template v-else>
            <!-- Carga actual -->
            <section class="tarjeta overflow-hidden">
                <div class="border-b px-6 py-3" :style="{ borderColor: 'var(--color-borde)' }">
                    <h2 class="text-base font-semibold">Carga del ciclo ({{ inscritas.length }})</h2>
                </div>

                <table v-if="inscritas.length" class="w-full text-sm">
                    <thead class="text-left text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">
                        <tr>
                            <th class="px-6 py-3 font-medium">Clave</th>
                            <th class="px-4 py-3 font-medium">Materia</th>
                            <th class="px-4 py-3 font-medium">Grupo</th>
                            <th class="px-4 py-3 font-medium">Tipo</th>
                            <th class="px-4 py-3 font-medium">Situación</th>
                            <th class="px-4 py-3 font-medium">Calif.</th>
                            <th class="px-6 py-3 font-medium text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="inscripcion in inscritas"
                            :key="inscripcion.id"
                            class="border-t transition"
                            :style="{ borderColor: 'var(--color-borde)' }"
                        >
                            <td class="px-6 py-3 font-mono text-xs">{{ inscripcion.clave_en_plan }}</td>
                            <td class="px-4 py-3 font-medium">{{ inscripcion.materia }}</td>
                            <td class="px-4 py-3">{{ inscripcion.grupo }}</td>
                            <td class="px-4 py-3">
                                <span
                                    class="rounded-full px-2 py-0.5 text-xs"
                                    :style="{
                                        backgroundColor:
                                            inscripcion.tipo === 'recursamiento'
                                                ? 'color-mix(in srgb, #f59e0b 18%, transparent)'
                                                : 'color-mix(in srgb, var(--color-acento) 12%, transparent)',
                                    }"
                                >
                                    {{ inscripcion.tipo_evaluacion ?? inscripcion.tipo }}
                                </span>
                            </td>
                            <td class="px-4 py-3">{{ inscripcion.situacion }}</td>
                            <td class="px-4 py-3">{{ inscripcion.calificacion_final ?? '—' }}</td>
                            <td class="px-6 py-3 text-right">
                                <button
                                    v-if="puedeInscribir && inscripcion.situacion !== 'Baja'"
                                    type="button"
                                    class="text-sm transition hover:text-red-600"
                                    :style="{ color: 'var(--color-suave)' }"
                                    @click="darDeBaja(inscripcion.id)"
                                >
                                    Dar de baja
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <p v-else class="px-6 py-8 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                    Sin materias inscritas en este ciclo.
                </p>
            </section>

            <!-- Disponibles -->
            <section class="tarjeta overflow-hidden">
                <div class="flex flex-wrap items-end justify-between gap-4 border-b px-6 py-3" :style="{ borderColor: 'var(--color-borde)' }">
                    <div>
                        <h2 class="text-base font-semibold">Materias abiertas en el ciclo</h2>
                        <p class="mt-0.5 text-sm" :style="{ color: 'var(--color-suave)' }">
                            Cada materia se valida contra seriación, cupo, horario y la ventana del ciclo.
                        </p>
                    </div>
                    <div v-if="puedeInscribir" class="w-full sm:w-64">
                        <CampoSelect
                            v-model="tipoEvaluacionId"
                            etiqueta="Inscribir como"
                            :opciones="tiposEvaluacion.map((t) => ({ valor: t.id, texto: t.nombre }))"
                        />
                    </div>
                </div>

                <ul v-if="disponibles.length">
                    <li
                        v-for="materia in disponibles"
                        :key="materia.id"
                        class="border-t px-6 py-4 transition"
                        :style="{ borderColor: 'var(--color-borde)' }"
                    >
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-sm font-medium">
                                    <span class="font-mono text-xs" :style="{ color: 'var(--color-suave)' }">
                                        {{ materia.clave_en_plan }}
                                    </span>
                                    · {{ materia.materia }}
                                </p>
                                <p class="mt-0.5 text-xs" :style="{ color: 'var(--color-suave)' }">
                                    Grupo {{ materia.grupo }}
                                    <span v-if="materia.periodo"> · periodo {{ materia.periodo }}</span>
                                    · {{ materia.inscritos }}<span v-if="materia.cupo">/{{ materia.cupo }}</span>
                                    inscritos
                                    <span v-if="materia.titular"> · {{ materia.titular }}</span>
                                </p>

                                <!-- Por qué no se puede -->
                                <ul v-if="materia.impedimentos.length" class="mt-2 space-y-1">
                                    <li
                                        v-for="impedimento in materia.impedimentos"
                                        :key="impedimento"
                                        class="flex items-start gap-1.5 text-xs text-amber-600"
                                    >
                                        <svg class="mt-0.5 h-3.5 w-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
                                        </svg>
                                        {{ impedimento }}
                                    </li>
                                </ul>
                            </div>

                            <div v-if="puedeInscribir" class="flex shrink-0 gap-2">
                                <button
                                    type="button"
                                    :disabled="!materia.inscribible || form.processing"
                                    class="rounded-lg px-3 py-1.5 text-sm font-medium transition duration-200 disabled:cursor-not-allowed disabled:opacity-40"
                                    :style="{
                                        backgroundColor: 'var(--color-acento)',
                                        color: 'var(--color-acento-texto)',
                                    }"
                                    @click="inscribir(materia)"
                                >
                                    Inscribir
                                </button>
                            </div>
                        </div>
                    </li>
                </ul>

                <p v-else class="px-6 py-8 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                    No hay materias abiertas en este ciclo.
                </p>
            </section>
        </template>
    </AppLayout>
</template>
