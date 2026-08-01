<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonVolver from '@/Components/BotonVolver.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import PildoraEstado from '@/Components/PildoraEstado.vue';

/*
 * Una materia, vista por el alumno que la cursa.
 *
 * Junta en una sola pantalla el «cómo me van a calificar» (el esquema de
 * evaluación) con el «cómo voy» (lo capturado): separados obligan a cruzarlos
 * de memoria, que es justo lo que un alumno no debería tener que hacer para
 * saber si va bien.
 *
 * Todavía no hay actividades ni entregas —eso llega con el LMS—; lo que se
 * muestra aquí ya existía en la base y solo faltaba una puerta.
 */
interface Componente {
    componente: string;
    porcentaje: number;
    calificacion: number | null;
}

interface Parcial {
    parcial: number;
    componentes: Componente[];
    peso_total: number;
    peso_capturado: number;
    ganado: number;
    completo: boolean;
}

interface Entrega {
    id: number;
    estado: string;
    contenido: string | null;
    entregada_en: string | null;
    tarde: boolean;
    calificacion: number | null;
    retroalimentacion: string | null;
    archivos: { id: number; nombre: string }[];
}

interface ActividadAlumno {
    id: number;
    tipo: string;
    tipo_etiqueta: string;
    se_entrega: boolean;
    titulo: string;
    instrucciones: string | null;
    puntos: number;
    abre_en: string | null;
    cierra_en: string | null;
    permite_tarde: boolean;
    abierta: boolean;
    componente: string | null;
    entrega: Entrega | null;
}

const props = defineProps<{
    curso: Record<string, any>;
    docentes: { id: number; nombre: string | null; foto: string | null; tipo: string; correo: string | null }[];
    evaluacion: { parciales: Parcial[]; sin_esquema: boolean };
    actividades: ActividadAlumno[];
    asistencia: {
        registros: { fecha: string; estatus: string; observacion: string | null }[];
        total: number;
        presentes: number;
        faltas: number;
        retardos: number;
        porcentaje: number | null;
    };
    horarios: { dia: string | null; inicio: string | null; fin: string | null; aula: string | null }[];
}>();

function iniciales(nombre: string | null): string {
    return (nombre ?? '?').split(' ').filter(Boolean).slice(0, 2).map((p) => p[0]).join('').toUpperCase();
}

/** Color de una calificación: el mismo criterio del kárdex. */
function colorCalificacion(c: number | null): string {
    if (c === null) return 'var(--color-suave)';

    return c >= 8 ? '#16a34a' : c >= 6 ? '#d97706' : '#dc2626';
}

const colorAsistencia = computed(() => {
    const p = props.asistencia.porcentaje;

    return p === null ? 'var(--color-suave)' : p >= 90 ? '#16a34a' : p >= 80 ? '#d97706' : '#dc2626';
});

/*
 * Entregar. Una actividad a la vez: el formulario se abre en la que se toca y
 * el resto se queda quieto. Abrir todos los formularios de golpe convertiría la
 * lista en un muro de campos vacíos.
 */
const entregando = ref<number | null>(null);

const formEntrega = useForm<{ contenido: string; archivos: File[] }>({
    contenido: '',
    archivos: [],
});

function alternarEntrega(a: ActividadAlumno): void {
    entregando.value = entregando.value === a.id ? null : a.id;
    formEntrega.reset();
    formEntrega.clearErrors();
    // Reentregar arranca con lo que ya había escrito: casi siempre se corrige,
    // no se empieza de cero.
    formEntrega.contenido = a.entrega?.contenido ?? '';
}

function elegirArchivos(evento: Event): void {
    formEntrega.archivos = Array.from((evento.target as HTMLInputElement).files ?? []);
}

function enviarEntrega(a: ActividadAlumno): void {
    formEntrega.post(`/mis-cursos/actividades/${a.id}/entrega`, {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            entregando.value = null;
            formEntrega.reset();
        },
    });
}

/** Cómo se ve el estado de una actividad para el alumno. */
function estadoDe(a: ActividadAlumno): { texto: string; color: string } {
    if (!a.se_entrega) return { texto: 'Lectura', color: 'var(--color-suave)' };
    if (a.entrega?.calificacion != null) return { texto: 'Calificada', color: '#16a34a' };
    if (a.entrega?.entregada_en) {
        return a.entrega.tarde
            ? { texto: 'Entregada tarde', color: '#d97706' }
            : { texto: 'Entregada', color: '#2563eb' };
    }
    if (!a.abierta) return { texto: 'Cerrada sin entregar', color: '#dc2626' };

    return { texto: 'Pendiente', color: '#d97706' };
}

const etiquetaEstatus: Record<string, string> = {
    presente: 'Asistencia',
    retardo: 'Retardo',
    falta: 'Falta',
    justificada: 'Falta justificada',
};
</script>

<template>
    <Head :title="curso.materia ?? 'Materia'" />

    <AppLayout titulo="Mi materia">
        <section class="tarjeta p-6">
            <BotonVolver href="/mis-cursos" texto="Mis cursos" class="mb-4" />

            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="text-lg font-semibold text-contenido">{{ curso.materia }}</h2>
                        <PildoraEstado :texto="curso.situacion" />
                    </div>
                    <p class="mt-0.5 font-mono text-xs text-suave">{{ curso.clave }}</p>

                    <p class="mt-3 text-sm text-suave">
                        {{ curso.carrera }}<span v-if="curso.plan"> · {{ curso.plan }}</span>
                    </p>
                    <p class="text-sm text-suave">
                        Ciclo {{ curso.ciclo }} · Grupo {{ curso.grupo }}
                        <span v-if="curso.campus"> · {{ curso.campus }}</span>
                        <span v-if="curso.turno"> · {{ curso.turno }}</span>
                        <span v-if="curso.creditos"> · {{ curso.creditos }} créditos</span>
                    </p>
                </div>

                <div v-if="curso.avance !== null" class="text-right">
                    <p class="text-2xl font-semibold leading-none" :style="{ color: 'var(--color-acento)' }">
                        {{ curso.avance }}%
                    </p>
                    <p class="mt-1 text-xs text-suave">de la evaluación<br />ya calificada</p>
                </div>
            </div>

            <p
                v-if="curso.tipo_evaluacion && !/ordinaria/i.test(curso.tipo_evaluacion)"
                class="mt-4 rounded-lg px-3 py-2 text-sm"
                :style="{ backgroundColor: 'color-mix(in srgb, #d97706 12%, transparent)', color: '#b45309' }"
            >
                La cursas en <strong>{{ curso.tipo_evaluacion }}</strong>.
            </p>
        </section>

        <!-- Cómo me califican y cómo voy, juntos -->
        <section class="tarjeta overflow-hidden">
            <header class="px-6 py-4">
                <h2 class="text-base font-semibold text-contenido">Cómo se evalúa</h2>
                <p class="mt-0.5 text-sm text-suave">
                    Lo que pesa cada parte y lo que llevas en cada una. Lo que aún no
                    tiene número es que todavía no se califica.
                </p>
            </header>

            <div v-if="!evaluacion.sin_esquema" class="border-t border-borde">
                <article
                    v-for="p in evaluacion.parciales"
                    :key="p.parcial"
                    class="border-b border-borde last:border-b-0"
                >
                    <div class="flex flex-wrap items-center justify-between gap-3 px-6 py-3">
                        <h3 class="text-sm font-semibold text-contenido">Parcial {{ p.parcial }}</h3>
                        <p class="text-xs text-suave">
                            Llevas <strong :style="{ color: 'var(--color-contenido)' }">{{ p.ganado }}</strong>
                            de los {{ p.peso_capturado }} puntos ya calificados
                            <span v-if="!p.completo">· faltan {{ (p.peso_total - p.peso_capturado).toFixed(0) }} por calificar</span>
                        </p>
                    </div>

                    <ul class="divide-y divide-borde border-t border-borde">
                        <li
                            v-for="(c, i) in p.componentes"
                            :key="i"
                            class="flex items-center gap-4 px-6 py-2.5"
                        >
                            <span class="min-w-0 flex-1 truncate text-sm">{{ c.componente }}</span>
                            <span class="shrink-0 text-xs text-suave">{{ c.porcentaje }}%</span>
                            <span
                                class="w-14 shrink-0 text-right text-sm font-semibold tabular-nums"
                                :style="{ color: colorCalificacion(c.calificacion) }"
                            >
                                {{ c.calificacion ?? '—' }}
                            </span>
                        </li>
                    </ul>
                </article>
            </div>

            <p v-else class="border-t border-borde px-6 py-8 text-center text-sm text-suave">
                Esta materia todavía no tiene definida su forma de evaluación.
            </p>
        </section>

        <!-- Actividades -->
        <section v-if="actividades.length" class="tarjeta overflow-hidden">
            <header class="px-6 py-4">
                <h2 class="text-base font-semibold text-contenido">Actividades</h2>
                <p class="mt-0.5 text-sm text-suave">
                    Lo que hay que hacer, para cuándo y qué te calificaron.
                </p>
            </header>

            <ul class="divide-y divide-borde border-t border-borde">
                <li v-for="a in actividades" :key="a.id">
                    <div class="flex flex-wrap items-start gap-4 px-6 py-4">
                        <span class="min-w-0 flex-1">
                            <span class="flex flex-wrap items-center gap-2">
                                <span class="font-medium text-contenido">{{ a.titulo }}</span>
                                <span
                                    class="rounded-full px-2 py-0.5 text-[11px]"
                                    :style="{ backgroundColor: 'color-mix(in srgb, var(--color-suave) 12%, transparent)', color: 'var(--color-suave)' }"
                                >
                                    {{ a.tipo_etiqueta }}
                                </span>
                                <!-- Si pondera, se dice a qué parcial va. Si no,
                                     se dice que no cuenta: callarlo deja al
                                     alumno suponiendo. -->
                                <span
                                    v-if="a.componente"
                                    class="rounded-full px-2 py-0.5 text-[11px]"
                                    :style="{ backgroundColor: 'color-mix(in srgb, var(--color-acento) 12%, transparent)', color: 'var(--color-acento)' }"
                                >
                                    {{ a.componente }}
                                </span>
                                <span v-else-if="a.se_entrega" class="text-[11px] text-suave">
                                    No cuenta para la calificación
                                </span>
                            </span>

                            <span v-if="a.instrucciones" class="mt-1 block whitespace-pre-line text-sm text-suave">
                                {{ a.instrucciones }}
                            </span>

                            <span v-if="a.cierra_en" class="mt-1 block text-xs text-suave">
                                Entrega hasta el {{ a.cierra_en }}
                                <span v-if="a.permite_tarde"> · se acepta después, marcado como tarde</span>
                            </span>
                        </span>

                        <span class="flex shrink-0 flex-col items-end gap-2">
                            <PildoraEstado :texto="estadoDe(a).texto" :color="estadoDe(a).color" sin-capitalizar />
                            <span v-if="a.entrega?.calificacion != null" class="text-sm font-semibold text-contenido">
                                {{ a.entrega.calificacion }} / {{ a.puntos }}
                            </span>
                            <span v-else-if="a.se_entrega" class="text-xs text-suave">sobre {{ a.puntos }}</span>

                            <button
                                v-if="a.se_entrega && a.abierta"
                                type="button"
                                class="rounded-lg border px-3 py-1.5 text-xs font-medium"
                                :style="{ borderColor: 'var(--color-acento)', color: 'var(--color-acento)' }"
                                @click="alternarEntrega(a)"
                            >
                                {{ entregando === a.id ? 'Cancelar' : (a.entrega?.entregada_en ? 'Volver a entregar' : 'Entregar') }}
                            </button>
                        </span>
                    </div>

                    <!-- Lo que ya entregó y lo que le dijeron -->
                    <div
                        v-if="a.entrega?.entregada_en && entregando !== a.id"
                        class="border-t border-borde px-6 py-3"
                        :style="{ backgroundColor: 'color-mix(in srgb, var(--color-suave) 5%, transparent)' }"
                    >
                        <p class="text-xs text-suave">Entregaste el {{ a.entrega.entregada_en }}</p>
                        <p v-if="a.entrega.contenido" class="mt-1 whitespace-pre-line text-sm">{{ a.entrega.contenido }}</p>
                        <ul v-if="a.entrega.archivos.length" class="mt-2 flex flex-wrap gap-2">
                            <li v-for="f in a.entrega.archivos" :key="f.id">
                                <a
                                    :href="`/mis-cursos/entregas/archivos/${f.id}`"
                                    class="rounded-full border px-2 py-0.5 text-xs"
                                    :style="{ borderColor: 'var(--color-borde)', color: 'var(--color-acento)' }"
                                >
                                    {{ f.nombre }}
                                </a>
                            </li>
                        </ul>
                        <p
                            v-if="a.entrega.retroalimentacion"
                            class="mt-3 rounded-lg border-l-2 py-2 pl-3 text-sm"
                            :style="{ borderColor: 'var(--color-acento)' }"
                        >
                            <span class="block text-xs font-medium text-suave">Retroalimentación del docente</span>
                            {{ a.entrega.retroalimentacion }}
                        </p>
                    </div>

                    <!-- Formulario de entrega -->
                    <form
                        v-if="entregando === a.id"
                        class="space-y-3 border-t border-borde px-6 py-4"
                        :style="{ borderLeft: '3px solid var(--color-acento)' }"
                        @submit.prevent="enviarEntrega(a)"
                    >
                        <div>
                            <label class="mb-1 block text-sm font-medium">Tu respuesta</label>
                            <textarea
                                v-model="formEntrega.contenido"
                                rows="4"
                                class="w-full rounded-lg border px-3 py-2 text-sm"
                                :style="{ borderColor: 'var(--color-borde)' }"
                                placeholder="Escribe aquí, o adjunta un archivo abajo."
                            />
                            <p v-if="formEntrega.errors.contenido" class="mt-1 text-xs text-red-600">
                                {{ formEntrega.errors.contenido }}
                            </p>
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-medium">Archivos</label>
                            <input type="file" multiple class="text-sm" @change="elegirArchivos" />
                            <p class="mt-1 text-xs text-suave">Hasta 5 archivos, 20 MB cada uno.</p>
                        </div>

                        <p v-if="a.entrega?.entregada_en" class="text-xs text-amber-600">
                            Volver a entregar reemplaza lo anterior y quita la calificación:
                            se califica lo que quede.
                        </p>

                        <BotonPrincipal :procesando="formEntrega.processing" texto="Entregar" icono="crear" />
                    </form>
                </li>
            </ul>
        </section>

        <div class="grid gap-4 lg:grid-cols-2">
            <!-- Asistencia -->
            <section class="tarjeta overflow-hidden">
                <header class="flex flex-wrap items-center justify-between gap-3 px-6 py-4">
                    <div>
                        <h2 class="text-base font-semibold text-contenido">Mi asistencia</h2>
                        <p class="mt-0.5 text-sm text-suave">
                            {{ asistencia.presentes }} de {{ asistencia.total }} clase(s)
                            <span v-if="asistencia.retardos"> · {{ asistencia.retardos }} retardo(s)</span>
                        </p>
                    </div>
                    <p v-if="asistencia.porcentaje !== null" class="text-2xl font-semibold" :style="{ color: colorAsistencia }">
                        {{ asistencia.porcentaje }}%
                    </p>
                </header>

                <ul v-if="asistencia.registros.length" class="max-h-72 divide-y divide-borde overflow-y-auto border-t border-borde">
                    <li v-for="(r, i) in asistencia.registros" :key="i" class="flex items-center justify-between gap-3 px-6 py-2">
                        <span class="font-mono text-xs text-suave">{{ r.fecha }}</span>
                        <span class="min-w-0 flex-1 truncate text-xs text-suave">{{ r.observacion }}</span>
                        <PildoraEstado :texto="etiquetaEstatus[r.estatus] ?? r.estatus" sin-capitalizar />
                    </li>
                </ul>
                <p v-else class="border-t border-borde px-6 py-8 text-center text-sm text-suave">
                    Todavía no se ha pasado lista en esta materia.
                </p>
            </section>

            <!-- Docente y horario -->
            <section class="tarjeta overflow-hidden">
                <header class="px-6 py-4">
                    <h2 class="text-base font-semibold text-contenido">Quién la imparte</h2>
                </header>

                <ul v-if="docentes.length" class="divide-y divide-borde border-t border-borde">
                    <li v-for="d in docentes" :key="d.id" class="flex items-center gap-3 px-6 py-3">
                        <img v-if="d.foto" :src="d.foto" :alt="d.nombre ?? ''" class="h-10 w-10 shrink-0 rounded-full object-cover" />
                        <span
                            v-else
                            class="grid h-10 w-10 shrink-0 place-items-center rounded-full text-xs font-semibold"
                            :style="{ backgroundColor: 'color-mix(in srgb, var(--color-acento) 14%, transparent)', color: 'var(--color-acento)' }"
                        >
                            {{ iniciales(d.nombre) }}
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-medium text-contenido">{{ d.nombre }}</span>
                            <span v-if="d.correo" class="block truncate text-xs text-suave">{{ d.correo }}</span>
                        </span>
                        <span
                            class="shrink-0 rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide"
                            :style="d.tipo === 'titular'
                                ? { backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }
                                : { backgroundColor: 'color-mix(in srgb, var(--color-suave) 45%, transparent)', color: 'var(--color-superficie)' }"
                        >
                            {{ d.tipo }}
                        </span>
                    </li>
                </ul>
                <p v-else class="border-t border-borde px-6 py-8 text-center text-sm text-amber-600">
                    Esta materia todavía no tiene docente asignado.
                </p>

                <template v-if="horarios.length">
                    <header class="border-t border-borde px-6 py-4">
                        <h3 class="text-sm font-semibold text-contenido">Horario</h3>
                    </header>
                    <ul class="divide-y divide-borde border-t border-borde">
                        <li v-for="(h, i) in horarios" :key="i" class="flex items-center justify-between gap-3 px-6 py-2 text-sm">
                            <span class="capitalize">{{ h.dia }}</span>
                            <span class="text-suave">{{ h.inicio }}–{{ h.fin }}</span>
                            <span v-if="h.aula" class="text-xs text-suave">{{ h.aula }}</span>
                        </li>
                    </ul>
                </template>
            </section>
        </div>
    </AppLayout>
</template>
