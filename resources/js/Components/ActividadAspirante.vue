<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AnilloProgreso from '@/Components/AnilloProgreso.vue';

/**
 * La ACTIVIDAD de un prospecto: lo que falta por hacer y lo que ya se hizo.
 *
 * ── Una sola línea de tiempo, en dos bloques ──────────────────────────────
 * Arriba lo AGENDADO, que es lo accionable —lo que hay que hacer hoy—, y abajo
 * el historial en orden inverso, que es como se lee una conversación: lo último
 * primero. No son dos listas de cosas distintas: una llamada agendada y una
 * llamada hecha son la misma entrada en dos momentos de su vida, y por eso al
 * cerrar una de arriba baja sola.
 *
 * ── Lo vencido se señala, no se esconde ───────────────────────────────────
 * Una tarea que se pasó de fecha sigue arriba y marcada. Moverla al fondo o
 * pintarla igual que las demás es como se pierde un prospecto: lo que no
 * incomoda no se atiende.
 *
 * ── Registrar y agendar son dos gestos, no uno con casilla ────────────────
 * «Ya hablé con él» y «hay que hablarle el jueves» se capturan en momentos
 * distintos del día y con la cabeza en cosas distintas. Un solo formulario con
 * un interruptor obliga a leerlo entero para saber cuál de las dos está a punto
 * de hacer.
 */
const props = defineProps<{
    aspiranteId: number;
    /** Quién lo atiende hoy. El titular es quien responde por él. */
    asesores: { persona_id: number; nombre: string; titular: boolean }[];
    /** Reasignar es de quien coordina, no de quien da seguimiento. */
    puedeReasignar: boolean;
    actividad: {
        agendadas: Actividad[];
        historial: Actividad[];
        contactos: number;
    };
    catalogos: {
        tipos: { id: number; nombre: string; exige_proximo_contacto: boolean }[];
        resultados: { id: number; nombre: string; cierra_el_embudo: boolean }[];
        etapas: { id: number; nombre: string }[];
        asesores: { id: number; nombre: string }[];
    };
    /** En qué etapa está. El id y no el nombre: la barra pinta hasta dónde llegó. */
    etapaActualId: number | null;
    /** Cuánto lleva recorrido. Lo calcula el servidor, no la pantalla. */
    avance: { porcentaje: number; paso: number; total: number };
}>();

interface Actividad {
    id: number;
    tipo: string | null;
    estatus: 'agendado' | 'realizado' | 'cancelado';
    nota: string;
    respuesta: string | null;
    resultado: string | null;
    etapa: string | null;
    responsable: string | null;
    cerrada_por: string | null;
    programado_para: string | null;
    momento: string | null;
    cerrado_en: string | null;
    vencida: boolean;
}

const base = computed(() => `/aspirantes/${props.aspiranteId}/actividad`);

// ── Registrar algo que ya pasó ─────────────────────────────────────────────
const registro = useForm({
    tipo_id: null as number | null,
    nota: '',
    resultado_id: null as number | null,
    respuesta: '',
    programado_para: '',
    etapa_destino_id: null as number | null,
});

function registrar(): void {
    registro.post(base.value, {
        preserveScroll: true,
        onSuccess: () => registro.reset(),
    });
}

// ── Agendar algo que falta ─────────────────────────────────────────────────
const agendando = ref(false);
const cita = useForm({
    tipo_id: null as number | null,
    nota: '',
    programado_para: '',
    responsable_id: null as number | null,
});

function agendar(): void {
    cita.post(`${base.value}/agendar`, {
        preserveScroll: true,
        onSuccess: () => {
            cita.reset();
            agendando.value = false;
        },
    });
}

// ── Cerrar una agendada ────────────────────────────────────────────────────
const cerrando = ref<number | null>(null);
const cierre = useForm({
    resultado_id: null as number | null,
    respuesta: '',
    etapa_destino_id: null as number | null,
});

function abrirCierre(id: number): void {
    cerrando.value = cerrando.value === id ? null : id;
    cierre.reset();
    cierre.clearErrors();
}

function cerrar(id: number): void {
    cierre.put(`${base.value}/${id}/cerrar`, {
        preserveScroll: true,
        onSuccess: () => {
            cerrando.value = null;
            cierre.reset();
        },
    });
}

// ── Cancelar ───────────────────────────────────────────────────────────────
const cancelacion = useForm({ motivo: '' });

function cancelar(id: number): void {
    const motivo = window.prompt('¿Por qué se cancela? Queda en el historial.');

    if (motivo === null || motivo.trim() === '') {
        return;
    }

    cancelacion.motivo = motivo;
    cancelacion.put(`${base.value}/${id}/cancelar`, { preserveScroll: true });
}

// ── El asesor titular ──────────────────────────────────────────────────────
const titular = computed(() => props.asesores.find((a) => a.titular) ?? null);

const asignacion = useForm({ persona_id: null as number | null });

function asignarAsesor(): void {
    asignacion.post(`${base.value}/asesor`, {
        preserveScroll: true,
        onSuccess: () => asignacion.reset(),
    });
}

// ── La secuencia del embudo ────────────────────────────────────────────────
/**
 * En qué escalón va. -1 = todavía en ninguno.
 *
 * Se busca por ID y no por nombre: dos etapas pueden llamarse parecido y el
 * nombre lo edita la escuela desde su catálogo.
 */
const pasoActual = computed(() =>
    props.catalogos.etapas.findIndex((e) => e.id === props.etapaActualId),
);

/**
 * ¿Llegó al final del embudo?
 *
 * El último escalón no es «uno más»: es la meta —está listo para inscribirse—
 * y por eso se pinta en VERDE y no en el acento. Con el mismo color que los
 * demás, terminar el recorrido se ve igual que ir por la mitad.
 */
const enLaMeta = computed(() =>
    props.catalogos.etapas.length > 0 && pasoActual.value === props.catalogos.etapas.length - 1,
);

const VERDE = '#16a34a';

/** El color de un escalón alcanzado: verde sólo el último. */
function colorDe(i: number): string {
    return i === props.catalogos.etapas.length - 1 ? VERDE : 'var(--color-acento)';
}

const etapa = useForm({ etapa_crm_id: null as number | null, nota: '' });

function irAEtapa(id: number): void {
    if (id === props.etapaActualId) {
        return;
    }

    etapa.etapa_crm_id = id;
    etapa.post(`${base.value}/etapa`, {
        preserveScroll: true,
        onSuccess: () => etapa.reset(),
    });
}

/** «12 ago, 09:00» — se lee de un vistazo y cabe en un renglón. */
function cuando(fecha: string | null): string {
    if (!fecha) {
        return '—';
    }

    return new Date(fecha.replace(' ', 'T')).toLocaleString('es-MX', {
        day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit',
    });
}

const colorEstatus: Record<string, string> = {
    agendado: 'var(--color-acento)',
    realizado: '#16a34a',
    cancelado: 'var(--color-suave)',
};
</script>

<template>
    <div class="space-y-5">
        <!--
            El embudo como SECUENCIA, no como desplegable.
            ─────────────────────────────────────────────────────────────────
            Era un «Mover a…» con un botón al lado: decía en qué etapa está y
            escondía por completo el camino —cuántas van, cuántas faltan, si
            está a la mitad o a punto de inscribirse—, que es justo lo que se
            quiere ver de un prospecto de un vistazo. Ahora se ve el recorrido
            entero y en cuál va, y se avanza tocando el escalón.

            Los ESCALONES salen del catálogo, no de una lista fija: la escuela
            edita sus etapas y esto las sigue. Por eso el círculo lleva paloma o
            número y no un icono por etapa —un icono cableado se rompería en
            cuanto alguien agregue «Visita al campus»—.
        -->
        <div class="rounded-xl border p-4" :style="{ borderColor: 'var(--color-borde)' }">
            <div class="mb-3 flex items-center gap-3">
                <!-- El anillo dice CUÁNTO lleva; la barra, DÓNDE está. Son la
                     misma información leída de dos maneras, y hacen falta las
                     dos: el porcentaje se compara entre prospectos de un
                     vistazo, el nombre de la etapa dice qué toca hacer. -->
                <AnilloProgreso
                    :porcentaje="avance.porcentaje"
                    :tamano="46"
                    :grosor="4"
                    :color="enLaMeta ? VERDE : 'var(--color-acento)'"
                    :titulo="`Paso ${avance.paso} de ${avance.total}`"
                />
                <div class="min-w-0">
                    <p class="text-xs uppercase tracking-wide text-suave">Etapa del embudo</p>
                    <p class="text-sm font-semibold text-contenido">
                        {{ catalogos.etapas[pasoActual]?.nombre ?? 'Fuera del embudo' }}
                    </p>
                    <p v-if="avance.paso" class="text-xs text-suave">
                        Paso {{ avance.paso }} de {{ avance.total }}
                    </p>
                </div>
            </div>

            <ol class="flex items-start">
                <li
                    v-for="(e, i) in catalogos.etapas"
                    :key="e.id"
                    class="relative min-w-0 flex-1 text-center"
                >
                    <!-- La línea que llega desde el escalón anterior. Va
                         encendida sólo hasta donde el prospecto llegó. -->
                    <span
                        v-if="i > 0"
                        class="absolute right-1/2 top-[18px] h-0.5 w-full"
                        :style="{ backgroundColor: i <= pasoActual ? colorDe(i) : 'var(--color-borde)' }"
                    />

                    <button
                        type="button"
                        class="relative z-10 mx-auto grid h-9 w-9 place-items-center rounded-full border-2 text-xs font-semibold transition disabled:opacity-60"
                        :style="i <= pasoActual
                            ? {
                                backgroundColor: colorDe(i),
                                borderColor: colorDe(i),
                                color: '#fff',
                            }
                            : {
                                backgroundColor: 'var(--color-superficie)',
                                borderColor: 'var(--color-borde)',
                                color: 'var(--color-suave)',
                            }"
                        :class="i === pasoActual ? 'ring-4' : ''"
                        :title="i === pasoActual ? `Está en «${e.nombre}»` : `Mover a «${e.nombre}»`"
                        :disabled="etapa.processing"
                        @click="irAEtapa(e.id)"
                    >
                        <!-- Paloma en lo ya recorrido, número en lo demás: el
                             número solo no distingue «ya pasó» de «va aquí». -->
                        <svg v-if="i < pasoActual" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="3" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                        </svg>
                        <span v-else>{{ i + 1 }}</span>
                    </button>

                    <span
                        class="mt-2 block px-1 text-[11px] leading-tight"
                        :style="{ color: i === pasoActual && enLaMeta ? VERDE : (i <= pasoActual ? 'var(--color-contenido)' : 'var(--color-suave)') }"
                        :class="i === pasoActual ? 'font-semibold' : ''"
                    >
                        {{ e.nombre }}
                    </span>
                </li>
            </ol>

            <!-- El movimiento queda en la bitácora: mover a alguien de etapa ES
                 parte de su historia, no un ajuste silencioso. -->
            <p class="mt-3 text-xs text-suave">
                Toca un escalón para mover al prospecto. Queda registrado abajo, con quién lo hizo.
            </p>
        </div>

        <!-- Quién lo atiende. Va junto a la etapa: las dos contestan «en qué
             punto está y de quién es». -->
        <div class="rounded-xl border p-4" :style="{ borderColor: 'var(--color-borde)' }">
            <div class="flex flex-wrap items-end gap-3">
                <div class="min-w-0 flex-1">
                    <label class="block text-xs uppercase tracking-wide text-suave">Asesor</label>
                    <p class="mt-0.5 text-sm font-medium text-contenido">
                        {{ titular?.nombre ?? 'Sin asignar' }}
                    </p>
                </div>
                <template v-if="puedeReasignar && catalogos.asesores.length">
                    <div class="min-w-[12rem]">
                        <select
                            v-model="asignacion.persona_id"
                            class="w-full rounded-lg border px-3 py-2 text-sm"
                            :style="{ borderColor: 'var(--color-borde)', backgroundColor: 'var(--color-superficie)', color: 'var(--color-contenido)' }"
                        >
                            <option :value="null">{{ titular ? 'Reasignar a…' : 'Asignar a…' }}</option>
                            <option v-for="a in catalogos.asesores" :key="a.id" :value="a.id">{{ a.nombre }}</option>
                        </select>
                    </div>
                    <button
                        type="button"
                        class="rounded-lg px-3.5 py-2 text-sm font-medium disabled:opacity-50"
                        :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                        :disabled="!asignacion.persona_id || asignacion.processing"
                        @click="asignarAsesor"
                    >
                        {{ titular ? 'Reasignar' : 'Asignar' }}
                    </button>
                </template>
            </div>
            <p v-if="puedeReasignar && !catalogos.asesores.length" class="mt-2 text-xs text-suave">
                No hay asesores activos. Da de alta a alguien en
                <a href="/captacion/asesores" :style="{ color: 'var(--color-acento)' }">Asesores</a>.
            </p>
            <p v-else class="mt-2 text-xs text-suave">El cambio queda registrado abajo, con quién lo hizo.</p>
        </div>

        <!-- Lo que falta por hacer -->
        <div>
            <div class="mb-2 flex items-center justify-between gap-3">
                <h3 class="text-sm font-semibold text-contenido">
                    Pendiente
                    <span v-if="actividad.agendadas.length" class="ml-1 text-suave">({{ actividad.agendadas.length }})</span>
                </h3>
                <button
                    type="button"
                    class="rounded-lg border px-3 py-1.5 text-xs font-medium"
                    :style="{ borderColor: 'var(--color-borde)', color: 'var(--color-acento)' }"
                    @click="agendando = !agendando"
                >
                    {{ agendando ? 'Cancelar' : '+ Agendar' }}
                </button>
            </div>

            <form v-if="agendando" class="mb-3 grid gap-3 rounded-xl border p-4 sm:grid-cols-2" :style="{ borderColor: 'var(--color-acento)' }" @submit.prevent="agendar">
                <div>
                    <label class="block text-xs text-suave">Tipo</label>
                    <select v-model="cita.tipo_id" class="mt-1 w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)', backgroundColor: 'var(--color-superficie)', color: 'var(--color-contenido)' }">
                        <option :value="null">Sin tipo</option>
                        <option v-for="t in catalogos.tipos" :key="t.id" :value="t.id">{{ t.nombre }}</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-suave">¿Cuándo?</label>
                    <input v-model="cita.programado_para" type="datetime-local" class="mt-1 w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)', backgroundColor: 'var(--color-superficie)', color: 'var(--color-contenido)' }" />
                    <p v-if="cita.errors.programado_para" class="mt-1 text-xs text-rojo">{{ cita.errors.programado_para }}</p>
                </div>
                <div v-if="catalogos.asesores.length" class="sm:col-span-2">
                    <label class="block text-xs text-suave">Responsable</label>
                    <select v-model="cita.responsable_id" class="mt-1 w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)', backgroundColor: 'var(--color-superficie)', color: 'var(--color-contenido)' }">
                        <option :value="null">Yo</option>
                        <option v-for="a in catalogos.asesores" :key="a.id" :value="a.id">{{ a.nombre }}</option>
                    </select>
                </div>
                <div class="sm:col-span-2">
                    <label class="block text-xs text-suave">¿Qué hay que hacer?</label>
                    <textarea v-model="cita.nota" rows="2" class="mt-1 w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)', backgroundColor: 'var(--color-superficie)', color: 'var(--color-contenido)' }" />
                    <p v-if="cita.errors.nota" class="mt-1 text-xs text-rojo">{{ cita.errors.nota }}</p>
                </div>
                <div class="sm:col-span-2">
                    <button type="submit" class="rounded-lg px-4 py-2 text-sm font-medium disabled:opacity-50" :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }" :disabled="cita.processing">
                        Agendar
                    </button>
                </div>
            </form>

            <p v-if="!actividad.agendadas.length" class="rounded-xl border border-dashed px-4 py-6 text-center text-sm text-suave" :style="{ borderColor: 'var(--color-borde)' }">
                Nada agendado. Un prospecto sin próximo paso es un prospecto que nadie vuelve a marcar.
            </p>

            <ul v-else class="space-y-2">
                <li
                    v-for="a in actividad.agendadas"
                    :key="a.id"
                    class="rounded-xl border p-3"
                    :style="{ borderColor: a.vencida ? '#dc2626' : 'var(--color-borde)' }"
                >
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-contenido">{{ a.tipo ?? 'Actividad' }}</p>
                            <p class="mt-0.5 text-sm text-contenido">{{ a.nota }}</p>
                            <p class="mt-1 text-xs text-suave">
                                {{ cuando(a.programado_para) }}
                                <span v-if="a.vencida" class="ml-1 font-semibold" :style="{ color: '#dc2626' }">· vencida</span>
                                <span v-if="a.responsable"> · {{ a.responsable }}</span>
                            </p>
                        </div>
                        <div class="flex shrink-0 gap-1.5">
                            <button type="button" class="rounded-lg px-2.5 py-1.5 text-xs font-medium" :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }" @click="abrirCierre(a.id)">
                                Registrar
                            </button>
                            <button type="button" class="rounded-lg border px-2.5 py-1.5 text-xs" :style="{ borderColor: 'var(--color-borde)', color: 'var(--color-suave)' }" @click="cancelar(a.id)">
                                Cancelar
                            </button>
                        </div>
                    </div>

                    <!-- El cierre: cómo fue. -->
                    <form v-if="cerrando === a.id" class="mt-3 grid gap-3 border-t pt-3 sm:grid-cols-2" :style="{ borderColor: 'var(--color-borde)' }" @submit.prevent="cerrar(a.id)">
                        <div>
                            <label class="block text-xs text-suave">¿Cómo fue?</label>
                            <select v-model="cierre.resultado_id" class="mt-1 w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)', backgroundColor: 'var(--color-superficie)', color: 'var(--color-contenido)' }">
                                <option :value="null">Elige el desenlace…</option>
                                <option v-for="r in catalogos.resultados" :key="r.id" :value="r.id">{{ r.nombre }}</option>
                            </select>
                            <p v-if="cierre.errors.resultado_id" class="mt-1 text-xs text-rojo">{{ cierre.errors.resultado_id }}</p>
                        </div>
                        <div>
                            <label class="block text-xs text-suave">Mover de etapa (opcional)</label>
                            <select v-model="cierre.etapa_destino_id" class="mt-1 w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)', backgroundColor: 'var(--color-superficie)', color: 'var(--color-contenido)' }">
                                <option :value="null">Dejar como está</option>
                                <option v-for="e in catalogos.etapas" :key="e.id" :value="e.id">{{ e.nombre }}</option>
                            </select>
                        </div>
                        <div class="sm:col-span-2">
                            <label class="block text-xs text-suave">¿Qué contestó?</label>
                            <textarea v-model="cierre.respuesta" rows="2" class="mt-1 w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)', backgroundColor: 'var(--color-superficie)', color: 'var(--color-contenido)' }" />
                        </div>
                        <div class="sm:col-span-2">
                            <button type="submit" class="rounded-lg px-4 py-2 text-sm font-medium disabled:opacity-50" :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }" :disabled="cierre.processing">
                                Guardar
                            </button>
                        </div>
                    </form>
                </li>
            </ul>
        </div>

        <!-- Registrar un contacto que ya ocurrió -->
        <form class="grid gap-3 rounded-xl border p-4 sm:grid-cols-2" :style="{ borderColor: 'var(--color-borde)' }" @submit.prevent="registrar">
            <p class="sm:col-span-2 text-sm font-semibold text-contenido">Registrar un contacto</p>
            <div>
                <label class="block text-xs text-suave">Tipo</label>
                <select v-model="registro.tipo_id" class="mt-1 w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)', backgroundColor: 'var(--color-superficie)', color: 'var(--color-contenido)' }">
                    <option :value="null">Sin tipo</option>
                    <option v-for="t in catalogos.tipos" :key="t.id" :value="t.id">{{ t.nombre }}</option>
                </select>
            </div>
            <div>
                <label class="block text-xs text-suave">¿Cómo fue?</label>
                <select v-model="registro.resultado_id" class="mt-1 w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)', backgroundColor: 'var(--color-superficie)', color: 'var(--color-contenido)' }">
                    <option :value="null">Sin desenlace</option>
                    <option v-for="r in catalogos.resultados" :key="r.id" :value="r.id">{{ r.nombre }}</option>
                </select>
            </div>
            <div class="sm:col-span-2">
                <label class="block text-xs text-suave">Qué pasó</label>
                <textarea v-model="registro.nota" rows="2" class="mt-1 w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)', backgroundColor: 'var(--color-superficie)', color: 'var(--color-contenido)' }" />
                <p v-if="registro.errors.nota" class="mt-1 text-xs text-rojo">{{ registro.errors.nota }}</p>
            </div>
            <div>
                <label class="block text-xs text-suave">Siguiente contacto (opcional)</label>
                <input v-model="registro.programado_para" type="datetime-local" class="mt-1 w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)', backgroundColor: 'var(--color-superficie)', color: 'var(--color-contenido)' }" />
            </div>
            <div>
                <label class="block text-xs text-suave">Mover de etapa (opcional)</label>
                <select v-model="registro.etapa_destino_id" class="mt-1 w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)', backgroundColor: 'var(--color-superficie)', color: 'var(--color-contenido)' }">
                    <option :value="null">Dejar como está</option>
                    <option v-for="e in catalogos.etapas" :key="e.id" :value="e.id">{{ e.nombre }}</option>
                </select>
            </div>
            <div class="sm:col-span-2">
                <button type="submit" class="rounded-lg px-4 py-2 text-sm font-medium disabled:opacity-50" :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }" :disabled="registro.processing">
                    Registrar
                </button>
            </div>
        </form>

        <!-- El historial -->
        <div>
            <h3 class="mb-2 text-sm font-semibold text-contenido">
                Historial
                <span v-if="actividad.contactos" class="ml-1 font-normal text-suave">· {{ actividad.contactos }} contacto(s)</span>
            </h3>

            <p v-if="!actividad.historial.length" class="rounded-xl border border-dashed px-4 py-6 text-center text-sm text-suave" :style="{ borderColor: 'var(--color-borde)' }">
                Todavía no se le ha contactado.
            </p>

            <ol v-else class="space-y-2">
                <li v-for="a in actividad.historial" :key="a.id" class="rounded-xl border p-3" :style="{ borderColor: 'var(--color-borde)' }">
                    <div class="flex flex-wrap items-baseline gap-x-2">
                        <span class="h-2 w-2 shrink-0 rounded-full" :style="{ backgroundColor: colorEstatus[a.estatus] }" />
                        <span class="text-sm font-medium text-contenido">{{ a.tipo ?? 'Contacto' }}</span>
                        <span v-if="a.resultado" class="text-xs" :style="{ color: 'var(--color-acento)' }">{{ a.resultado }}</span>
                        <span v-if="a.estatus === 'cancelado'" class="text-xs text-suave">cancelada</span>
                        <span class="ml-auto text-xs text-suave">{{ cuando(a.cerrado_en ?? a.momento) }}</span>
                    </div>
                    <p class="mt-1 text-sm text-contenido">{{ a.nota }}</p>
                    <p v-if="a.respuesta" class="mt-1 text-sm italic text-suave">«{{ a.respuesta }}»</p>
                    <p class="mt-1 text-xs text-suave">
                        <span v-if="a.cerrada_por">{{ a.cerrada_por }}</span>
                        <span v-else-if="a.responsable">{{ a.responsable }}</span>
                        <span v-if="a.etapa"> · en {{ a.etapa }}</span>
                    </p>
                </li>
            </ol>
        </div>
    </div>
</template>
