<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import BotonVolver from '@/Components/BotonVolver.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoSelect from '@/Components/CampoSelect.vue';

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

interface ActividadDocente {
    id: number;
    tipo: string;
    tipo_etiqueta: string;
    se_entrega: boolean;
    titulo: string;
    instrucciones: string | null;
    puntos: number;
    esquema_evaluacion_id: number | null;
    componente: string | null;
    abre_en: string | null;
    cierra_en: string | null;
    permite_tarde: boolean;
    publicada: boolean;
    entregadas: number;
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
}

const props = defineProps<{
    materia: Record<string, any>;
    horarios: { dia: number; inicio: string; fin: string; aula: string | null }[];
    companeros: { nombre: string | null; tipo: string }[];
    alumnos: Alumno[];
    calendario: Record<string, { abierto: boolean; motivo: string | null }>;
    puedeCapturar: boolean;
    curso: { id: number; puede_agregar: boolean; puede_ponderar: boolean; de_plantilla: boolean } | null;
    actividades: ActividadDocente[];
    matriz: { inscripcion_id: number; matricula: string | null; nombre: string | null; de_baja: boolean; casillas: Casilla[] }[];
    componentes: { id: number; etiqueta: string }[];
    tiposActividad: { valor: string; etiqueta: string; se_entrega: boolean }[];
    puedePasarLista: boolean;
    asistencia: {
        fecha: string;
        modalidad: string;
        doble: boolean;
        sesiones: { fecha: string; modalidad: string }[];
        lista: {
            inscripcion_id: number;
            matricula: string | null;
            nombre: string | null;
            estatus: string | null;
            observacion: string | null;
            faltas: number;
            retardos: number;
            porcentaje: number | null;
        }[];
    };
}>();

const dias = ['', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo'];

/*
 * ── LMS: actividades y calificación ───────────────────────────────────────
 *
 * Un curso sin fila todavía se comporta como uno vacío que SÍ deja agregar: la
 * fila se crea al guardar la primera actividad. La mayoría de las materias
 * presenciales nunca cargarán contenido y no tiene sentido una fila por cada una.
 */
const puedeAgregar = computed(() => props.curso === null || props.curso.puede_agregar);
const puedePonderar = computed(() => props.curso === null || props.curso.puede_ponderar);

const formActividad = useForm({
    id: null as number | null,
    tipo: 'actividad',
    titulo: '',
    instrucciones: '',
    esquema_evaluacion_id: null as number | null,
    puntos: 10,
    abre_en: '',
    cierra_en: '',
    permite_tarde: false,
    publicada: true,
});

const editorAbierto = ref(false);

function nuevaActividad(): void {
    formActividad.reset();
    formActividad.clearErrors();
    editorAbierto.value = true;
}

function editarActividad(a: ActividadDocente): void {
    formActividad.clearErrors();
    formActividad.id = a.id;
    formActividad.tipo = a.tipo;
    formActividad.titulo = a.titulo;
    formActividad.instrucciones = a.instrucciones ?? '';
    formActividad.esquema_evaluacion_id = a.esquema_evaluacion_id;
    formActividad.puntos = a.puntos;
    formActividad.abre_en = a.abre_en ?? '';
    formActividad.cierra_en = a.cierra_en ?? '';
    formActividad.permite_tarde = a.permite_tarde;
    formActividad.publicada = a.publicada;
    editorAbierto.value = true;
}

function guardarActividad(): void {
    const base = `/docencia/materias/${props.materia.id}/actividades`;
    const opciones = { preserveScroll: true, onSuccess: () => { editorAbierto.value = false; formActividad.reset(); } };

    formActividad.id === null
        ? formActividad.post(base, opciones)
        : formActividad.put(`${base}/${formActividad.id}`, opciones);
}

function eliminarActividad(a: ActividadDocente): void {
    const aviso = a.entregadas > 0
        ? `"${a.titulo}" tiene ${a.entregadas} entrega(s). Al eliminarla se van con ella. ¿Continuar?`
        : `¿Eliminar "${a.titulo}"?`;

    if (!confirm(aviso)) return;

    router.delete(`/docencia/materias/${props.materia.id}/actividades/${a.id}`, { preserveScroll: true });
}

// --- Calificar una casilla de la matriz ---
const calificando = ref<{ inscripcion: number; actividad: number } | null>(null);
const formCalificar = useForm({ calificacion: '' as string | number, retroalimentacion: '' });

function abrirCalificacion(fila: { inscripcion_id: number }, c: Casilla): void {
    if (c.entrega_id === null) return;

    calificando.value = { inscripcion: fila.inscripcion_id, actividad: c.actividad_id };
    formCalificar.clearErrors();
    formCalificar.calificacion = c.calificacion ?? '';
    formCalificar.retroalimentacion = c.retroalimentacion ?? '';
}

function guardarCalificacion(c: Casilla): void {
    formCalificar.put(`/docencia/materias/${props.materia.id}/entregas/${c.entrega_id}/calificar`, {
        preserveScroll: true,
        onSuccess: () => { calificando.value = null; formCalificar.reset(); },
    });
}

const casillaAbierta = (fila: { inscripcion_id: number }, c: Casilla) =>
    calificando.value?.inscripcion === fila.inscripcion_id && calificando.value?.actividad === c.actividad_id;

/** El color de una casilla dice de un vistazo en qué va cada alumno. */
function colorCasilla(c: Casilla): { backgroundColor: string; color: string } {
    if (c.calificacion !== null) {
        return { backgroundColor: 'color-mix(in srgb, #16a34a 14%, transparent)', color: '#15803d' };
    }
    if (c.entrega_id !== null) {
        return c.tarde
            ? { backgroundColor: 'color-mix(in srgb, #d97706 16%, transparent)', color: '#b45309' }
            : { backgroundColor: 'color-mix(in srgb, #2563eb 14%, transparent)', color: '#1d4ed8' };
    }

    return { backgroundColor: 'color-mix(in srgb, var(--color-suave) 10%, transparent)', color: 'var(--color-suave)' };
}

const tituloActividad = (id: number) => props.actividades.find((a) => a.id === id)?.titulo ?? '';

/*
 * ── Pase de lista ─────────────────────────────────────────────────────────
 *
 * Se marca a todo el grupo y se guarda de una vez. Pasar lista es un acto único
 * sobre el grupo: guardar de a un alumno dejaría sesiones a medias si el docente
 * se distrae, y obligaría a recordar quién ya se guardó.
 */
const ESTATUS = [
    { valor: 'presente', etiqueta: 'Asistió', color: '#16a34a' },
    { valor: 'retardo', etiqueta: 'Retardo', color: '#d97706' },
    { valor: 'falta', etiqueta: 'Falta', color: '#dc2626' },
    { valor: 'justificada', etiqueta: 'Justificada', color: 'var(--color-suave)' },
];

const marcas = ref<Record<number, string>>(
    Object.fromEntries(props.asistencia.lista.map((a) => [a.inscripcion_id, a.estatus ?? ''])),
);

const formLista = useForm({ fecha: '', modalidad: '', asistencias: [] as unknown[] });

const sinMarcar = computed(() => props.asistencia.lista.filter((a) => !marcas.value[a.inscripcion_id]).length);

/** Marca a todos de una: casi siempre asisten casi todos y luego se corrigen dos. */
function marcarTodos(valor: string): void {
    marcas.value = Object.fromEntries(props.asistencia.lista.map((a) => [a.inscripcion_id, valor]));
}

function cambiarSesion(fecha: string, modalidad: string): void {
    router.get(
        `/docencia/materias/${props.materia.id}`,
        { fecha, modalidad },
        { preserveState: false, preserveScroll: true },
    );
}

function guardarLista(): void {
    formLista.fecha = props.asistencia.fecha;
    formLista.modalidad = props.asistencia.modalidad;
    formLista.asistencias = props.asistencia.lista
        .filter((a) => marcas.value[a.inscripcion_id])
        .map((a) => ({ inscripcion_id: a.inscripcion_id, estatus: marcas.value[a.inscripcion_id] }));

    formLista.post(`/docencia/materias/${props.materia.id}/asistencia`, { preserveScroll: true });
}

function alternarDoblePase(): void {
    router.put(
        `/docencia/materias/${props.materia.id}/asistencia/doble`,
        { doble_pase_lista: !props.asistencia.doble },
        { preserveScroll: true },
    );
}

function colorAsistencia(p: number | null): string {
    if (p === null) return 'var(--color-suave)';

    return p >= 90 ? '#16a34a' : p >= 80 ? '#d97706' : '#dc2626';
}

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
            <BotonVolver href="/docencia" texto="Mis materias" class="mb-4" />

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

        <!-- ===== Pase de lista ===== -->
        <section v-if="puedePasarLista" class="tarjeta overflow-hidden">
            <header class="flex flex-wrap items-end justify-between gap-3 px-6 py-4">
                <div>
                    <h2 class="text-base font-semibold text-contenido">Pase de lista</h2>
                    <p class="mt-0.5 text-sm" :style="{ color: 'var(--color-suave)' }">
                        Marca a todo el grupo y guarda de una vez. Volver a pasar
                        lista del mismo día corrige lo registrado, no lo duplica.
                    </p>
                </div>

                <button
                    type="button"
                    class="rounded-lg border px-3 py-1.5 text-xs font-medium"
                    :style="{ borderColor: 'var(--color-borde)', color: 'var(--color-acento)' }"
                    :title="asistencia.doble
                        ? 'Volver a un solo pase de lista por sesión'
                        : 'Separar teoría y práctica: dos pases de lista el mismo día'"
                    @click="alternarDoblePase"
                >
                    {{ asistencia.doble ? 'Usar un solo pase de lista' : 'Separar teoría y práctica' }}
                </button>
            </header>

            <div class="flex flex-wrap items-end gap-4 border-t border-borde px-6 py-4">
                <div class="w-44">
                    <label class="mb-1 block text-sm font-medium">Fecha de la clase</label>
                    <input
                        type="date"
                        :value="asistencia.fecha"
                        :max="new Date().toISOString().slice(0, 10)"
                        class="w-full rounded-lg border px-3 py-2 text-sm"
                        :style="{ borderColor: 'var(--color-borde)' }"
                        @change="cambiarSesion(($event.target as HTMLInputElement).value, asistencia.modalidad)"
                    />
                </div>

                <div v-if="asistencia.doble" class="w-44">
                    <label class="mb-1 block text-sm font-medium">Sesión</label>
                    <select
                        :value="asistencia.modalidad"
                        class="w-full rounded-lg border px-3 py-2 text-sm"
                        :style="{ borderColor: 'var(--color-borde)' }"
                        @change="cambiarSesion(asistencia.fecha, ($event.target as HTMLSelectElement).value)"
                    >
                        <option value="teorica">Teoría</option>
                        <option value="practica">Práctica</option>
                    </select>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-xs" :style="{ color: 'var(--color-suave)' }">Marcar a todos:</span>
                    <button
                        v-for="e in ESTATUS"
                        :key="e.valor"
                        type="button"
                        class="rounded-lg border px-2.5 py-1 text-xs font-medium"
                        :style="{ borderColor: `color-mix(in srgb, ${e.color} 35%, transparent)`, color: e.color }"
                        @click="marcarTodos(e.valor)"
                    >
                        {{ e.etiqueta }}
                    </button>
                </div>
            </div>

            <ul v-if="asistencia.lista.length" class="divide-y divide-borde border-t border-borde">
                <li v-for="a in asistencia.lista" :key="a.inscripcion_id" class="flex flex-wrap items-center gap-4 px-6 py-2.5">
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm">{{ a.nombre }}</span>
                        <span class="block font-mono text-xs" :style="{ color: 'var(--color-suave)' }">{{ a.matricula }}</span>
                    </span>

                    <!-- Su acumulado: es lo que dice si alguien está en riesgo,
                         que es para lo que sirve pasar lista. -->
                    <span v-if="a.porcentaje !== null" class="shrink-0 text-xs" :style="{ color: colorAsistencia(a.porcentaje) }">
                        {{ a.porcentaje }}%
                        <span v-if="a.faltas" :style="{ color: 'var(--color-suave)' }"> · {{ a.faltas }} falta(s)</span>
                    </span>

                    <span class="flex shrink-0 gap-1">
                        <button
                            v-for="e in ESTATUS"
                            :key="e.valor"
                            type="button"
                            class="rounded-lg px-2.5 py-1 text-xs font-medium transition"
                            :style="marcas[a.inscripcion_id] === e.valor
                                ? { backgroundColor: e.color, color: '#fff' }
                                : { backgroundColor: `color-mix(in srgb, ${e.color} 10%, transparent)`, color: e.color }"
                            @click="marcas[a.inscripcion_id] = e.valor"
                        >
                            {{ e.etiqueta }}
                        </button>
                    </span>
                </li>
            </ul>

            <div v-if="asistencia.lista.length" class="flex flex-wrap items-center gap-3 border-t border-borde px-6 py-4">
                <BotonPrincipal
                    :procesando="formLista.processing"
                    :deshabilitado="sinMarcar === asistencia.lista.length"
                    texto="Guardar la lista"
                    icono="crear"
                    tipo="button"
                    @click="guardarLista"
                />
                <span class="text-sm" :style="{ color: 'var(--color-suave)' }">
                    <template v-if="sinMarcar">
                        {{ sinMarcar }} sin marcar; solo se guardan los marcados.
                    </template>
                    <template v-else>Los {{ asistencia.lista.length }} están marcados.</template>
                </span>
            </div>

            <p v-else class="border-t border-borde px-6 py-8 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                No hay alumnos inscritos a quienes pasar lista.
            </p>
        </section>

        <!-- ===== Actividades del curso ===== -->
        <section v-if="puedeCapturar" class="tarjeta overflow-hidden">
            <header class="flex flex-wrap items-center justify-between gap-3 px-6 py-4">
                <div>
                    <h2 class="text-base font-semibold text-contenido">Actividades</h2>
                    <p class="mt-0.5 text-sm" :style="{ color: 'var(--color-suave)' }">
                        <template v-if="!puedePonderar">
                            Este curso viene armado: lo que agregues aquí no pondera.
                        </template>
                        <template v-else-if="!componentes.length">
                            Esta materia no tiene componentes de evaluación, así que lo que
                            agregues será formativo hasta que control escolar los defina.
                        </template>
                        <template v-else>
                            Lo que agregues puede colgar de un componente del parcial y
                            entonces cuenta para la calificación.
                        </template>
                    </p>
                </div>
                <BotonAccion
                    v-if="puedeAgregar"
                    :variante="editorAbierto ? 'cerrar' : 'agregar'"
                    texto="Actividad"
                    :icono-al-final="editorAbierto"
                    @click="editorAbierto ? (editorAbierto = false) : nuevaActividad()"
                />
            </header>

            <form v-if="editorAbierto" class="border-t border-borde px-6 py-5" @submit.prevent="guardarActividad">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <CampoSelect
                        v-model="formActividad.tipo"
                        etiqueta="Tipo"
                        :opciones="tiposActividad.map((t) => ({ valor: t.valor, texto: t.etiqueta }))"
                        :error="formActividad.errors.tipo"
                        ayuda="La lectura no se entrega ni pondera."
                    />
                    <CampoTexto
                        v-model="formActividad.titulo"
                        etiqueta="Título"
                        requerido
                        :error="formActividad.errors.titulo"
                        class="sm:col-span-2"
                    />
                    <CampoSelect
                        v-if="puedePonderar && formActividad.tipo !== 'lectura'"
                        v-model="formActividad.esquema_evaluacion_id"
                        etiqueta="Cuenta para"
                        :opciones="componentes.map((c) => ({ valor: c.id, texto: c.etiqueta }))"
                        vacio="No cuenta (formativa)"
                        :error="formActividad.errors.esquema_evaluacion_id"
                        ayuda="Al calificarla, el componente se recalcula solo."
                    />
                    <CampoTexto
                        v-if="formActividad.tipo !== 'lectura'"
                        v-model="formActividad.puntos"
                        etiqueta="Sobre cuántos puntos"
                        tipo="number"
                        min="1"
                        :error="formActividad.errors.puntos"
                        ayuda="Su peso dentro del componente."
                    />
                    <CampoTexto v-model="formActividad.abre_en" etiqueta="Abre" tipo="datetime-local" :error="formActividad.errors.abre_en" />
                    <CampoTexto v-model="formActividad.cierra_en" etiqueta="Cierra" tipo="datetime-local" :error="formActividad.errors.cierra_en" />

                    <div class="sm:col-span-2 lg:col-span-3">
                        <label class="mb-1 block text-sm font-medium">Instrucciones</label>
                        <textarea
                            v-model="formActividad.instrucciones"
                            rows="4"
                            class="w-full rounded-lg border px-3 py-2 text-sm"
                            :style="{ borderColor: 'var(--color-borde)' }"
                        />
                    </div>

                    <label class="flex items-center gap-2 text-sm">
                        <input v-model="formActividad.permite_tarde" type="checkbox" class="rounded" />
                        Aceptar entregas tarde (quedan marcadas)
                    </label>
                    <label class="flex items-center gap-2 text-sm">
                        <input v-model="formActividad.publicada" type="checkbox" class="rounded" />
                        Visible para los alumnos
                    </label>
                </div>

                <div class="mt-4 flex items-center gap-3">
                    <BotonPrincipal :procesando="formActividad.processing" :texto="formActividad.id === null ? 'Agregar' : 'Guardar cambios'" icono="crear" />
                    <button type="button" class="rounded-lg border border-borde px-4 py-2 text-sm" @click="editorAbierto = false">
                        Cancelar
                    </button>
                </div>
            </form>

            <ul v-if="actividades.length" class="divide-y divide-borde border-t border-borde">
                <li v-for="a in actividades" :key="a.id" class="flex flex-wrap items-center gap-3 px-6 py-3">
                    <span class="min-w-0 flex-1">
                        <span class="block font-medium text-contenido">
                            {{ a.titulo }}
                            <span v-if="!a.publicada" class="ml-2 rounded-full px-2 py-0.5 text-[11px]" :style="{ backgroundColor: 'color-mix(in srgb, var(--color-suave) 14%, transparent)', color: 'var(--color-suave)' }">
                                borrador
                            </span>
                        </span>
                        <span class="block text-xs" :style="{ color: 'var(--color-suave)' }">
                            {{ a.tipo_etiqueta }}
                            <template v-if="a.se_entrega"> · sobre {{ a.puntos }}</template>
                            <template v-if="a.componente"> · {{ a.componente }}</template>
                            <template v-else-if="a.se_entrega"> · formativa</template>
                            <template v-if="a.cierra_en"> · cierra {{ a.cierra_en.replace('T', ' ') }}</template>
                        </span>
                    </span>
                    <span v-if="a.se_entrega" class="shrink-0 text-xs" :style="{ color: 'var(--color-suave)' }">
                        {{ a.entregadas }}/{{ matriz.length }} entregaron
                    </span>
                    <span class="flex shrink-0 items-center gap-1">
                        <BotonAccion variante="editar" texto="Editar la actividad" @click="editarActividad(a)" />
                        <BotonAccion variante="eliminar" texto="Eliminar la actividad" @click="eliminarActividad(a)" />
                    </span>
                </li>
            </ul>
        </section>

        <!-- ===== Matriz alumnos × actividades ===== -->
        <section v-if="puedeCapturar && actividades.some((a) => a.se_entrega)" class="tarjeta overflow-hidden">
            <header class="px-6 py-4">
                <h2 class="text-base font-semibold text-contenido">Quién entregó qué</h2>
                <p class="mt-0.5 text-sm" :style="{ color: 'var(--color-suave)' }">
                    Una fila por alumno. Toca una casilla entregada para calificarla;
                    el componente del parcial se recalcula solo.
                </p>
            </header>

            <div class="overflow-x-auto border-t border-borde">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-[11px] uppercase tracking-wider" :style="{ color: 'var(--color-suave)', backgroundColor: 'color-mix(in srgb, var(--color-suave) 6%, transparent)' }">
                            <th class="sticky left-0 z-10 px-6 py-3 font-semibold" :style="{ backgroundColor: 'var(--color-superficie)' }">Alumno</th>
                            <th v-for="a in actividades.filter((x) => x.se_entrega)" :key="a.id" class="px-3 py-3 text-center font-semibold">
                                <span class="block max-w-28 truncate" :title="a.titulo">{{ a.titulo }}</span>
                                <span class="font-normal normal-case">de {{ a.puntos }}</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="fila in matriz" :key="fila.inscripcion_id" class="border-t" :style="{ borderColor: 'var(--color-borde)', opacity: fila.de_baja ? 0.5 : 1 }">
                            <td class="sticky left-0 z-10 px-6 py-2" :style="{ backgroundColor: 'var(--color-superficie)' }">
                                <span class="block truncate">{{ fila.nombre }}</span>
                                <span class="block font-mono text-xs" :style="{ color: 'var(--color-suave)' }">{{ fila.matricula }}</span>
                            </td>
                            <td
                                v-for="c in fila.casillas.filter((x) => actividades.find((a) => a.id === x.actividad_id)?.se_entrega)"
                                :key="c.actividad_id"
                                class="px-3 py-2 text-center"
                            >
                                <button
                                    type="button"
                                    class="w-full rounded-lg px-2 py-1 text-xs font-medium disabled:cursor-not-allowed"
                                    :style="colorCasilla(c)"
                                    :disabled="c.entrega_id === null"
                                    :title="c.entrega_id === null ? 'Sin entregar' : `Entregó el ${c.entregada_en}`"
                                    @click="abrirCalificacion(fila, c)"
                                >
                                    {{ c.calificacion ?? (c.entrega_id ? '—' : '·') }}
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Calificar: se abre bajo la tabla con lo que el alumno entregó a
                 la vista. Calificar sin leer la entrega no tendría sentido. -->
            <div
                v-if="calificando"
                class="border-t border-borde px-6 py-4"
                :style="{ borderLeft: '3px solid var(--color-acento)' }"
            >
                <template v-for="fila in matriz.filter((f) => f.inscripcion_id === calificando?.inscripcion)" :key="fila.inscripcion_id">
                    <template v-for="c in fila.casillas.filter((x) => casillaAbierta(fila, x))" :key="c.actividad_id">
                        <p class="text-sm font-medium text-contenido">
                            {{ fila.nombre }} · {{ tituloActividad(c.actividad_id) }}
                        </p>
                        <p class="text-xs" :style="{ color: 'var(--color-suave)' }">
                            Entregó el {{ c.entregada_en }}<span v-if="c.tarde" class="text-amber-600"> · fuera de tiempo</span>
                        </p>

                        <p v-if="c.contenido" class="mt-2 whitespace-pre-line rounded-lg px-3 py-2 text-sm" :style="{ backgroundColor: 'color-mix(in srgb, var(--color-suave) 6%, transparent)' }">
                            {{ c.contenido }}
                        </p>

                        <form class="mt-3 flex flex-wrap items-end gap-3" @submit.prevent="guardarCalificacion(c)">
                            <div class="w-40">
                                <CampoTexto
                                    v-model="formCalificar.calificacion"
                                    etiqueta="Calificación"
                                    tipo="number"
                                    step="0.01"
                                    min="0"
                                    :error="formCalificar.errors.calificacion"
                                />
                            </div>
                            <div class="min-w-64 flex-1">
                                <CampoTexto
                                    v-model="formCalificar.retroalimentacion"
                                    etiqueta="Retroalimentación"
                                    marcador="Lo que el alumno debe saber de su trabajo."
                                    :error="formCalificar.errors.retroalimentacion"
                                />
                            </div>
                            <BotonPrincipal :procesando="formCalificar.processing" texto="Guardar" icono="crear" />
                            <button type="button" class="rounded-lg border border-borde px-4 py-2 text-sm" @click="calificando = null">
                                Cerrar
                            </button>
                        </form>
                    </template>
                </template>
            </div>
        </section>
    </AppLayout>
</template>
