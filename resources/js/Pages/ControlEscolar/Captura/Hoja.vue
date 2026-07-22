<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import NavEscolar from '@/Components/NavEscolar.vue';

interface Componente {
    id: number;
    componente: string;
    parcial: number | null;
    porcentaje: number;
}

interface Alumno {
    inscripcion_id: number;
    matricula: string | null;
    nombre: string | null;
    tipo: string;
    calificaciones: Record<string, number | null>;
    final: number | null;
    completa: boolean;
    aprobada: boolean | null;
}

const props = defineProps<{
    materia: Record<string, any>;
    escala: { minima: number | null; maxima: number | null; aprobatoria: number | null };
    componentes: Componente[];
    alumnos: Alumno[];
    actas: {
        id: number;
        folio: string | null;
        tipo: string | null;
        situacion: string;
        cerrada_por: string | null;
        cerrada_en: string | null;
        es_correccion: boolean;
        observaciones: string | null;
    }[];
    estado: { captura_abierta: boolean; en_correccion: boolean; impedimentos: string[] };
    permisos: { capturar: boolean; cerrar: boolean; corregir: boolean };
}>();

/*
 * Espejo local de lo capturado. La calificación final se recalcula aquí en
 * vivo con la misma regla del servidor, para que el docente vea el efecto de
 * cada número antes de guardar. El servidor la vuelve a calcular al cerrar:
 * este cálculo es para los ojos, no la fuente de verdad.
 */
const notas = reactive<Record<number, Record<number, number | null>>>({});

props.alumnos.forEach((alumno) => {
    notas[alumno.inscripcion_id] = {};
    props.componentes.forEach((componente) => {
        const valor = alumno.calificaciones[String(componente.id)];
        notas[alumno.inscripcion_id][componente.id] = valor ?? null;
    });
});

const sumaPorcentajes = computed(() =>
    props.componentes.reduce((total, componente) => total + componente.porcentaje, 0),
);

const esquemaValido = computed(() => Math.abs(sumaPorcentajes.value - 100) < 0.01 && props.componentes.length > 0);

function finalDe(inscripcionId: number): { valor: number | null; completa: boolean } {
    if (!esquemaValido.value) {
        return { valor: null, completa: false };
    }

    let acumulado = 0;
    let completa = true;

    props.componentes.forEach((componente) => {
        const valor = notas[inscripcionId]?.[componente.id];

        if (valor === null || valor === undefined || valor === ('' as unknown)) {
            completa = false;
            return;
        }

        acumulado += Number(valor) * (componente.porcentaje / 100);
    });

    return { valor: Math.round(acumulado * 100) / 100, completa };
}

function aprobado(inscripcionId: number): boolean | null {
    const { valor, completa } = finalDe(inscripcionId);

    if (!completa || valor === null || props.escala.aprobatoria === null) {
        return null;
    }

    return valor >= props.escala.aprobatoria;
}

const pendientes = computed(
    () => props.alumnos.filter((alumno) => !finalDe(alumno.inscripcion_id).completa).length,
);

/* Guardado */

const form = useForm({ calificaciones: [] as { inscripcion_id: number; esquema_evaluacion_id: number; calificacion: number | null }[] });

function guardar(): void {
    form.calificaciones = props.alumnos.flatMap((alumno) =>
        props.componentes.map((componente) => {
            const valor = notas[alumno.inscripcion_id]?.[componente.id];

            return {
                inscripcion_id: alumno.inscripcion_id,
                esquema_evaluacion_id: componente.id,
                calificacion: valor === null || valor === undefined || (valor as unknown) === '' ? null : Number(valor),
            };
        }),
    );

    form.put(`/escolar/captura/${props.materia.id}`, { preserveScroll: true });
}

/* Cierre y corrección */

const confirmandoCierre = ref(false);
const formCierre = useForm({});
const formCorreccion = useForm({ motivo: '' });

function cerrarActa(): void {
    formCierre.post(`/escolar/captura/${props.materia.id}/cerrar`, {
        preserveScroll: true,
        onSuccess: () => (confirmandoCierre.value = false),
    });
}

const corrigiendo = ref(false);

function abrirCorreccion(): void {
    formCorreccion.post(`/escolar/captura/${props.materia.id}/corregir`, {
        preserveScroll: true,
        onSuccess: () => {
            corrigiendo.value = false;
            formCorreccion.reset();
        },
    });
}

/*
 * Enter baja a la misma columna del siguiente alumno. Capturar 40 boletas con
 * el mouse es la diferencia entre usar el sistema y volver al papel.
 */
function siguienteFila(evento: KeyboardEvent, indiceAlumno: number, componenteId: number): void {
    const siguiente = props.alumnos[indiceAlumno + 1];

    if (siguiente === undefined) {
        return;
    }

    evento.preventDefault();
    const destino = document.getElementById(`c-${siguiente.inscripcion_id}-${componenteId}`);
    (destino as HTMLInputElement | null)?.focus();
    (destino as HTMLInputElement | null)?.select();
}

function nombreLegible(componente: Componente): string {
    return componente.componente.replace(/_/g, ' ');
}
</script>

<template>
    <Head :title="`Captura · ${materia.nombre ?? ''}`" />

    <AppLayout titulo="Captura de calificaciones">
        <NavEscolar />

        <!-- Cabecera de la materia -->
        <section class="tarjeta p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="font-mono text-sm" :style="{ color: 'var(--color-suave)' }">{{ materia.clave_en_plan }}</p>
                    <h2 class="text-lg font-semibold">{{ materia.nombre }}</h2>
                    <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                        Grupo {{ materia.grupo }} · ciclo {{ materia.ciclo }}
                        <span v-if="materia.campus"> · {{ materia.campus }}</span>
                        <span v-if="materia.titular"> · titular {{ materia.titular }}</span>
                    </p>
                    <p v-if="materia.captura_hasta" class="mt-1 text-xs" :style="{ color: 'var(--color-suave)' }">
                        Captura hasta el {{ materia.captura_hasta }}
                    </p>
                </div>
                <a href="/escolar/captura" class="text-sm" :style="{ color: 'var(--color-acento)' }">
                    ← Volver
                </a>
            </div>

            <div
                class="mt-4 flex flex-wrap items-center gap-x-6 gap-y-1 border-t pt-4 text-sm"
                :style="{ borderColor: 'var(--color-borde)' }"
            >
                <span :style="{ color: 'var(--color-suave)' }">
                    Escala {{ escala.minima }}–{{ escala.maxima }} · aprueba con {{ escala.aprobatoria }}
                </span>
                <span :style="{ color: 'var(--color-suave)' }">
                    {{ alumnos.length }} alumnos ·
                    <template v-if="pendientes">{{ pendientes }} sin terminar</template>
                    <template v-else>captura completa</template>
                </span>
            </div>
        </section>

        <!-- Esquema mal configurado: sin esto no hay nada que calcular -->
        <div
            v-if="!esquemaValido"
            class="tarjeta border-l-4 border-amber-500 p-4 text-sm"
        >
            <p class="font-medium text-amber-700">El esquema de evaluación no es utilizable.</p>
            <p class="mt-1" :style="{ color: 'var(--color-suave)' }">
                <template v-if="componentes.length === 0">
                    Esta materia no tiene componentes de evaluación en su plan.
                </template>
                <template v-else>
                    Los componentes suman {{ sumaPorcentajes }}% y deben sumar 100%.
                </template>
                Configúralo en la malla curricular del plan antes de capturar.
            </p>
        </div>

        <!-- Acta ya asentada -->
        <div
            v-if="!estado.captura_abierta"
            class="tarjeta border-l-4 p-4 text-sm"
            style="border-left-color: #16a34a"
        >
            <p class="font-medium">El acta ya está asentada.</p>
            <p class="mt-1" :style="{ color: 'var(--color-suave)' }">
                Las calificaciones son parte del kárdex y no se editan. Para cambiar una hay que
                emitir un acta de corrección: la original se conserva y queda la traza de qué cambió.
            </p>

            <div v-if="permisos.corregir" class="mt-3">
                <button
                    v-if="!corrigiendo"
                    type="button"
                    class="rounded-lg border px-3 py-1.5 text-sm transition"
                    :style="{ borderColor: 'var(--color-borde)' }"
                    @click="corrigiendo = true"
                >
                    Emitir acta de corrección
                </button>

                <div v-else class="space-y-2">
                    <label class="block text-xs font-medium">Motivo de la corrección</label>
                    <textarea
                        v-model="formCorreccion.motivo"
                        rows="2"
                        class="w-full rounded-lg border px-3 py-2 text-sm"
                        :style="{ borderColor: 'var(--color-borde)' }"
                        placeholder="Se capturó mal el examen final de…"
                    ></textarea>
                    <p v-if="formCorreccion.errors.motivo" class="text-xs text-red-600">
                        {{ formCorreccion.errors.motivo }}
                    </p>
                    <div class="flex gap-2">
                        <button
                            type="button"
                            :disabled="formCorreccion.processing"
                            class="rounded-lg px-3 py-1.5 text-sm font-medium disabled:opacity-40"
                            :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                            @click="abrirCorreccion"
                        >
                            Abrir corrección
                        </button>
                        <button
                            type="button"
                            class="rounded-lg border px-3 py-1.5 text-sm"
                            :style="{ borderColor: 'var(--color-borde)' }"
                            @click="corrigiendo = false"
                        >
                            Cancelar
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div
            v-else-if="estado.en_correccion"
            class="tarjeta border-l-4 border-amber-500 p-4 text-sm"
        >
            <p class="font-medium text-amber-700">Acta de corrección en curso.</p>
            <p class="mt-1" :style="{ color: 'var(--color-suave)' }">
                Al firmarla sustituirá los renglones del kárdex que asentó el acta anterior.
            </p>
        </div>

        <!-- Hoja -->
        <section class="tarjeta overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b px-6 py-3" :style="{ borderColor: 'var(--color-borde)' }">
                <h2 class="text-base font-semibold">Hoja de calificaciones</h2>
                <button
                    v-if="permisos.capturar"
                    type="button"
                    :disabled="form.processing || !esquemaValido"
                    class="rounded-lg px-3 py-1.5 text-sm font-medium transition disabled:cursor-not-allowed disabled:opacity-40"
                    :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                    @click="guardar"
                >
                    {{ form.processing ? 'Guardando…' : 'Guardar captura' }}
                </button>
            </div>

            <div class="overflow-x-auto">
                <table v-if="alumnos.length" class="w-full text-sm">
                    <thead class="text-left text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">
                        <tr>
                            <th class="px-6 py-3 font-medium">Matrícula</th>
                            <th class="px-4 py-3 font-medium">Alumno</th>
                            <th
                                v-for="componente in componentes"
                                :key="componente.id"
                                class="px-3 py-3 text-center font-medium"
                            >
                                {{ nombreLegible(componente) }}
                                <span class="block font-normal normal-case">{{ componente.porcentaje }}%</span>
                            </th>
                            <th class="px-4 py-3 text-center font-medium">Final</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="(alumno, indice) in alumnos"
                            :key="alumno.inscripcion_id"
                            class="border-t"
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
                            <td v-for="componente in componentes" :key="componente.id" class="px-3 py-2 text-center">
                                <input
                                    :id="`c-${alumno.inscripcion_id}-${componente.id}`"
                                    v-model="notas[alumno.inscripcion_id][componente.id]"
                                    type="number"
                                    inputmode="decimal"
                                    step="0.01"
                                    :min="escala.minima ?? undefined"
                                    :max="escala.maxima ?? undefined"
                                    :disabled="!permisos.capturar || !esquemaValido"
                                    class="w-20 rounded-lg border px-2 py-1 text-center disabled:opacity-50"
                                    :style="{ borderColor: 'var(--color-borde)' }"
                                    @keydown.enter="siguienteFila($event, indice, componente.id)"
                                    @keydown.down="siguienteFila($event, indice, componente.id)"
                                />
                            </td>
                            <td class="px-4 py-2 text-center font-medium">
                                <span
                                    v-if="finalDe(alumno.inscripcion_id).completa"
                                    :style="{ color: aprobado(alumno.inscripcion_id) ? '#16a34a' : '#dc2626' }"
                                >
                                    {{ finalDe(alumno.inscripcion_id).valor?.toFixed(2) }}
                                </span>
                                <span v-else :style="{ color: 'var(--color-suave)' }" title="Falta capturar algún componente">
                                    —
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <p v-else class="px-6 py-12 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                    No hay alumnos inscritos en esta materia.
                </p>
            </div>

            <p v-if="form.errors.calificaciones" class="border-t px-6 py-3 text-sm text-red-600" :style="{ borderColor: 'var(--color-borde)' }">
                {{ form.errors.calificaciones }}
            </p>
        </section>

        <!-- Firma del acta -->
        <section v-if="estado.captura_abierta && permisos.cerrar" class="tarjeta p-6">
            <h2 class="text-base font-semibold">Asentar el acta</h2>
            <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                Al firmar, las calificaciones finales se vuelcan al kárdex con folio y dejan de ser
                editables. Guarda la captura antes de firmar.
            </p>

            <ul v-if="estado.impedimentos.length" class="mt-3 space-y-1">
                <li
                    v-for="impedimento in estado.impedimentos"
                    :key="impedimento"
                    class="flex items-start gap-1.5 text-xs text-amber-600"
                >
                    <svg class="mt-0.5 h-3.5 w-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
                    </svg>
                    {{ impedimento }}
                </li>
            </ul>

            <p v-if="formCierre.errors.acta" class="mt-3 text-sm text-red-600">{{ formCierre.errors.acta }}</p>

            <div class="mt-4">
                <button
                    v-if="!confirmandoCierre"
                    type="button"
                    :disabled="estado.impedimentos.length > 0"
                    class="rounded-lg px-4 py-2 text-sm font-medium transition disabled:cursor-not-allowed disabled:opacity-40"
                    :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                    @click="confirmandoCierre = true"
                >
                    Firmar y asentar acta
                </button>

                <div v-else class="flex flex-wrap items-center gap-3">
                    <span class="text-sm font-medium">¿Firmar el acta? Después solo se corrige con otra acta.</span>
                    <button
                        type="button"
                        :disabled="formCierre.processing"
                        class="rounded-lg px-3 py-1.5 text-sm font-medium disabled:opacity-40"
                        :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                        @click="cerrarActa"
                    >
                        {{ formCierre.processing ? 'Asentando…' : 'Sí, firmar' }}
                    </button>
                    <button
                        type="button"
                        class="rounded-lg border px-3 py-1.5 text-sm"
                        :style="{ borderColor: 'var(--color-borde)' }"
                        @click="confirmandoCierre = false"
                    >
                        Cancelar
                    </button>
                </div>
            </div>
        </section>

        <!-- Historial de actas -->
        <section v-if="actas.length" class="tarjeta overflow-hidden">
            <div class="border-b px-6 py-3" :style="{ borderColor: 'var(--color-borde)' }">
                <h2 class="text-base font-semibold">Actas de esta materia</h2>
            </div>

            <ul>
                <li
                    v-for="acta in actas"
                    :key="acta.id"
                    class="border-t px-6 py-3 text-sm"
                    :style="{ borderColor: 'var(--color-borde)' }"
                >
                    <div class="flex flex-wrap items-center gap-x-4 gap-y-1">
                        <span class="font-mono text-xs">{{ acta.folio ?? 'sin folio (borrador)' }}</span>
                        <span>{{ acta.tipo }}</span>
                        <span
                            v-if="acta.es_correccion"
                            class="rounded-full px-2 py-0.5 text-xs"
                            style="background-color: color-mix(in srgb, #f59e0b 18%, transparent)"
                        >
                            corrección
                        </span>
                        <span :style="{ color: 'var(--color-suave)' }">{{ acta.situacion }}</span>
                        <span v-if="acta.cerrada_por" :style="{ color: 'var(--color-suave)' }">
                            firmó {{ acta.cerrada_por }} · {{ acta.cerrada_en }}
                        </span>
                    </div>
                    <p v-if="acta.observaciones" class="mt-1 text-xs" :style="{ color: 'var(--color-suave)' }">
                        {{ acta.observaciones }}
                    </p>
                </li>
            </ul>
        </section>
    </AppLayout>
</template>
