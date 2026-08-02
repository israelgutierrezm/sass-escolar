<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';

/*
 * Pase de lista de una sesión.
 *
 * ── Todos asisten hasta que se diga lo contrario ───────────────────────────
 * La lista abre con TODOS marcados como asistencia. En un grupo de cuarenta
 * faltan dos o tres, así que marcar de a uno era hacer cuarenta clics para
 * registrar lo que ya se sabía; ahora el trabajo del caso normal es desmarcar a
 * los que faltaron y guardar.
 *
 * ── Un checkbox, no cuatro botones ─────────────────────────────────────────
 * La pregunta de verdad es binaria: ¿vino o no vino? El retardo y la
 * justificación son matices de esas dos respuestas, y aparecen SOBRE el renglón
 * cuando corresponden —el chip de tarde solo si vino; el de justificar solo si
 * faltó—. Cuatro botones por alumno obligaban a leer cuatro etiquetas cuarenta
 * veces para una decisión de sí o no.
 *
 * ── El justificante se escribe aquí ────────────────────────────────────────
 * El motivo se captura en el mismo renglón de la falta. Mandarlo a otra pantalla
 * significaría, en la práctica, que casi nunca se anota.
 */
interface FilaLista {
    inscripcion_id: number;
    matricula: string | null;
    nombre: string | null;
    estatus: string | null;
    observacion: string | null;
    faltas: number;
    retardos: number;
    porcentaje: number | null;
}

interface SesionDelMes {
    clave: string;
    fecha: string;
    dia: number;
    modalidad: string;
}

const props = defineProps<{
    materiaId: number;
    asistencia: {
        fecha: string;
        modalidad: string;
        doble: boolean;
        lista: FilaLista[];
        sesiones: { fecha: string; modalidad: string }[];
        /** Meses con algo registrado, 'AAAA-MM', del más reciente al más viejo. */
        meses: string[];
        mes: string | null;
        sesionesDelMes: SesionDelMes[];
        rejilla: Record<number, Record<string, { estatus: string; observacion: string | null }>>;
    };
}>();

const PRESENTE = 'presente';
const RETARDO = 'retardo';
const FALTA = 'falta';
const JUSTIFICADA = 'justificada';

/*
 * Sin registro previo, presente. Con registro, lo que ya quedó guardado: volver
 * a abrir una sesión pasada tiene que mostrar lo que se registró, no proponer
 * asistencia perfecta encima de un día que ya se cerró.
 */
const marcas = ref<Record<number, string>>({});
const motivos = ref<Record<number, string>>({});

function reiniciar(): void {
    marcas.value = Object.fromEntries(
        props.asistencia.lista.map((a) => [a.inscripcion_id, a.estatus ?? PRESENTE]),
    );
    motivos.value = Object.fromEntries(
        props.asistencia.lista.map((a) => [a.inscripcion_id, a.observacion ?? '']),
    );
}

reiniciar();

// Cambiar de fecha o de sesión trae otra lista: hay que releerla.
watch(() => [props.asistencia.fecha, props.asistencia.modalidad], reiniciar);

const vino = (id: number): boolean => [PRESENTE, RETARDO].includes(marcas.value[id]);

/** El checkbox: vino o no vino. Los matices se conservan si siguen aplicando. */
function alternarAsistencia(id: number): void {
    marcas.value[id] = vino(id) ? FALTA : PRESENTE;
}

/** Vino, pero tarde. */
function alternarRetardo(id: number): void {
    marcas.value[id] = marcas.value[id] === RETARDO ? PRESENTE : RETARDO;
}

/** Faltó, pero con motivo. */
function alternarJustificada(id: number): void {
    marcas.value[id] = marcas.value[id] === JUSTIFICADA ? FALTA : JUSTIFICADA;
}

const conteo = computed(() => {
    const c = { presente: 0, retardo: 0, falta: 0, justificada: 0 };

    for (const a of props.asistencia.lista) {
        const m = marcas.value[a.inscripcion_id] as keyof typeof c;
        if (m in c) c[m]++;
    }

    return c;
});

/** Cuántos hay que tocar de verdad: lo que se aparta del caso normal. */
const excepciones = computed(
    () => conteo.value.retardo + conteo.value.falta + conteo.value.justificada,
);

const yaRegistrada = computed(() => props.asistencia.lista.some((a) => a.estatus !== null));

function colorDe(estatus: string): string {
    return {
        presente: '#16a34a',
        retardo: '#d97706',
        falta: '#dc2626',
        justificada: 'var(--color-suave)',
    }[estatus] ?? 'var(--color-suave)';
}

function colorAcumulado(p: number | null): string {
    if (p === null) return 'var(--color-suave)';

    return p >= 90 ? '#16a34a' : p >= 80 ? '#d97706' : '#dc2626';
}

/* ── Guardar ───────────────────────────────────────────────────────────── */

const form = useForm({ fecha: '', modalidad: '', asistencias: [] as unknown[] });

function guardar(): void {
    form.fecha = props.asistencia.fecha;
    form.modalidad = props.asistencia.modalidad;
    // Se manda la lista COMPLETA: ahora todos tienen estado, y omitir a alguien
    // dejaría su sesión sin registrar sin que nada lo dijera.
    form.asistencias = props.asistencia.lista.map((a) => ({
        inscripcion_id: a.inscripcion_id,
        estatus: marcas.value[a.inscripcion_id],
        observacion: marcas.value[a.inscripcion_id] === JUSTIFICADA
            ? (motivos.value[a.inscripcion_id] || null)
            : null,
    }));

    form.post(`/docencia/materias/${props.materiaId}/asistencia`, { preserveScroll: true });
}

/*
 * Igual que el mes: cambiar el día no debe expulsar de la pestaña. Las marcas se
 * releen solas —el watcher sobre fecha y modalidad llama a `reiniciar`—, así que
 * conservar el estado no deja datos viejos en pantalla.
 */
function cambiarSesion(fecha: string, modalidad: string): void {
    router.get(
        `/docencia/materias/${props.materiaId}`,
        { fecha, modalidad, mes: props.asistencia.mes },
        { preserveState: true, preserveScroll: true },
    );
}

/* ── Lo ya registrado ──────────────────────────────────────────────────── */

/*
 * El pase de lista responde «¿quién vino hoy?»; la rejilla responde «¿cómo ha
 * ido el mes?». Son dos preguntas y hasta ahora la segunda obligaba a abrir el
 * día por día para reconstruirla.
 */
const verRejilla = ref(false);

/*
 * Se conserva el estado al cambiar de mes: recargar la pantalla entera devolvía
 * al usuario a la primera pestaña y cerraba la rejilla que acababa de abrir.
 * Los props llegan nuevos igual; lo que se preserva es dónde estaba parado.
 */
function cambiarMes(mes: string): void {
    router.get(
        `/docencia/materias/${props.materiaId}`,
        { fecha: props.asistencia.fecha, modalidad: props.asistencia.modalidad, mes },
        { preserveState: true, preserveScroll: true },
    );
}

/** Una letra por estado: con veinte columnas, la palabra no cabe. */
const inicialDe: Record<string, string> = {
    presente: 'A',
    retardo: 'R',
    falta: 'F',
    justificada: 'J',
};

const nombreDe: Record<string, string> = {
    presente: 'Asistencia',
    retardo: 'Retardo',
    falta: 'Falta',
    justificada: 'Falta justificada',
};

function celda(inscripcionId: number, clave: string) {
    return props.asistencia.rejilla[inscripcionId]?.[clave];
}

/** El total del mes por alumno: es el número que se reporta. */
function faltasDelMes(inscripcionId: number): number {
    const suyas = props.asistencia.rejilla[inscripcionId] ?? {};

    return Object.values(suyas).filter((c) => c.estatus === 'falta').length;
}

const MESES = [
    'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
    'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre',
];

/**
 * «noviembre 2025», no «2025-11».
 *
 * Con el año siempre visible: un curso de noviembre a febrero cruza el año, y
 * ver solo el mes dejaría dos «febrero» indistinguibles en el selector.
 */
function nombreMes(mes: string): string {
    const [anio, num] = mes.split('-');

    return `${MESES[Number(num) - 1]} ${anio}`;
}

function alternarDoblePase(): void {
    router.put(
        `/docencia/materias/${props.materiaId}/asistencia/doble`,
        { doble_pase_lista: !props.asistencia.doble },
        { preserveScroll: true },
    );
}

const hoy = new Date().toISOString().slice(0, 10);
</script>

<template>
    <section class="tarjeta overflow-hidden">
        <header class="flex flex-wrap items-end justify-between gap-3 px-6 py-4">
            <div>
                <h2 class="text-base font-semibold text-contenido">Pase de lista</h2>
                <p class="mt-0.5 text-sm text-suave">
                    Todos empiezan como asistencia: desmarca a quien faltó y guarda.
                    Volver a pasar lista del mismo día corrige lo registrado.
                </p>
            </div>

            <button
                type="button"
                class="rounded-lg border px-3 py-1.5 text-xs font-medium"
                :style="{ borderColor: 'var(--color-borde)', color: 'var(--color-acento)' }"
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
                    :max="hoy"
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

            <!-- El resumen en vivo: de un vistazo, cuántos van a quedar mal. -->
            <div class="flex flex-wrap items-center gap-3 pb-1 text-xs">
                <span :style="{ color: colorDe('presente') }">
                    <strong>{{ conteo.presente }}</strong> asistencias
                </span>
                <span v-if="conteo.retardo" :style="{ color: colorDe('retardo') }">
                    <strong>{{ conteo.retardo }}</strong> retardos
                </span>
                <span v-if="conteo.falta" :style="{ color: colorDe('falta') }">
                    <strong>{{ conteo.falta }}</strong> faltas
                </span>
                <span v-if="conteo.justificada" class="text-suave">
                    <strong>{{ conteo.justificada }}</strong> justificadas
                </span>
            </div>

            <button
                v-if="excepciones > 0"
                type="button"
                class="rounded-lg border px-2.5 py-1 text-xs"
                :style="{ borderColor: 'var(--color-borde)', color: 'var(--color-suave)' }"
                @click="marcas = Object.fromEntries(asistencia.lista.map((a) => [a.inscripcion_id, PRESENTE]))"
            >
                Marcar a todos como asistencia
            </button>
        </div>

        <p
            v-if="yaRegistrada"
            class="border-t border-borde px-6 py-2 text-xs"
            :style="{ backgroundColor: 'color-mix(in srgb, var(--color-acento) 6%, transparent)', color: 'var(--color-acento)' }"
        >
            Esta sesión ya tiene lista registrada. Lo que guardes la corrige.
        </p>

        <ul v-if="asistencia.lista.length" class="divide-y divide-borde border-t border-borde">
            <li
                v-for="a in asistencia.lista"
                :key="a.inscripcion_id"
                class="px-6 py-2.5 transition"
                :style="{
                    backgroundColor: vino(a.inscripcion_id)
                        ? 'transparent'
                        : `color-mix(in srgb, ${colorDe(marcas[a.inscripcion_id])} 6%, transparent)`,
                }"
            >
                <div class="flex flex-wrap items-center gap-3">
                    <!-- Vino o no vino: la pregunta de verdad. -->
                    <label class="flex shrink-0 cursor-pointer items-center">
                        <input
                            type="checkbox"
                            class="h-5 w-5 cursor-pointer rounded"
                            :checked="vino(a.inscripcion_id)"
                            :style="{ accentColor: colorDe(marcas[a.inscripcion_id]) }"
                            @change="alternarAsistencia(a.inscripcion_id)"
                        />
                    </label>

                    <span class="min-w-0 flex-1">
                        <span
                            class="block truncate text-sm"
                            :style="{ opacity: vino(a.inscripcion_id) ? 1 : 0.65 }"
                        >
                            {{ a.nombre }}
                        </span>
                        <span class="block font-mono text-xs text-suave">{{ a.matricula }}</span>
                    </span>

                    <!-- Su acumulado: es lo que dice si alguien está en riesgo,
                         que es para lo que sirve pasar lista. -->
                    <span
                        v-if="a.porcentaje !== null"
                        class="shrink-0 text-xs"
                        :style="{ color: colorAcumulado(a.porcentaje) }"
                    >
                        {{ a.porcentaje }}%
                        <span v-if="a.faltas" class="text-suave"> · {{ a.faltas }} falta(s)</span>
                    </span>

                    <!-- El matiz que aplica al estado actual, y solo ese. -->
                    <span class="flex shrink-0 items-center gap-2">
                        <button
                            v-if="vino(a.inscripcion_id)"
                            type="button"
                            class="rounded-full border px-2.5 py-1 text-xs font-medium transition"
                            :style="marcas[a.inscripcion_id] === RETARDO
                                ? { backgroundColor: colorDe(RETARDO), borderColor: colorDe(RETARDO), color: '#fff' }
                                : { borderColor: 'var(--color-borde)', color: 'var(--color-suave)' }"
                            @click="alternarRetardo(a.inscripcion_id)"
                        >
                            Llegó tarde
                        </button>

                        <button
                            v-else
                            type="button"
                            class="rounded-full border px-2.5 py-1 text-xs font-medium transition"
                            :style="marcas[a.inscripcion_id] === JUSTIFICADA
                                ? { backgroundColor: 'var(--color-suave)', borderColor: 'var(--color-suave)', color: '#fff' }
                                : { borderColor: colorDe(FALTA), color: colorDe(FALTA) }"
                            @click="alternarJustificada(a.inscripcion_id)"
                        >
                            {{ marcas[a.inscripcion_id] === JUSTIFICADA ? 'Justificada' : 'Justificar' }}
                        </button>
                    </span>
                </div>

                <!-- El motivo, en el mismo renglón de la falta. -->
                <input
                    v-if="marcas[a.inscripcion_id] === JUSTIFICADA"
                    v-model="motivos[a.inscripcion_id]"
                    type="text"
                    maxlength="300"
                    class="mt-2 w-full rounded-lg border px-3 py-1.5 text-sm"
                    :style="{ borderColor: 'var(--color-borde)' }"
                    placeholder="Motivo del justificante (cita médica, permiso…)"
                />
            </li>
        </ul>

        <div v-if="asistencia.lista.length" class="flex flex-wrap items-center gap-3 border-t border-borde px-6 py-4">
            <BotonPrincipal
                :procesando="form.processing"
                texto="Guardar la lista"
                icono="crear"
                tipo="button"
                @click="guardar"
            />
            <span class="text-sm text-suave">
                <template v-if="excepciones === 0">
                    Asistencia completa: los {{ asistencia.lista.length }} presentes.
                </template>
                <template v-else>
                    {{ excepciones }} de {{ asistencia.lista.length }} con algo distinto de asistencia.
                </template>
            </span>
        </div>

        <p v-else class="border-t border-borde px-6 py-8 text-center text-sm text-suave">
            No hay alumnos inscritos a quienes pasar lista.
        </p>
    </section>

    <!-- ===== Lo ya registrado, en rejilla ===== -->
    <section v-if="asistencia.meses.length" class="tarjeta overflow-hidden">
        <header class="flex flex-wrap items-end justify-between gap-3 px-6 py-4">
            <div>
                <button
                    type="button"
                    class="flex items-center gap-2 text-base font-semibold text-contenido"
                    @click="verRejilla = !verRejilla"
                >
                    <span
                        class="inline-block transition-transform"
                        :style="{ transform: verRejilla ? 'rotate(90deg)' : 'none' }"
                    >›</span>
                    Lo ya registrado
                </button>
                <p class="mt-0.5 text-sm text-suave">
                    {{ asistencia.sesionesDelMes.length }} sesión(es) en
                    {{ asistencia.mes ? nombreMes(asistencia.mes) : '' }}.
                </p>
            </div>

            <!-- El mes es la unidad natural: es como se reportan las faltas, y
                 deja recorrer un curso que cruza el año sin partirlo. -->
            <label v-if="verRejilla" class="block">
                <span class="mb-1 block text-xs text-suave">Mes</span>
                <select
                    :value="asistencia.mes ?? ''"
                    class="rounded-lg border px-3 py-1.5 text-sm capitalize"
                    :style="{ borderColor: 'var(--color-borde)' }"
                    @change="cambiarMes(($event.target as HTMLSelectElement).value)"
                >
                    <option v-for="m in asistencia.meses" :key="m" :value="m">{{ nombreMes(m) }}</option>
                </select>
            </label>
        </header>

        <div v-if="verRejilla" class="overflow-x-auto border-t border-borde">
            <table class="w-full text-sm">
                <thead>
                    <tr
                        class="text-[11px] uppercase tracking-wider"
                        :style="{ color: 'var(--color-suave)', backgroundColor: 'color-mix(in srgb, var(--color-suave) 6%, transparent)' }"
                    >
                        <th
                            class="sticky left-0 z-10 px-6 py-3 text-left font-semibold"
                            :style="{ backgroundColor: 'var(--color-superficie)' }"
                        >
                            Alumno
                        </th>
                        <th
                            v-for="s in asistencia.sesionesDelMes"
                            :key="s.clave"
                            class="px-2 py-3 text-center font-semibold"
                            :title="`${s.fecha}${s.modalidad !== 'unica' ? ' · ' + s.modalidad : ''}`"
                        >
                            <span class="block">{{ s.dia }}</span>
                            <span
                                v-if="s.modalidad !== 'unica'"
                                class="block text-[9px] font-normal normal-case opacity-70"
                            >
                                {{ s.modalidad === 'teorica' ? 'teo' : 'prá' }}
                            </span>
                        </th>
                        <th class="px-3 py-3 text-center font-semibold">Faltas</th>
                    </tr>
                </thead>

                <tbody>
                    <tr
                        v-for="a in asistencia.lista"
                        :key="a.inscripcion_id"
                        class="border-t"
                        :style="{ borderColor: 'var(--color-borde)' }"
                    >
                        <td
                            class="sticky left-0 z-10 px-6 py-2"
                            :style="{ backgroundColor: 'var(--color-superficie)' }"
                        >
                            <span class="block truncate text-sm">{{ a.nombre }}</span>
                            <span class="block font-mono text-xs text-suave">{{ a.matricula }}</span>
                        </td>

                        <td
                            v-for="s in asistencia.sesionesDelMes"
                            :key="s.clave"
                            class="px-2 py-2 text-center"
                        >
                            <!-- Una letra y un color: con veinte columnas la
                                 palabra no cabe, y la letra sigue diciendo el
                                 estado a quien no distingue los tonos. -->
                            <span
                                v-if="celda(a.inscripcion_id, s.clave)"
                                class="mx-auto grid h-6 w-6 place-items-center rounded text-xs font-semibold"
                                :style="{
                                    backgroundColor: `color-mix(in srgb, ${colorDe(celda(a.inscripcion_id, s.clave)!.estatus)} 14%, transparent)`,
                                    color: colorDe(celda(a.inscripcion_id, s.clave)!.estatus),
                                }"
                                :title="`${nombreDe[celda(a.inscripcion_id, s.clave)!.estatus]}${celda(a.inscripcion_id, s.clave)!.observacion ? ' · ' + celda(a.inscripcion_id, s.clave)!.observacion : ''}`"
                            >
                                {{ inicialDe[celda(a.inscripcion_id, s.clave)!.estatus] ?? '·' }}
                            </span>
                            <span v-else class="text-suave" title="Sin registro de esa sesión">·</span>
                        </td>

                        <td class="px-3 py-2 text-center">
                            <span
                                class="text-sm font-semibold"
                                :style="{ color: faltasDelMes(a.inscripcion_id) ? colorDe(FALTA) : 'var(--color-suave)' }"
                            >
                                {{ faltasDelMes(a.inscripcion_id) }}
                            </span>
                        </td>
                    </tr>
                </tbody>
            </table>

            <div class="flex flex-wrap items-center gap-4 border-t border-borde px-6 py-2.5 text-xs text-suave">
                <span v-for="(nombre, clave) in nombreDe" :key="clave" class="flex items-center gap-1.5">
                    <span
                        class="grid h-5 w-5 place-items-center rounded text-[10px] font-semibold"
                        :style="{
                            backgroundColor: `color-mix(in srgb, ${colorDe(clave)} 14%, transparent)`,
                            color: colorDe(clave),
                        }"
                    >{{ inicialDe[clave] }}</span>
                    {{ nombre }}
                </span>
            </div>
        </div>
    </section>
</template>
