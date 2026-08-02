<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import { ICONOS } from '@/iconos';

/**
 * Calificar una entrega, con lo que el alumno mandó a la vista.
 *
 * ── Por qué un panel y no una franja al pie de la tabla ────────────────────
 * Antes el formulario aparecía DEBAJO del libro de calificaciones: con treinta
 * alumnos, tocar una casilla de la fila 4 abría un cuadro que quedaba dos
 * pantallas más abajo, y calificar era un vaivén de desplazamiento. Peor: no
 * mostraba los archivos que el alumno había subido, así que calificar una tarea
 * entregada como PDF era calificar a ciegas.
 *
 * El panel se ancla al costado, no se mueve, y trae los tres datos que se
 * necesitan para poner una nota: qué entregó, cuándo, y sobre cuánto va.
 *
 * ── Lo que se califica solo ────────────────────────────────────────────────
 * Un examen de opción múltiple lo califica la máquina en el momento en que el
 * alumno lo entrega. Aquí no se pisa esa nota con un campo de texto: se dice que
 * fue automática y se manda a la pantalla del examen, que es donde se revisan
 * las preguntas abiertas y donde recalcular tiene sentido. Escribir un número
 * encima habría dejado la calificación y las respuestas contando cosas
 * distintas.
 */
interface Archivo {
    id: number;
    nombre: string;
    bytes: number;
}

interface Casilla {
    actividad_id: number;
    entrega_id: number | null;
    estado: string;
    tarde: boolean;
    calificacion: number | null;
    retroalimentacion: string | null;
    contenido: string | null;
    entregada_en: string | null;
    automatica: boolean;
    archivos: Archivo[];
}

interface Actividad {
    id: number;
    tipo: string;
    tipo_etiqueta: string;
    titulo: string;
    puntos: number;
    componente: string | null;
}

const props = defineProps<{
    materiaId: number;
    alumno: string | null;
    matricula: string | null;
    casilla: Casilla;
    actividad: Actividad;
    /** Cuántas entregas quedan sin calificar, para el botón de continuar. */
    pendientes: number;
}>();

const emit = defineEmits<{ cerrar: []; siguiente: [] }>();

const form = useForm({ calificacion: '' as string | number, retroalimentacion: '' });

/*
 * Al saltar de una entrega a otra el formulario se rehace con lo de la nueva.
 * Sin esto, «guardar y siguiente» arrastraba la retroalimentación del alumno
 * anterior al campo del siguiente, y basta un despiste para publicarla.
 */
watch(
    () => props.casilla,
    (c) => {
        form.clearErrors();
        form.calificacion = c.calificacion ?? '';
        form.retroalimentacion = c.retroalimentacion ?? '';
    },
    { immediate: true },
);

const esExamen = computed(() => props.actividad.tipo === 'examen');

/** Sin nota y ya entregada: es a lo que el docente vino. */
const esperaRevision = computed(
    () => props.casilla.entrega_id !== null && props.casilla.calificacion === null,
);

const seguirDespues = ref(true);

function guardar(): void {
    form.put(`/docencia/materias/${props.materiaId}/entregas/${props.casilla.entrega_id}/calificar`, {
        preserveScroll: true,
        onSuccess: () => {
            if (seguirDespues.value && props.pendientes > 1) {
                emit('siguiente');

                return;
            }

            emit('cerrar');
        },
    });
}

/** El máximo de un tirón: es la nota más puesta y evita teclear el número. */
function ponerMaximo(): void {
    form.calificacion = props.actividad.puntos;
}

function pesoLegible(bytes: number): string {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`;

    return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}

const colorNota = computed(() => {
    const n = Number(form.calificacion);

    if (form.calificacion === '' || Number.isNaN(n)) return 'var(--color-suave)';

    const enDiez = props.actividad.puntos > 0 ? (n * 10) / props.actividad.puntos : n;

    return enDiez >= 8 ? '#16a34a' : enDiez >= 6 ? '#d97706' : '#dc2626';
});
</script>

<template>
    <!-- Fondo: atenúa la rejilla para que se lea el trabajo, y cierra al tocar
         fuera, que es lo que uno intenta antes de buscar el botón. -->
    <div class="fixed inset-0 z-40 bg-black/25" @click="emit('cerrar')" />

    <aside
        class="fixed inset-y-0 right-0 z-50 flex w-full max-w-lg flex-col shadow-2xl"
        :style="{ backgroundColor: 'var(--color-superficie)' }"
    >
        <!-- Quién y qué -->
        <header class="flex items-start gap-3 border-b border-borde px-5 py-4">
            <span class="min-w-0 flex-1">
                <span class="block truncate font-semibold text-contenido">{{ alumno }}</span>
                <span class="block truncate font-mono text-xs text-suave">{{ matricula }}</span>
                <span class="mt-1.5 flex flex-wrap items-center gap-2">
                    <span
                        class="rounded-full px-2 py-0.5 text-[11px] font-medium"
                        :style="{ backgroundColor: 'color-mix(in srgb, var(--color-acento) 12%, transparent)', color: 'var(--color-acento)' }"
                    >
                        {{ actividad.tipo_etiqueta }}
                    </span>
                    <span class="truncate text-sm text-contenido">{{ actividad.titulo }}</span>
                </span>
                <span v-if="actividad.componente" class="mt-0.5 block text-[11px] text-suave">
                    {{ actividad.componente }} · sobre {{ actividad.puntos }} puntos
                </span>
            </span>

            <button
                type="button"
                class="grid h-8 w-8 shrink-0 place-items-center rounded-lg text-suave transition hover:bg-[color-mix(in_srgb,var(--color-suave)_12%,transparent)]"
                title="Cerrar"
                @click="emit('cerrar')"
            >
                ✕
            </button>
        </header>

        <div class="min-h-0 flex-1 overflow-y-auto">
            <!-- Cuándo llegó -->
            <div class="flex flex-wrap items-center gap-2 border-b border-borde px-5 py-3 text-xs">
                <span class="text-suave">Entregó el {{ casilla.entregada_en }}</span>
                <span
                    v-if="casilla.tarde"
                    class="rounded-full px-2 py-0.5 font-medium"
                    :style="{ backgroundColor: 'color-mix(in srgb, #d97706 14%, transparent)', color: '#b45309' }"
                >
                    Fuera de tiempo
                </span>
                <span
                    v-if="casilla.automatica"
                    class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 font-medium"
                    :style="{ backgroundColor: 'color-mix(in srgb, #2563eb 12%, transparent)', color: '#1d4ed8' }"
                    title="La calificó el sistema al momento de entregarse"
                >
                    ⚡ Calificada automáticamente
                </span>
            </div>

            <!-- Qué entregó -->
            <section class="px-5 py-4">
                <h3 class="text-xs font-semibold uppercase tracking-wide text-suave">Su trabajo</h3>

                <p
                    v-if="casilla.contenido"
                    class="mt-2 whitespace-pre-line rounded-lg px-3 py-2.5 text-sm text-contenido"
                    :style="{ backgroundColor: 'color-mix(in srgb, var(--color-suave) 7%, transparent)' }"
                >
                    {{ casilla.contenido }}
                </p>

                <!-- Los adjuntos: en la mayoría de las tareas SON la entrega. -->
                <ul v-if="casilla.archivos.length" class="mt-3 space-y-1.5">
                    <li v-for="f in casilla.archivos" :key="f.id">
                        <a
                            :href="`/mis-cursos/entregas/archivos/${f.id}`"
                            class="flex items-center gap-2.5 rounded-lg border px-3 py-2 text-sm transition hover:border-[var(--color-acento)]"
                            :style="{ borderColor: 'var(--color-borde)' }"
                        >
                            <svg class="h-4 w-4 shrink-0" :style="{ color: 'var(--color-acento)' }" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
                                <path stroke-linecap="round" stroke-linejoin="round" :d="ICONOS.documento" />
                            </svg>
                            <span class="min-w-0 flex-1 truncate text-contenido">{{ f.nombre }}</span>
                            <span class="shrink-0 text-xs text-suave">{{ pesoLegible(f.bytes) }}</span>
                        </a>
                    </li>
                </ul>

                <p
                    v-if="!casilla.contenido && !casilla.archivos.length && !esExamen"
                    class="mt-2 text-sm text-suave"
                >
                    La entrega llegó vacía: sin texto ni archivos.
                </p>

                <!-- El examen no se lee aquí: se revisa en su pantalla, que es
                     donde están las respuestas pregunta por pregunta. -->
                <div
                    v-if="esExamen"
                    class="mt-2 rounded-lg px-4 py-3"
                    :style="{ backgroundColor: 'color-mix(in srgb, #2563eb 7%, transparent)' }"
                >
                    <p class="text-sm text-contenido">
                        <template v-if="esperaRevision">
                            Este examen tiene preguntas abiertas que la máquina no puede
                            calificar. Se revisan una por una y la nota se calcula sola al
                            terminar.
                        </template>
                        <template v-else>
                            La nota salió del examen. Puedes ver qué contestó y volver a
                            revisar las preguntas abiertas.
                        </template>
                    </p>
                    <a
                        :href="`/docencia/materias/${materiaId}/examenes/${actividad.id}`"
                        class="mt-2.5 inline-block rounded-lg px-3.5 py-1.5 text-xs font-medium"
                        :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                    >
                        {{ esperaRevision ? 'Revisar el examen' : 'Ver el examen' }}
                    </a>
                </div>
            </section>
        </div>

        <!-- Poner la nota -->
        <form
            v-if="!esExamen"
            class="border-t border-borde px-5 py-4"
            @submit.prevent="guardar"
        >
            <div class="flex items-end gap-3">
                <div>
                    <label class="mb-1 block text-xs font-medium text-suave">Calificación</label>
                    <div class="flex items-center gap-2">
                        <input
                            v-model="form.calificacion"
                            type="number"
                            step="0.01"
                            min="0"
                            :max="actividad.puntos"
                            class="w-24 rounded-lg border px-3 py-2 text-lg font-semibold"
                            :style="{ borderColor: 'var(--color-borde)', color: colorNota }"
                        />
                        <span class="text-sm text-suave">de {{ actividad.puntos }}</span>
                        <button
                            type="button"
                            class="rounded-lg border px-2.5 py-1 text-xs"
                            :style="{ borderColor: 'var(--color-borde)', color: 'var(--color-suave)' }"
                            title="Poner el puntaje completo"
                            @click="ponerMaximo"
                        >
                            Máximo
                        </button>
                    </div>
                    <p v-if="form.errors.calificacion" class="mt-1 text-xs text-red-600">
                        {{ form.errors.calificacion }}
                    </p>
                </div>
            </div>

            <div class="mt-3">
                <label class="mb-1 block text-xs font-medium text-suave">
                    Retroalimentación <span class="font-normal">— la lee el alumno</span>
                </label>
                <textarea
                    v-model="form.retroalimentacion"
                    rows="3"
                    class="w-full rounded-lg border px-3 py-2 text-sm"
                    :style="{ borderColor: 'var(--color-borde)' }"
                    placeholder="Qué estuvo bien, qué corregir. Es lo único que se lleva de vuelta."
                />
                <p v-if="form.errors.retroalimentacion" class="mt-1 text-xs text-red-600">
                    {{ form.errors.retroalimentacion }}
                </p>
            </div>

            <!-- Calificar es una tarea en serie: se hacen las treinta de un
                 tirón. Sin esto había que cerrar, buscar la siguiente casilla
                 naranja y volver a abrir. -->
            <label
                v-if="pendientes > 1"
                class="mt-3 flex items-center gap-2 text-xs text-suave"
            >
                <input v-model="seguirDespues" type="checkbox" class="rounded" />
                Al guardar, pasar a la siguiente sin calificar
                ({{ pendientes - 1 }} más)
            </label>

            <div class="mt-4 flex items-center gap-2">
                <BotonPrincipal
                    :procesando="form.processing"
                    :texto="seguirDespues && pendientes > 1 ? 'Guardar y seguir' : 'Guardar'"
                    icono="guardar"
                />
                <button
                    type="button"
                    class="rounded-lg border px-4 py-2 text-sm"
                    :style="{ borderColor: 'var(--color-borde)' }"
                    @click="emit('cerrar')"
                >
                    Cerrar
                </button>
            </div>
        </form>
    </aside>
</template>
