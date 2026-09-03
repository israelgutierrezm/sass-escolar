<script setup lang="ts">
/**
 * El expediente de un alumno: dónde va, qué le falta y qué se puede hacer.
 *
 * ── Los botones salen del SERVIDOR ─────────────────────────────────────────
 * `expediente.siguientes` viene del enum de estados, no de una lista escrita
 * aquí. Con la tabla de transiciones repetida en la pantalla, un botón
 * ofrecería un movimiento que el servidor rehúsa — y el usuario lo pulsaría
 * hasta cansarse.
 *
 * ── Y los formularios se RE-SIEMBRAN al cambiar de expediente ──────────────
 * Inertia reutiliza el componente cuando la pantalla siguiente es la misma y
 * sólo intercambia las props, así que los `ref` sobreviven a la navegación: sin
 * esto, el panel de asignación quedaría abierto sobre otro alumno con los datos
 * del anterior. Es el defecto que se vio en la pantalla de facturas.
 */
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

import AppLayout from '@/Layouts/AppLayout.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoTextarea from '@/Components/CampoTextarea.vue';
import Modal from '@/Components/Modal.vue';
import PildoraEstado from '@/Components/PildoraEstado.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';

interface Siguiente {
    valor: string;
    texto: string;
    estado_texto: string;
    color: string;
    exige_motivo: boolean;
}

const props = defineProps<{
    expediente: Record<string, any>;
    catalogos: {
        organizaciones: { id: number; nombre: string }[];
        plazas: { id: number; organizacion_id: number; nombre: string }[];
        documentos: { id: number; nombre: string }[];
        estadosDocumento: { id: number; clave: string; nombre: string }[];
        modalidades: { id: number; nombre: string }[];
        requisitos: { valor: string; texto: string }[];
    };
    puedeRevisar: boolean;
    puedeExcepcionar: boolean;
}>();

const errores = ref<Record<string, string>>({});
const procesando = ref(false);
const moviendo = ref<Siguiente | null>(null);
const motivo = ref('');
const asignando = ref(false);
const excepcionando = ref(false);

const asignacion = ref<Record<string, unknown>>({});
const excepcion = ref<Record<string, unknown>>({ requisito: null, motivo: '' });

function sembrar(): void {
    errores.value = {};
    moviendo.value = null;
    motivo.value = '';
    asignando.value = false;
    excepcionando.value = false;
    asignacion.value = {
        organizacion_id: null,
        plaza_id: null,
        modalidad_id: null,
        fecha_inicio: '',
        fecha_fin_programada: '',
        responsable_interno_id: null,
    };
    excepcion.value = { requisito: null, motivo: '' };
}

sembrar();

watch(() => props.expediente.id, sembrar);

/* Las plazas se acotan a la organización elegida: una de otra se rehúsa. */
const plazasDeLaOrganizacion = computed(() => {
    const org = asignacion.value.organizacion_id as number | null;

    return props.catalogos.plazas.filter((p) => !org || p.organizacion_id === org);
});

/* Asignar tiene su propio formulario: necesita organización y fechas. */
const puedeAsignar = computed(
    () => props.puedeRevisar && props.expediente.siguientes.some((s: Siguiente) => s.valor === 'asignado'),
);

const otrosMovimientos = computed(
    () => props.expediente.siguientes.filter((s: Siguiente) => s.valor !== 'asignado'),
);

const requisitosLibres = computed(() => {
    const puestas = props.expediente.excepciones.map((e: any) => e.requisito);

    return props.catalogos.requisitos.filter((r) => !puestas.includes(r.valor));
});

function mover(): void {
    if (moviendo.value === null) return;

    procesando.value = true;

    router.post(
        `/procesos/expedientes/${props.expediente.id}/mover`,
        { estado: moviendo.value.valor, motivo: motivo.value || null },
        {
            preserveScroll: true,
            onError: (e) => (errores.value = e),
            onSuccess: () => sembrar(),
            onFinish: () => (procesando.value = false),
        },
    );
}

function asignar(): void {
    procesando.value = true;

    router.post(`/procesos/expedientes/${props.expediente.id}/asignar`, { ...asignacion.value }, {
        preserveScroll: true,
        onError: (e) => (errores.value = e),
        onSuccess: () => sembrar(),
        onFinish: () => (procesando.value = false),
    });
}

function autorizarExcepcion(): void {
    procesando.value = true;

    router.post(`/procesos/expedientes/${props.expediente.id}/excepciones`, { ...excepcion.value }, {
        preserveScroll: true,
        onError: (e) => (errores.value = e),
        onSuccess: () => sembrar(),
        onFinish: () => (procesando.value = false),
    });
}

function quitarExcepcion(id: number): void {
    router.delete(`/procesos/expedientes/${props.expediente.id}/excepciones/${id}`, { preserveScroll: true });
}

const MOMENTOS: Record<string, string> = {
    solicitud: 'Al solicitar',
    durante: 'Durante el proceso',
    liberacion: 'Para liberar',
};
</script>

<template>
    <Head :title="`${expediente.alumno ?? 'Expediente'} · ${expediente.tipo ?? ''}`" />

    <AppLayout :titulo="expediente.tipo ?? 'Expediente'">
        <Link href="/procesos/expedientes" class="mb-4 inline-block text-sm" :style="{ color: 'var(--color-suave)' }">
            ← Todas las solicitudes
        </Link>

        <section class="tarjeta mb-4 p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <h2 class="text-lg font-semibold">{{ expediente.alumno ?? '—' }}</h2>
                    <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                        {{ expediente.matricula }} · {{ expediente.programa ?? '—' }}
                        <span v-if="expediente.plan"> · {{ expediente.plan }}</span>
                        <span v-if="expediente.campus"> · {{ expediente.campus }}</span>
                    </p>
                    <p v-if="expediente.regla" class="mt-2 text-xs" :style="{ color: 'var(--color-suave)' }">
                        Se le aplicó <strong>{{ expediente.regla }}</strong>, versión {{ expediente.regla_version }}.
                        <span v-if="expediente.horas_requeridas"> Son {{ expediente.horas_requeridas }} horas.</span>
                    </p>
                </div>

                <PildoraEstado :texto="expediente.estado_texto" :color="expediente.estado_color" sin-capitalizar />
            </div>

            <!-- El motivo del último rechazo o suspensión, a la vista. Sin él,
                 quien abre el expediente no sabe por qué está donde está. -->
            <p
                v-if="expediente.motivo_estado"
                class="mt-3 rounded-lg px-4 py-3 text-sm"
                :style="{ backgroundColor: 'color-mix(in srgb, #b45309 10%, transparent)', color: '#b45309' }"
            >
                {{ expediente.motivo_estado }}
            </p>

            <div v-if="puedeRevisar && (otrosMovimientos.length || puedeAsignar)" class="mt-4 flex flex-wrap gap-2">
                <button
                    v-if="puedeAsignar"
                    type="button"
                    class="rounded-lg px-3 py-1.5 text-sm font-medium text-white"
                    :style="{ backgroundColor: '#0d9488' }"
                    @click="asignando = true"
                >Asignar organización</button>

                <button
                    v-for="s in otrosMovimientos"
                    :key="s.valor"
                    type="button"
                    class="rounded-lg border px-3 py-1.5 text-sm"
                    :style="{ borderColor: s.color, color: s.color }"
                    @click="moviendo = s"
                >{{ s.texto }}</button>
            </div>

            <p
                v-else-if="puedeRevisar"
                class="mt-4 text-sm"
                :style="{ color: 'var(--color-suave)' }"
            >
                Desde «{{ expediente.estado_texto }}» ya no se mueve a ninguna parte.
            </p>
        </section>

        <div class="grid gap-4 lg:grid-cols-2">
            <TarjetaSeccion titulo="La asignación">
                <dl v-if="expediente.organizacion" class="space-y-2 text-sm">
                    <div class="flex justify-between gap-3">
                        <dt :style="{ color: 'var(--color-suave)' }">Organización</dt>
                        <dd class="text-right font-medium">{{ expediente.organizacion }}</dd>
                    </div>
                    <div v-if="expediente.plaza" class="flex justify-between gap-3">
                        <dt :style="{ color: 'var(--color-suave)' }">Plaza</dt>
                        <dd class="text-right">{{ expediente.plaza }}</dd>
                    </div>
                    <div v-if="expediente.modalidad" class="flex justify-between gap-3">
                        <dt :style="{ color: 'var(--color-suave)' }">Modalidad</dt>
                        <dd class="text-right">{{ expediente.modalidad }}</dd>
                    </div>
                    <div v-if="expediente.supervisor" class="flex justify-between gap-3">
                        <dt :style="{ color: 'var(--color-suave)' }">Supervisor</dt>
                        <dd class="text-right">{{ expediente.supervisor }}</dd>
                    </div>
                    <div v-if="expediente.responsable" class="flex justify-between gap-3">
                        <dt :style="{ color: 'var(--color-suave)' }">Responsable interno</dt>
                        <dd class="text-right">{{ expediente.responsable }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt :style="{ color: 'var(--color-suave)' }">Periodo</dt>
                        <dd class="text-right tabular-nums">
                            {{ expediente.fecha_inicio }} — {{ expediente.fecha_fin_programada }}
                        </dd>
                    </div>
                </dl>

                <p v-else class="text-sm" :style="{ color: 'var(--color-suave)' }">
                    Todavía no tiene organización.
                </p>

                <!-- Lo que el alumno propuso cuando su organización no está en
                     el padrón: se enseña tal cual para poder decidir si se da de
                     alta, en vez de dejarla entrar sola. -->
                <div
                    v-if="expediente.organizacion_propuesta"
                    class="mt-4 rounded-lg px-4 py-3 text-sm"
                    :style="{ backgroundColor: 'color-mix(in srgb, var(--color-suave) 8%, transparent)' }"
                >
                    <p class="font-medium">El alumno propuso una organización</p>
                    <p class="mt-1 text-xs" :style="{ color: 'var(--color-suave)' }">
                        {{ Object.entries(expediente.organizacion_propuesta).map(([k, v]) => `${k}: ${v}`).join(' · ') }}
                    </p>
                </div>
            </TarjetaSeccion>

            <TarjetaSeccion titulo="Documentos">
                <ul v-if="expediente.documentos.length" class="space-y-2">
                    <li
                        v-for="d in expediente.documentos"
                        :key="d.id"
                        class="flex flex-wrap items-center justify-between gap-2 border-b pb-2 text-sm last:border-0"
                        :style="{ borderColor: 'var(--color-borde)' }"
                    >
                        <div class="min-w-0">
                            <span class="font-medium">{{ d.nombre }}</span>
                            <span class="ml-2 text-xs" :style="{ color: 'var(--color-suave)' }">
                                {{ MOMENTOS[d.momento] ?? d.momento }}
                            </span>
                            <span v-if="d.observaciones" class="mt-0.5 block text-xs" :style="{ color: '#b45309' }">
                                {{ d.observaciones }}
                            </span>
                        </div>
                        <PildoraEstado
                            :texto="d.entregado ? (d.estado ?? 'Sin revisar') : 'Falta'"
                            :color="d.entregado ? (d.estado_clave === 'aceptado' ? '#16a34a' : '#0284c7') : '#b45309'"
                            sin-capitalizar
                        />
                    </li>
                </ul>

                <p v-else class="text-sm" :style="{ color: 'var(--color-suave)' }">
                    Todavía no ha subido ninguno.
                </p>
            </TarjetaSeccion>
        </div>

        <TarjetaSeccion titulo="Excepciones autorizadas" class="mt-4">
            <template #insignia>
                <button
                    v-if="puedeExcepcionar && requisitosLibres.length"
                    type="button"
                    class="rounded-lg border border-borde px-3 py-1 text-xs"
                    @click="excepcionando = true"
                >Autorizar una</button>
            </template>

            <ul v-if="expediente.excepciones.length" class="space-y-2">
                <li
                    v-for="x in expediente.excepciones"
                    :key="x.id"
                    class="flex flex-wrap items-start justify-between gap-2 border-b pb-2 text-sm last:border-0"
                    :style="{ borderColor: 'var(--color-borde)' }"
                >
                    <div class="min-w-0">
                        <span class="font-medium">{{ x.etiqueta }}</span>
                        <span class="mt-0.5 block text-xs" :style="{ color: 'var(--color-suave)' }">
                            {{ x.motivo }} — {{ x.autorizada_por ?? 'alguien' }}, {{ x.autorizada_en }}
                        </span>
                    </div>
                    <button
                        v-if="puedeExcepcionar"
                        type="button"
                        class="text-xs"
                        :style="{ color: '#b91c1c' }"
                        @click="quitarExcepcion(x.id)"
                    >Retirar</button>
                </li>
            </ul>

            <p v-else class="text-sm" :style="{ color: 'var(--color-suave)' }">
                Ninguna: este expediente cumple lo que su regla pide, sin perdones.
            </p>
        </TarjetaSeccion>

        <TarjetaSeccion titulo="Qué le ha pasado" class="mt-4">
            <ol class="space-y-3">
                <li v-for="(t, i) in expediente.historia" :key="i" class="flex gap-3 text-sm">
                    <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full" :style="{ backgroundColor: t.color }" />
                    <div class="min-w-0">
                        <p>
                            <span v-if="t.origen">{{ t.origen }} → </span><strong>{{ t.destino }}</strong>
                        </p>
                        <p class="text-xs" :style="{ color: 'var(--color-suave)' }">
                            {{ t.momento }}<span v-if="t.quien"> · {{ t.quien }}</span>
                        </p>
                        <p v-if="t.motivo" class="mt-0.5 text-xs" :style="{ color: 'var(--color-suave)' }">
                            «{{ t.motivo }}»
                        </p>
                    </div>
                </li>
            </ol>
        </TarjetaSeccion>

        <Modal v-if="moviendo" :etiqueta="moviendo.texto" ancho="max-w-lg" @cerrar="moviendo = null">
            <template #default="{ cerrar }">
                <form class="space-y-4 p-6" @submit.prevent="mover">
                    <h2 class="text-base font-semibold">{{ moviendo.texto }}</h2>

                    <CampoTextarea
                        v-if="moviendo.exige_motivo"
                        v-model="motivo"
                        etiqueta="Motivo"
                        requerido
                        :filas="3"
                        ayuda="Lo lee el alumno. Sin él no sabe qué corregir, y dentro de un año nadie puede explicar por qué se hizo."
                        :error="errores.motivo"
                    />

                    <p v-else class="text-sm" :style="{ color: 'var(--color-suave)' }">
                        ¿Seguro? El expediente pasa a «{{ moviendo.estado_texto }}» y queda anotado con tu nombre.
                    </p>

                    <div class="flex items-center gap-3 pt-2">
                        <BotonPrincipal
                            :procesando="procesando"
                            :texto="moviendo.texto"
                            icono="crear"
                            :deshabilitado="moviendo.exige_motivo && motivo.trim().length < 5"
                        />
                        <button type="button" class="rounded-lg border border-borde px-4 py-2 text-sm" @click="cerrar">Cancelar</button>
                    </div>
                </form>
            </template>
        </Modal>

        <Modal v-if="asignando" etiqueta="Asignar organización" ancho="max-w-2xl" @cerrar="asignando = false">
            <template #default="{ cerrar }">
                <form class="space-y-4 p-6" @submit.prevent="asignar">
                    <h2 class="text-base font-semibold">Asignar organización</h2>

                    <p class="rounded-lg px-4 py-3 text-xs" :style="{ backgroundColor: 'color-mix(in srgb, var(--color-suave) 8%, transparent)', color: 'var(--color-suave)' }">
                        Sólo salen las organizaciones que reciben alumnos y que alcanzan a este programa y campus.
                        <span v-if="expediente.exige_convenio">Su regla exige <strong>convenio vigente</strong>.</span>
                        <span v-if="expediente.plazo_maximo_dias">El tope de la regla es de {{ expediente.plazo_maximo_dias }} días.</span>
                    </p>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <CampoSelect
                            v-model="asignacion.organizacion_id"
                            etiqueta="Organización"
                            requerido
                            :opciones="catalogos.organizaciones.map((o) => ({ valor: o.id, texto: o.nombre }))"
                            vacio="Elige la organización…"
                            :error="errores.organizacion_id"
                            @update:model-value="asignacion.plaza_id = null"
                        />
                        <CampoSelect
                            v-model="asignacion.plaza_id"
                            etiqueta="Plaza"
                            :opciones="plazasDeLaOrganizacion.map((p) => ({ valor: p.id, texto: p.nombre }))"
                            vacio="Sin plaza publicada"
                            ayuda="Sólo las que tienen lugar y admiten a su programa."
                            :error="errores.plaza_id"
                        />
                        <CampoSelect
                            v-model="asignacion.modalidad_id"
                            etiqueta="Modalidad"
                            :opciones="catalogos.modalidades.map((m) => ({ valor: m.id, texto: m.nombre }))"
                            vacio="La de la plaza"
                            ayuda="En blanco se hereda de la plaza elegida: es lo que ella ya declara."
                            :error="errores.modalidad_id"
                        />
                        <CampoTexto v-model="asignacion.fecha_inicio" etiqueta="Empieza" tipo="date" requerido :error="errores.fecha_inicio" />
                        <CampoTexto v-model="asignacion.fecha_fin_programada" etiqueta="Debe terminar" tipo="date" requerido :error="errores.fecha_fin_programada" />
                    </div>

                    <div class="flex items-center gap-3 pt-2">
                        <BotonPrincipal :procesando="procesando" texto="Asignar" icono="crear" />
                        <button type="button" class="rounded-lg border border-borde px-4 py-2 text-sm" @click="cerrar">Cancelar</button>
                    </div>
                </form>
            </template>
        </Modal>

        <Modal v-if="excepcionando" etiqueta="Autorizar excepción" ancho="max-w-lg" @cerrar="excepcionando = false">
            <template #default="{ cerrar }">
                <form class="space-y-4 p-6" @submit.prevent="autorizarExcepcion">
                    <h2 class="text-base font-semibold">Autorizar una excepción</h2>

                    <p class="rounded-lg px-4 py-3 text-xs" :style="{ backgroundColor: 'color-mix(in srgb, #b45309 10%, transparent)', color: '#b45309' }">
                        Esto le perdona a <strong>este alumno</strong> un requisito que su programa exige.
                        Queda escrito con tu nombre y tu motivo, y el expediente lo enseña.
                    </p>

                    <CampoSelect
                        v-model="excepcion.requisito"
                        etiqueta="Requisito"
                        requerido
                        :opciones="requisitosLibres.map((r) => ({ valor: r.valor, texto: r.texto }))"
                        vacio="Elige cuál…"
                        :error="errores.requisito"
                    />

                    <CampoTextarea
                        v-model="excepcion.motivo"
                        etiqueta="Motivo"
                        requerido
                        :filas="3"
                        ayuda="Por qué se le perdona. Es lo único que quedará para explicarlo."
                        :error="errores.motivo"
                    />

                    <div class="flex items-center gap-3 pt-2">
                        <BotonPrincipal
                            :procesando="procesando"
                            texto="Autorizar"
                            icono="crear"
                            :deshabilitado="!excepcion.requisito || String(excepcion.motivo).trim().length < 10"
                        />
                        <button type="button" class="rounded-lg border border-borde px-4 py-2 text-sm" @click="cerrar">Cancelar</button>
                    </div>
                </form>
            </template>
        </Modal>
    </AppLayout>
</template>
