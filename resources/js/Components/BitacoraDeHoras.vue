<script setup lang="ts">
/**
 * La bitácora de horas de un expediente, para los DOS oficios.
 *
 * ── Un componente y no dos ────────────────────────────────────────────────
 * El alumno registra sus jornadas y la escuela las aprueba; la lista es la
 * misma y sólo cambia qué botones lleva cada renglón. Escrita dos veces, una de
 * las dos acabaría sin decir por qué se rechazó una jornada — que es lo único
 * que el alumno puede usar para corregirla.
 *
 * ── Y el permiso lo pone quien lo usa ─────────────────────────────────────
 * `puedeCapturar` y `puedeRevisar` llegan resueltos, igual que `MenuAcciones`
 * recibe su lista ya filtrada. El componente no sabe de permisos y no debe
 * saber.
 */
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoTextarea from '@/Components/CampoTextarea.vue';
import Modal from '@/Components/Modal.vue';
import PildoraEstado from '@/Components/PildoraEstado.vue';
import { hoyLocal } from '@/utils/fechas';

interface Jornada {
    id: number;
    fecha: string | null;
    inicio: string;
    fin: string;
    horas: number;
    actividad: string;
    modalidad?: string | null;
    estado: string;
    motivo_rechazo: string | null;
    editable?: boolean;
    tiene_evidencia?: boolean;
    capturada_por?: string | null;
}

const props = defineProps<{
    expedienteId: number;
    horas: {
        aprobadas: number;
        faltan: number | null;
        requeridas?: number | null;
        admite: boolean;
        max_dia?: number | null;
        max_semana?: number | null;
        jornadas: Jornada[];
    };
    modalidades?: { id: number; nombre: string }[];
    puedeCapturar: boolean;
    puedeRevisar: boolean;
    pideUbicacion?: boolean;
}>();

const COLORES: Record<string, string> = {
    capturada: '#0284c7',
    aprobada: '#16a34a',
    rechazada: '#b91c1c',
};

const ETIQUETAS: Record<string, string> = {
    capturada: 'Sin revisar',
    aprobada: 'Aprobada',
    rechazada: 'Rechazada',
};

const errores = ref<Record<string, string>>({});
const procesando = ref(false);
const capturando = ref(false);
const editando = ref<Jornada | null>(null);
const rechazando = ref<Jornada | null>(null);
const motivo = ref('');
const archivo = ref<File | null>(null);
const ubicacion = ref<{ latitud: number; longitud: number } | null>(null);

const jornada = ref<Record<string, unknown>>({});

function sembrar(j: Jornada | null = null): void {
    errores.value = {};
    archivo.value = null;
    ubicacion.value = null;
    jornada.value = {
        fecha: j?.fecha ?? hoyLocal(),
        hora_inicio: j?.inicio ?? '09:00',
        hora_fin: j?.fin ?? '13:00',
        minutos_descanso: 0,
        actividad: j?.actividad ?? '',
        modalidad_id: null,
    };
}

sembrar();

/* Lo que ya suma, en porcentaje, para la barra. */
const avance = computed(() => {
    const meta = props.horas.requeridas;

    if (!meta) return null;

    return Math.min(100, Math.round((props.horas.aprobadas / meta) * 1000) / 10);
});

/* Los topes en una frase, o cadena vacía si la regla no pone ninguno. */
const topes = computed(() => {
    const partes: string[] = [];

    if (props.horas.max_dia) partes.push(`${props.horas.max_dia} h al día`);
    if (props.horas.max_semana) partes.push(`${props.horas.max_semana} h a la semana`);

    return partes.length ? ` Como mucho ${partes.join(' y ')}.` : '';
});

function abrirCaptura(): void {
    sembrar();
    editando.value = null;
    capturando.value = true;

    /*
     * La ubicación se pide SÓLO si la escuela la encendió, y no bloquea nada:
     * quien no dé el permiso del navegador registra su jornada igual. Nunca es
     * obligatoria — instrucción explícita del cliente.
     */
    if (props.pideUbicacion && navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(
            (p) => (ubicacion.value = { latitud: p.coords.latitude, longitud: p.coords.longitude }),
            () => (ubicacion.value = null),
            { timeout: 5000 },
        );
    }
}

function abrirEdicion(j: Jornada): void {
    sembrar(j);
    editando.value = j;
    capturando.value = true;
}

function guardar(): void {
    procesando.value = true;

    const base = `/procesos/expedientes/${props.expedienteId}/horas`;
    const datos: Record<string, unknown> = { ...jornada.value, ...(ubicacion.value ?? {}) };

    if (editando.value) {
        router.put(`${base}/${editando.value.id}`, datos, {
            preserveScroll: true,
            onError: (e) => (errores.value = e),
            onSuccess: () => (capturando.value = false),
            onFinish: () => (procesando.value = false),
        });

        return;
    }

    if (archivo.value) datos.evidencia = archivo.value;

    router.post(base, datos, {
        preserveScroll: true,
        forceFormData: archivo.value !== null,
        onError: (e) => (errores.value = e),
        onSuccess: () => (capturando.value = false),
        onFinish: () => (procesando.value = false),
    });
}

function aprobar(j: Jornada): void {
    router.post(`/procesos/expedientes/${props.expedienteId}/horas/${j.id}/revisar`, { aprobada: true }, {
        preserveScroll: true,
    });
}

function rechazar(): void {
    if (rechazando.value === null) return;

    procesando.value = true;

    router.post(
        `/procesos/expedientes/${props.expedienteId}/horas/${rechazando.value.id}/revisar`,
        { aprobada: false, motivo: motivo.value },
        {
            preserveScroll: true,
            onError: (e) => (errores.value = e),
            onSuccess: () => {
                rechazando.value = null;
                motivo.value = '';
            },
            onFinish: () => (procesando.value = false),
        },
    );
}
</script>

<template>
    <div>
        <!-- El avance, arriba: es lo primero que se mira. -->
        <div v-if="horas.requeridas" class="mb-4">
            <div class="mb-1 flex flex-wrap items-center justify-between gap-2 text-sm">
                <span :style="{ color: 'var(--color-suave)' }">Horas aprobadas</span>
                <span class="tabular-nums">
                    {{ horas.aprobadas }} de {{ horas.requeridas }}
                    <span v-if="horas.faltan" :style="{ color: 'var(--color-suave)' }">
                        · faltan {{ horas.faltan }}
                    </span>
                    <span v-else :style="{ color: '#16a34a' }">· ya las tienes</span>
                </span>
            </div>
            <div class="h-2 w-full overflow-hidden rounded-full" :style="{ backgroundColor: 'color-mix(in srgb, var(--color-suave) 16%, transparent)' }">
                <div class="h-full rounded-full" :style="{ width: `${avance}%`, backgroundColor: 'var(--color-acento)' }" />
            </div>
            <!--
                Se dice que sólo cuentan las aprobadas. Sin esto, quien captura
                diez jornadas cree que ya lleva diez y descubre que no el día
                que alguien las revise.
            -->
            <!--
                El texto de los topes se arma en una sola expresión: encadenando
                `<span v-if>` el HTML se queda sin el espacio entre ellos y salía
                «al día y30 h a la semana».
            -->
            <p class="mt-1 text-xs" :style="{ color: 'var(--color-suave)' }">
                Sólo cuentan las jornadas aprobadas.{{ topes }}
            </p>
        </div>

        <div v-if="puedeCapturar && horas.admite" class="mb-3">
            <button
                type="button"
                class="rounded-lg px-3 py-1.5 text-sm font-medium text-white"
                :style="{ backgroundColor: 'var(--color-acento)' }"
                @click="abrirCaptura"
            >Registrar una jornada</button>
        </div>

        <!--
            Por qué no se puede capturar, cuando no se puede. Un formulario
            ausente sin explicación se lee como que algo se rompió.
        -->
        <p v-else-if="puedeCapturar" class="mb-3 text-sm" :style="{ color: 'var(--color-suave)' }">
            Las horas se registran mientras el proceso está en curso.
        </p>

        <div class="overflow-x-auto">
            <table v-if="horas.jornadas.length" class="w-full text-sm">
                <thead>
                    <tr
                        class="text-left text-[11px] uppercase tracking-wider"
                        :style="{ color: 'var(--color-suave)', backgroundColor: 'color-mix(in srgb, var(--color-suave) 6%, transparent)' }"
                    >
                        <th class="px-4 py-2 font-semibold">Día</th>
                        <th class="px-4 py-2 font-semibold">Horario</th>
                        <th class="px-4 py-2 font-semibold text-right">Horas</th>
                        <th class="px-4 py-2 font-semibold">Actividad</th>
                        <th class="px-4 py-2 font-semibold">Estado</th>
                        <th class="px-4 py-2 font-semibold text-right">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="j in horas.jornadas"
                        :key="j.id"
                        class="border-t align-top"
                        :style="{ borderColor: 'var(--color-borde)' }"
                    >
                        <td class="px-4 py-3 tabular-nums whitespace-nowrap">{{ j.fecha }}</td>
                        <td class="px-4 py-3 tabular-nums whitespace-nowrap">{{ j.inicio }}–{{ j.fin }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ j.horas }}</td>
                        <td class="px-4 py-3">
                            <span>{{ j.actividad }}</span>
                            <span v-if="j.capturada_por" class="mt-0.5 block text-xs" :style="{ color: 'var(--color-suave)' }">
                                La capturó {{ j.capturada_por }}
                            </span>
                            <!-- El motivo del rechazo, a la vista: es lo único
                                 que el alumno puede usar para corregirla. -->
                            <span v-if="j.motivo_rechazo" class="mt-0.5 block text-xs" :style="{ color: '#b91c1c' }">
                                {{ j.motivo_rechazo }}
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <PildoraEstado :texto="ETIQUETAS[j.estado] ?? j.estado" :color="COLORES[j.estado] ?? '#64748b'" sin-capitalizar />
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex flex-wrap items-center justify-end gap-2 text-xs">
                                <a
                                    v-if="j.tiene_evidencia"
                                    class="underline"
                                    :href="`/procesos/expedientes/${expedienteId}/horas/${j.id}/evidencia`"
                                >Evidencia</a>

                                <button
                                    v-if="puedeCapturar && j.editable !== false && j.estado !== 'aprobada'"
                                    type="button"
                                    class="underline"
                                    @click="abrirEdicion(j)"
                                >Corregir</button>

                                <template v-if="puedeRevisar && j.estado === 'capturada'">
                                    <button type="button" class="underline" :style="{ color: '#16a34a' }" @click="aprobar(j)">Aprobar</button>
                                    <button type="button" class="underline" :style="{ color: '#b91c1c' }" @click="rechazando = j">Rechazar</button>
                                </template>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>

            <p v-else class="py-8 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                Todavía no hay jornadas registradas.
            </p>
        </div>

        <Modal v-if="capturando" :etiqueta="editando ? 'Corregir jornada' : 'Registrar jornada'" ancho="max-w-xl" @cerrar="capturando = false">
            <template #default="{ cerrar }">
                <form class="space-y-4 p-6" @submit.prevent="guardar">
                    <h2 class="text-base font-semibold">{{ editando ? 'Corregir la jornada' : 'Registrar una jornada' }}</h2>

                    <div class="grid gap-4 sm:grid-cols-3">
                        <CampoTexto v-model="jornada.fecha" etiqueta="Día" tipo="date" requerido :error="errores.fecha" />
                        <CampoTexto v-model="jornada.hora_inicio" etiqueta="Entrada" tipo="time" requerido :error="errores.hora_inicio" />
                        <CampoTexto v-model="jornada.hora_fin" etiqueta="Salida" tipo="time" requerido :error="errores.hora_fin" />
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <CampoTexto
                            v-model.number="jornada.minutos_descanso"
                            etiqueta="Descanso (minutos)"
                            tipo="number"
                            paso="1"
                            ayuda="Lo que no cuenta: la comida, por ejemplo."
                            :error="errores.minutos_descanso"
                        />
                        <CampoSelect
                            v-if="modalidades?.length"
                            v-model="jornada.modalidad_id"
                            etiqueta="Modalidad"
                            :opciones="modalidades.map((m) => ({ valor: m.id, texto: m.nombre }))"
                            vacio="La del expediente"
                            :error="errores.modalidad_id"
                        />
                    </div>

                    <CampoTextarea
                        v-model="jornada.actividad"
                        etiqueta="¿Qué hiciste?"
                        requerido
                        :filas="3"
                        ayuda="Con detalle: es lo que quien la apruebe va a leer."
                        :error="errores.actividad"
                    />

                    <label v-if="!editando" class="block text-sm">
                        <span class="mb-1 block">Evidencia (opcional)</span>
                        <input
                            type="file"
                            accept=".pdf,.jpg,.jpeg,.png"
                            class="w-full text-sm"
                            @change="archivo = ($event.target as HTMLInputElement).files?.[0] ?? null"
                        />
                        <span v-if="errores.evidencia" class="mt-1 block text-xs" :style="{ color: '#b91c1c' }">{{ errores.evidencia }}</span>
                    </label>

                    <p v-if="pideUbicacion" class="text-xs" :style="{ color: 'var(--color-suave)' }">
                        <template v-if="ubicacion">Se guardará dónde estás al registrarla.</template>
                        <template v-else>Tu escuela pide la ubicación al registrar. Puedes negarla: la jornada se guarda igual.</template>
                    </p>

                    <div class="flex items-center gap-3 pt-2">
                        <BotonPrincipal :procesando="procesando" :texto="editando ? 'Guardar' : 'Registrar'" icono="crear" />
                        <button type="button" class="rounded-lg border border-borde px-4 py-2 text-sm" @click="cerrar">Cancelar</button>
                    </div>
                </form>
            </template>
        </Modal>

        <Modal v-if="rechazando" etiqueta="Rechazar jornada" ancho="max-w-lg" @cerrar="rechazando = null">
            <template #default="{ cerrar }">
                <form class="space-y-4 p-6" @submit.prevent="rechazar">
                    <h2 class="text-base font-semibold">Rechazar la jornada del {{ rechazando.fecha }}</h2>

                    <CampoTextarea
                        v-model="motivo"
                        etiqueta="¿Por qué?"
                        requerido
                        :filas="3"
                        ayuda="Lo lee el alumno. Sin esto vuelve a capturar lo mismo."
                        :error="errores.motivo"
                    />

                    <div class="flex items-center gap-3 pt-2">
                        <BotonPrincipal :procesando="procesando" texto="Rechazar" icono="eliminar" :deshabilitado="motivo.trim().length < 5" />
                        <button type="button" class="rounded-lg border border-borde px-4 py-2 text-sm" @click="cerrar">Cancelar</button>
                    </div>
                </form>
            </template>
        </Modal>
    </div>
</template>
