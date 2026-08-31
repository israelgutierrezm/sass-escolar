<script setup lang="ts">
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import CampoSelect from '@/Components/CampoSelect.vue';
import CampoTextarea from '@/Components/CampoTextarea.vue';
import PildoraEstado from '@/Components/PildoraEstado.vue';

/**
 * La contraparte administrativa de un expediente: aceptar o rechazar.
 *
 * ── Por qué es UN componente y no uno por oficio ───────────────────────────
 * El alumno, el docente y el tutor entregan papeles en tres tablas distintas
 * —a propósito, para que los del padre no asomen en el expediente del hijo—
 * pero el ACTO de revisarlos es el mismo, con las mismas reglas: se elige
 * estado, rechazar exige motivo, y el motivo es lo único que lee quien entregó.
 * Escrito tres veces, la tercera acabaría dejando rechazar sin explicación.
 *
 * ── El rechazo pide el motivo, y lo dice antes ─────────────────────────────
 * El campo se marca obligatorio EN CUANTO se elige «rechazado», no al enviar:
 * enterarse después de escribir es enterarse tarde. El servidor lo vuelve a
 * exigir —la pantalla no es una defensa— pero aquí se avisa a tiempo.
 */
interface Documento {
    id: number;
    /** El TIPO de papel, para el filtro: `id` es el de esta entrega concreta. */
    documento_id: number | null;
    documento: string | null;
    descripcion: string | null;
    estado_id: number | null;
    estado: string | null;
    estado_clave: string | null;
    vigencia: string | null;
    vencido: boolean;
    observaciones: string | null;
    subido: string | null;
    /** Quién lo subió, cuando el oficio lo distingue (el alumno y su tutor). */
    entregado_por?: string | null;
}

const props = defineProps<{
    documentos: Documento[];
    estados: { id: number; clave: string; nombre: string }[];
    /** Base de las rutas, sin barra final: `/escolar/alumnos/12/documentos`. */
    base: string;
    puedeValidar: boolean;
    /** Quién entrega, para el texto: «el alumno», «el tutor». */
    quienEntrega: string;
    /** Nota bajo el encabezado, cuando hace falta explicar el alcance. */
    nota?: string;
}>();

const revisando = ref<number | null>(null);

/*
 * Filtros de PANTALLA.
 *
 * Un expediente son unos cuantos papeles —tres en el demo, ocho en el del
 * docente—, así que filtrar aquí responde al instante y sin una petición por
 * tecla. La barra no se dibuja con UN solo documento, donde filtrar no
 * significa nada; con dos ya sirve.
 */
const filtroDocumento = ref<number | null>(null);
const filtroEstado = ref<string | null>(null);

const MINIMO_PARA_FILTRAR = 2;

const vale = computed(() => props.documentos.length >= MINIMO_PARA_FILTRAR);

const opcionesDocumento = computed(() => {
    const vistos = new Map<number, string>();
    props.documentos.forEach((d) => {
        if (d.documento_id !== null && d.documento) vistos.set(d.documento_id, d.documento);
    });

    return [...vistos].map(([valor, texto]) => ({ valor, texto }));
});

const opcionesEstado = computed(() => {
    const vistos = new Map<string, string>();
    props.documentos.forEach((d) => {
        const clave = d.vencido && d.estado_clave !== 'rechazado' ? 'vencido' : (d.estado_clave ?? 'pendiente');
        vistos.set(clave, d.vencido && d.estado_clave !== 'rechazado' ? 'Vencido' : (d.estado ?? 'Pendiente'));
    });

    return [...vistos].map(([valor, texto]) => ({ valor, texto }));
});

const visibles = computed(() => props.documentos.filter((d) => {
    if (filtroDocumento.value && d.documento_id !== filtroDocumento.value) return false;

    if (filtroEstado.value) {
        const clave = d.vencido && d.estado_clave !== 'rechazado' ? 'vencido' : d.estado_clave;
        if (clave !== filtroEstado.value) return false;
    }

    return true;
}));

const hayFiltro = computed(() => Boolean(filtroDocumento.value || filtroEstado.value));

function limpiar(): void {
    filtroDocumento.value = null;
    filtroEstado.value = null;
}

const form = useForm({
    estado_documento_id: null as number | null,
    observaciones: '',
});

const claveElegida = computed(
    () => props.estados.find((e) => e.id === form.estado_documento_id)?.clave ?? null,
);

const esRechazo = computed(() => claveElegida.value === 'rechazado');

const pendientes = computed(() => props.documentos.filter((d) => d.estado_clave === 'pendiente').length);

function abrir(doc: Documento): void {
    revisando.value = doc.id;
    form.estado_documento_id = doc.estado_id;
    form.observaciones = doc.observaciones ?? '';
    form.clearErrors();
    // El punto de partida, para que cerrar sin teclear no se lea como cambio.
    form.defaults();
}

function guardar(doc: Documento): void {
    form.put(`${props.base}/${doc.id}`, {
        preserveScroll: true,
        onSuccess: () => (revisando.value = null),
    });
}

/** El color habla del estado REAL: un aceptado que venció ya no vale. */
function color(doc: Documento): string {
    if (doc.estado_clave === 'rechazado' || doc.vencido) return '#dc2626';
    if (doc.estado_clave === 'aceptado') return '#16a34a';

    return '#f59e0b';
}

function etiqueta(doc: Documento): string {
    if (doc.vencido && doc.estado_clave !== 'rechazado') return 'Vencido';

    return doc.estado ?? 'Pendiente';
}
</script>

<template>
    <section class="tarjeta overflow-hidden">
        <div class="border-b px-6 py-3" :style="{ borderColor: 'var(--color-borde)' }">
            <h2 class="text-base font-semibold">
                Expediente<span v-if="pendientes"> · {{ pendientes }} por revisar</span>
            </h2>
            <p class="mt-0.5 text-sm" :style="{ color: 'var(--color-suave)' }">
                Lo carga {{ quienEntrega }}; aquí se acepta o se rechaza. Un rechazo tiene que
                explicar qué corregir.
            </p>
            <p v-if="nota" class="mt-1 text-xs" :style="{ color: 'var(--color-suave)' }">{{ nota }}</p>

            <!--
                Con un solo documento no se dibuja: filtrar una lista de uno no
                significa nada y la barra ocuparía más que lo que filtra.
            -->
            <div v-if="vale" class="mt-3 grid gap-3 sm:grid-cols-3">
                <CampoSelect v-model="filtroDocumento" etiqueta="Documento" vacio="Todos" :opciones="opcionesDocumento" />
                <CampoSelect v-model="filtroEstado" etiqueta="Estado" vacio="Todos" :opciones="opcionesEstado" />
                <div class="flex items-end">
                    <button
                        v-if="hayFiltro"
                        type="button"
                        class="text-sm underline"
                        :style="{ color: 'var(--color-acento)' }"
                        @click="limpiar"
                    >
                        Quitar filtros
                    </button>
                </div>
            </div>
        </div>

        <ul v-if="visibles.length">
            <li
                v-for="doc in visibles"
                :key="doc.id"
                class="border-t px-6 py-3"
                :style="{ borderColor: 'var(--color-borde)' }"
            >
                <div class="flex flex-wrap items-center justify-between gap-3 text-sm">
                    <div class="min-w-0">
                        <p class="font-medium">{{ doc.documento }}</p>
                        <p v-if="doc.descripcion" class="text-xs" :style="{ color: 'var(--color-suave)' }">
                            {{ doc.descripcion }}
                        </p>
                        <p class="text-xs" :style="{ color: 'var(--color-suave)' }">
                            <span v-if="doc.subido">Subido {{ doc.subido }}</span>
                            <!--
                                Quién lo entregó importa AL REVISAR: un papel que
                                subió el tutor se le reclama al tutor, no al
                                alumno de doce años.
                            -->
                            <span v-if="doc.entregado_por"> por {{ doc.entregado_por }}</span>
                            <span v-if="doc.vigencia"> · vigencia {{ doc.vigencia }}</span>
                            <span v-if="doc.vencido" class="text-red-600"> · vencido</span>
                        </p>
                        <p v-if="doc.observaciones" class="mt-0.5 text-xs italic text-amber-700">
                            {{ doc.observaciones }}
                        </p>
                    </div>

                    <div class="flex shrink-0 items-center gap-3">
                        <PildoraEstado :texto="etiqueta(doc)" :color="color(doc)" />
                        <a :href="`${base}/${doc.id}/descargar`" class="text-sm" :style="{ color: 'var(--color-acento)' }">
                            Descargar
                        </a>
                        <button
                            v-if="puedeValidar"
                            type="button"
                            class="text-sm"
                            :style="{ color: 'var(--color-acento)' }"
                            @click="abrir(doc)"
                        >
                            Revisar
                        </button>
                    </div>
                </div>

                <div
                    v-if="revisando === doc.id"
                    class="mt-3 grid gap-3 rounded-lg p-3 sm:grid-cols-3"
                    style="background-color: color-mix(in srgb, currentColor 4%, transparent)"
                >
                    <CampoSelect
                        v-model="form.estado_documento_id"
                        etiqueta="Estado"
                        :opciones="estados.map((e) => ({ valor: e.id, texto: e.nombre }))"
                        :error="form.errors.estado_documento_id"
                    />
                    <CampoTextarea
                        v-model="form.observaciones"
                        :etiqueta="esRechazo ? 'Motivo del rechazo' : 'Observaciones'"
                        :requerido="esRechazo"
                        :filas="2"
                        :maximo="255"
                        :marcador="esRechazo ? 'Qué tiene que corregir…' : 'Opcional'"
                        :error="form.errors.observaciones"
                        :ayuda="esRechazo
                            ? 'Se le muestra tal cual y le llega como aviso. Es lo único que va a leer.'
                            : undefined"
                    />
                    <div class="flex items-end gap-2">
                        <button
                            type="button"
                            :disabled="form.processing || (esRechazo && !form.observaciones.trim())"
                            class="rounded-lg px-3 py-2 text-sm font-medium disabled:cursor-not-allowed disabled:opacity-50"
                            :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                            @click="guardar(doc)"
                        >
                            Guardar
                        </button>
                        <button
                            type="button"
                            class="rounded-lg border px-3 py-2 text-sm"
                            :style="{ borderColor: 'var(--color-borde)' }"
                            @click="revisando = null"
                        >
                            Cancelar
                        </button>
                    </div>
                </div>
            </li>
        </ul>

        <p v-else class="px-6 py-12 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
            <template v-if="hayFiltro">Ningún documento coincide con esos filtros.</template>
            <template v-else>Todavía no ha cargado documentos.</template>
        </p>
    </section>
</template>
