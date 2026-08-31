<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import BotonAccion from '@/Components/BotonAccion.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoTextarea from '@/Components/CampoTextarea.vue';
import Modal from '@/Components/Modal.vue';
import PildoraEstado from '@/Components/PildoraEstado.vue';
import { hoyLocal } from '@/utils/fechas';

/**
 * La trayectoria administrativa de UNA matrícula, como línea de tiempo.
 *
 * ── Es de la matrícula, no de la persona ───────────────────────────────────
 * Quien estudia dos programas tiene dos trayectorias. La cabecera dice de cuál
 * se está hablando aunque haya una sola: sin eso, en el expediente de quien
 * tiene dos, esta lista se lee como «todo lo que le ha pasado», que es falso.
 *
 * ── Se piden al ABRIR la pestaña ───────────────────────────────────────────
 * No viajan con el expediente. La mayoría de las visitas al expediente no son
 * para mirar movimientos, y cargarlos siempre encarecería la pantalla para
 * todos por una consulta que casi nadie abre.
 *
 * ── Los filtros son de PANTALLA, no de servidor ────────────────────────────
 * Una trayectoria son decenas de renglones, no miles: filtrar aquí responde al
 * instante y sin una petición por tecla. El día que una matrícula tenga cientos
 * —no puede: son hechos administrativos, no bitácora— esto se mueve al Ejecutor.
 */
const props = defineProps<{
    /** La matrícula en foco. */
    matricula: { id: number; matricula: string | null; programa_academico: string | null; plan: string | null; campus: string | null; situacion: string | null; estatus: string | null; generacion: string | null; fecha_ingreso: string | null; periodo_actual: number | null };
    /** Las OTRAS trayectorias de la misma persona, para poder saltar a ellas. */
    otrasTrayectorias: { id: number; matricula: string; programa_academico: string | null }[];
    unidadPeriodo: string;
    puedeRegistrar: boolean;
    puedeCorregir: boolean;
}>();

type Cambio = { que: string; antes: string | null; despues: string | null };

type Movimiento = {
    id: number;
    tipo: string | null;
    tipo_clave: string | null;
    color: string;
    fecha_efectiva: string | null;
    registrado_en: string | null;
    registro: string | null;
    origen: string;
    automatico: boolean;
    ciclo: string | null;
    motivo: string | null;
    observaciones: string | null;
    corrige_movimiento_id: number | null;
    cambios: Cambio[];
};

type Tipo = {
    id: number; clave: string; nombre: string; descripcion: string | null; color: string;
    pide_ciclo: boolean; pide_grupos: boolean; pide_situacion: boolean;
    pide_oferta: boolean; pide_periodo: boolean; pide_motivo: boolean;
};

const movimientos = ref<Movimiento[]>([]);
const cargando = ref(true);
const fallo = ref(false);

const base = computed(() => `/escolar/matriculas/${props.matricula.id}/movimientos`);

async function traer(): Promise<void> {
    cargando.value = true;
    fallo.value = false;
    try {
        const respuesta = await fetch(base.value, { headers: { Accept: 'application/json' } });
        if (!respuesta.ok) throw new Error(String(respuesta.status));
        movimientos.value = (await respuesta.json()).movimientos;
    } catch {
        // Sin datos inventados: se dice que no se pudieron traer y se ofrece
        // reintentar. Una lista vacía se lee como «no ha pasado nada».
        fallo.value = true;
    } finally {
        cargando.value = false;
    }
}

onMounted(traer);

// ── Filtros (§11) ──────────────────────────────────────────────────────────
const filtroTipo = ref<string | null>(null);
const filtroCiclo = ref<string | null>(null);
const desde = ref<string | null>(null);
const hasta = ref<string | null>(null);

const tiposPresentes = computed(() => {
    const vistos = new Map<string, string>();
    movimientos.value.forEach((m) => {
        if (m.tipo_clave && m.tipo) vistos.set(m.tipo_clave, m.tipo);
    });
    return [...vistos].map(([valor, texto]) => ({ valor, texto }));
});

const ciclosPresentes = computed(() => {
    const vistos = new Set<string>();
    movimientos.value.forEach((m) => { if (m.ciclo) vistos.add(m.ciclo); });
    return [...vistos].map((c) => ({ valor: c, texto: c }));
});

const visibles = computed(() =>
    movimientos.value.filter((m) => {
        if (filtroTipo.value && m.tipo_clave !== filtroTipo.value) return false;
        if (filtroCiclo.value && m.ciclo !== filtroCiclo.value) return false;
        if (desde.value && (m.fecha_efectiva ?? '') < desde.value) return false;
        if (hasta.value && (m.fecha_efectiva ?? '') > hasta.value) return false;
        return true;
    }),
);

const hayFiltro = computed(() => Boolean(filtroTipo.value || filtroCiclo.value || desde.value || hasta.value));

function limpiar(): void {
    filtroTipo.value = null;
    filtroCiclo.value = null;
    desde.value = null;
    hasta.value = null;
}

// ── Colores de la línea ────────────────────────────────────────────────────
const TONOS: Record<string, string> = {
    verde: '#16a34a',
    azul: 'var(--color-acento)',
    naranja: '#d97706',
    rojo: '#dc2626',
    gris: 'var(--color-suave)',
};
const tono = (m: Movimiento) => TONOS[m.color] ?? TONOS.gris;

const ORIGENES: Record<string, string> = {
    manual: 'Capturado a mano',
    conversion_aspirante: 'Conversión del aspirante',
    matriculacion: 'Matriculación',
    baja: 'Baja',
    reingreso: 'Reingreso',
    titulacion: 'Titulación',
};

/** Los que ya fueron enmendados, para marcarlos en la línea. */
const corregidos = computed(() => new Set(movimientos.value.map((m) => m.corrige_movimiento_id).filter(Boolean) as number[]));

/**
 * Cómo se nombra el movimiento que una corrección enmienda.
 *
 * «Corrige el movimiento #310» no le dice nada a quien lee: un id es de la
 * base, no del expediente. Se resuelve contra la lista que ya está cargada,
 * sin pedirle nada más al servidor.
 */
function aQuienCorrige(m: Movimiento): string | null {
    if (m.corrige_movimiento_id === null) return null;

    const enmendado = movimientos.value.find((otro) => otro.id === m.corrige_movimiento_id);

    // Puede no estar a la vista: un filtro no lo esconde —la lista completa
    // sigue en memoria— pero un movimiento borrado de otra trayectoria sí.
    return enmendado
        ? `Corrige: ${enmendado.tipo ?? 'movimiento'} del ${enmendado.fecha_efectiva}`
        : 'Corrige un movimiento anterior';
}

// ── Registro manual (§10) ──────────────────────────────────────────────────
const abierto = ref(false);
const catalogos = ref<{ tipos: Tipo[]; ciclos: { id: number; clave: string; nombre: string }[]; situaciones: { id: number; nombre: string }[]; grupos: { id: number; clave: string; nombre: string }[] } | null>(null);

const form = useForm<{
    tipo_id: number | null; fecha_efectiva: string; ciclo_id: number | null;
    situacion_nueva_id: number | null; grupo_anterior_id: number | null; grupo_nuevo_id: number | null;
    periodo_nuevo: number | null; motivo: string | null; observaciones: string | null;
    corrige_movimiento_id: number | null;
}>({
    tipo_id: null, fecha_efectiva: hoyLocal(), ciclo_id: null,
    situacion_nueva_id: null, grupo_anterior_id: null, grupo_nuevo_id: null,
    periodo_nuevo: null, motivo: null, observaciones: null, corrige_movimiento_id: null,
});

/**
 * El tipo elegido decide QUÉ campos se dibujan.
 *
 * Es lo que evita el formulario gigante con veinte campos de los que sólo
 * aplican tres: quien captura una baja temporal no tiene por qué ver un
 * selector de grupo, y verlo invita a llenarlo.
 */
const tipoElegido = computed(() => catalogos.value?.tipos.find((t) => t.id === form.tipo_id) ?? null);

async function abrir(corrige: Movimiento | null = null): Promise<void> {
    if (catalogos.value === null) {
        const respuesta = await fetch(`${base.value}/catalogos`, { headers: { Accept: 'application/json' } });
        if (!respuesta.ok) return;
        catalogos.value = await respuesta.json();
    }

    /*
     * Se vacía CAMPO POR CAMPO y no con `form.reset()`.
     *
     * `reset()` vuelve a los DEFAULTS, y abajo se llama a `form.defaults()`
     * para que cerrar sin teclear nada no pregunte si se pierden los cambios.
     * Con las dos cosas, la segunda captura heredaba lo tecleado en la primera:
     * la corrección salía con las observaciones de la baja que venía a
     * enmendar, y sonaba a que alguien las había escrito ahí.
     */
    form.clearErrors();
    form.tipo_id = null;
    form.fecha_efectiva = hoyLocal();
    form.ciclo_id = null;
    form.situacion_nueva_id = null;
    form.grupo_anterior_id = null;
    form.grupo_nuevo_id = null;
    form.periodo_nuevo = null;
    form.motivo = null;
    form.observaciones = null;
    form.corrige_movimiento_id = null;

    if (corrige) {
        form.corrige_movimiento_id = corrige.id;
        const correccion = catalogos.value?.tipos.find((t) => t.clave === 'correccion');
        if (correccion) form.tipo_id = correccion.id;
    }

    // El punto de partida, para que cerrar sin teclear nada no pregunte.
    form.defaults();
    abierto.value = true;
}

// Cambiar de tipo limpia lo que el tipo nuevo no pide: si no, un dato tecleado
// para otro tipo viaja escondido y el servidor lo descarta sin que se vea.
watch(() => form.tipo_id, () => {
    form.ciclo_id = null;
    form.situacion_nueva_id = null;
    form.grupo_anterior_id = null;
    form.grupo_nuevo_id = null;
    form.periodo_nuevo = null;
});

function guardar(): void {
    form.post(base.value, {
        preserveScroll: true,
        onSuccess: () => {
            abierto.value = false;
            // La lista se vuelve a pedir: la escribe el servidor, no el navegador.
            traer();
        },
    });
}

const opciones = {
    tipos: computed(() => (catalogos.value?.tipos ?? []).map((t) => ({ valor: t.id, texto: t.nombre }))),
    ciclos: computed(() => (catalogos.value?.ciclos ?? []).map((c) => ({ valor: c.id, texto: c.clave || c.nombre }))),
    situaciones: computed(() => (catalogos.value?.situaciones ?? []).map((s) => ({ valor: s.id, texto: s.nombre }))),
    grupos: computed(() => (catalogos.value?.grupos ?? []).map((g) => ({ valor: g.id, texto: `${g.clave ?? ''} ${g.nombre ?? ''}`.trim() }))),
};

function irA(id: number): void {
    router.get(`/escolar/alumnos/${id}`);
}
</script>

<template>
    <section class="space-y-4">
        <!--
            §12 · Resumen de la trayectoria en foco.

            Va arriba y no al final porque contesta la primera pregunta que uno
            se hace al abrir la pestaña: «¿de qué matrícula estoy viendo la
            historia?». Con dos programas, sin esto la lista es ambigua.
        -->
        <div class="tarjeta p-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="text-sm font-semibold text-contenido">
                        {{ matricula.programa_academico ?? 'Programa académico sin nombre' }}
                    </p>
                    <p class="mt-0.5 text-xs text-suave">
                        Matrícula {{ matricula.matricula ?? '—' }}
                        <template v-if="matricula.plan"> · {{ matricula.plan }}</template>
                        <template v-if="matricula.campus"> · {{ matricula.campus }}</template>
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <PildoraEstado :texto="matricula.situacion" />
                    <BotonAccion v-if="puedeRegistrar" variante="nuevo" texto="Registrar movimiento" @click="abrir()" />
                </div>
            </div>

            <dl class="mt-4 grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                <div>
                    <dt class="text-xs text-suave">Ingreso</dt>
                    <dd class="text-contenido">{{ matricula.fecha_ingreso ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-suave">Generación</dt>
                    <dd class="text-contenido">{{ matricula.generacion ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-suave">{{ unidadPeriodo }} actual</dt>
                    <dd class="text-contenido">{{ matricula.periodo_actual ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-suave">Movimientos</dt>
                    <dd class="text-contenido">{{ movimientos.length }}</dd>
                </div>
            </dl>

            <!--
                Las otras trayectorias de la MISMA persona. No se mezclan aquí:
                cada una tiene su historia y juntarlas contaría dos como una.
            -->
            <div v-if="otrasTrayectorias.length" class="mt-4 border-t border-borde pt-3">
                <p class="text-xs text-suave">
                    Esta persona tiene {{ otrasTrayectorias.length + 1 }} trayectorias. Cada una lleva sus propios movimientos:
                </p>
                <div class="mt-2 flex flex-wrap gap-2">
                    <button
                        v-for="otra in otrasTrayectorias"
                        :key="otra.id"
                        type="button"
                        class="rounded-full border border-borde px-3 py-1 text-xs text-contenido hover:bg-superficie-2"
                        @click="irA(otra.id)"
                    >
                        {{ otra.matricula }} · {{ otra.programa_academico ?? 'Sin programa' }}
                    </button>
                </div>
            </div>
        </div>

        <!-- §11 · Filtros -->
        <div v-if="movimientos.length" class="tarjeta grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-5">
            <CampoSelect v-model="filtroTipo" etiqueta="Tipo" :opciones="tiposPresentes" vacio="Todos" />
            <CampoSelect v-model="filtroCiclo" etiqueta="Ciclo" :opciones="ciclosPresentes" vacio="Todos" />
            <CampoTexto v-model="desde" etiqueta="Desde" tipo="date" />
            <CampoTexto v-model="hasta" etiqueta="Hasta" tipo="date" />
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

        <p v-if="cargando" class="text-sm text-suave">Cargando la trayectoria…</p>

        <div v-else-if="fallo" class="tarjeta p-6 text-center">
            <p class="text-sm text-contenido">No se pudieron traer los movimientos.</p>
            <button type="button" class="mt-2 text-sm underline" :style="{ color: 'var(--color-acento)' }" @click="traer">
                Reintentar
            </button>
        </div>

        <div v-else-if="!movimientos.length" class="tarjeta p-6 text-center text-sm text-suave">
            Esta trayectoria todavía no tiene movimientos registrados.
        </div>

        <div v-else-if="!visibles.length" class="tarjeta p-6 text-center text-sm text-suave">
            Ningún movimiento coincide con esos filtros.
        </div>

        <!--
            §11 · Línea de tiempo vertical. La fecha EFECTIVA manda el orden;
            cuándo se capturó se dice aparte, en la letra chica de la auditoría.
        -->
        <ol v-else class="relative space-y-4 border-l border-borde pl-6">
            <li v-for="m in visibles" :key="m.id" class="relative">
                <span
                    class="absolute -left-[1.9rem] mt-1.5 inline-block h-3 w-3 rounded-full ring-4 ring-superficie"
                    :style="{ backgroundColor: tono(m) }"
                />

                <div class="tarjeta p-4">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-sm font-semibold" :style="{ color: tono(m) }">{{ m.tipo ?? 'Movimiento' }}</span>
                            <span v-if="m.ciclo" class="rounded-full bg-superficie-2 px-2 py-0.5 text-xs text-suave">{{ m.ciclo }}</span>
                            <span v-if="m.automatico" class="rounded-full bg-superficie-2 px-2 py-0.5 text-xs text-suave" title="Lo emitió un proceso del sistema">⚙ Automático</span>
                            <span v-if="corregidos.has(m.id)" class="rounded-full px-2 py-0.5 text-xs" :style="{ color: '#d97706', backgroundColor: 'color-mix(in srgb, #d97706 14%, transparent)' }">
                                Corregido después
                            </span>
                            <span v-if="m.corrige_movimiento_id" class="rounded-full bg-superficie-2 px-2 py-0.5 text-xs text-suave">
                                {{ aQuienCorrige(m) }}
                            </span>
                        </div>
                        <span class="text-sm text-suave">{{ m.fecha_efectiva }}</span>
                    </div>

                    <!--
                        §7 · Los cambios, con NOMBRE. «Activo → Baja temporal»,
                        no «situacion_id 1 → 4». Y sólo se pintan los que de
                        verdad cambiaron: un alta no tiene situación anterior y
                        un «— → Activo» se lee como un dato que falta.
                    -->
                    <ul v-if="m.cambios.length" class="mt-3 space-y-1">
                        <li v-for="c in m.cambios" :key="c.que" class="flex flex-wrap items-center gap-2 text-sm">
                            <span class="text-xs text-suave">{{ c.que }}:</span>
                            <span class="text-contenido">{{ c.antes ?? '—' }}</span>
                            <span class="text-suave">→</span>
                            <span class="font-medium text-contenido">{{ c.despues ?? '—' }}</span>
                        </li>
                    </ul>

                    <p v-if="m.motivo" class="mt-3 text-sm text-contenido"><span class="text-xs text-suave">Motivo:</span> {{ m.motivo }}</p>
                    <p v-if="m.observaciones" class="mt-1 whitespace-pre-line text-sm text-suave">{{ m.observaciones }}</p>

                    <!--
                        §14 · La auditoría, siempre visible: quién lo registró,
                        cuándo lo capturó y de dónde salió. Sin esto no se puede
                        distinguir un movimiento del sistema de uno tecleado.
                    -->
                    <p class="mt-3 border-t border-borde pt-2 text-xs text-suave">
                        {{ ORIGENES[m.origen] ?? m.origen }}
                        <template v-if="m.registro"> · {{ m.registro }}</template>
                        <template v-if="m.registrado_en"> · registrado el {{ m.registrado_en }}</template>
                    </p>

                    <!--
                        §8 · No hay editar ni borrar. Lo único que se ofrece es
                        registrar la corrección, y sólo a quien tiene ese permiso.
                    -->
                    <div v-if="puedeCorregir && !corregidos.has(m.id)" class="mt-2">
                        <button type="button" class="text-xs underline" :style="{ color: 'var(--color-acento)' }" @click="abrir(m)">
                            Corregir este movimiento
                        </button>
                    </div>
                </div>
            </li>
        </ol>

        <!-- §10 · Alta manual, con los campos que el TIPO pide -->
        <Modal
            v-if="abierto"
            :etiqueta="form.corrige_movimiento_id ? 'Corregir un movimiento' : 'Registrar movimiento'"
            ancho="max-w-2xl"
            :formulario="form"
            @cerrar="abierto = false"
        >
            <form class="flex max-h-[85vh] flex-col" @submit.prevent="guardar">
                <header class="border-b border-borde px-6 py-4">
                    <h2 class="text-base font-semibold text-contenido">
                        {{ form.corrige_movimiento_id ? 'Corregir un movimiento' : 'Registrar movimiento' }}
                    </h2>
                    <p class="mt-0.5 text-xs text-suave">
                        {{ matricula.matricula ?? 'Sin matrícula' }} · {{ matricula.programa_academico ?? 'Sin programa' }}
                    </p>
                </header>

                <!-- El cuerpo desplaza: con «cambio de grupo» el formulario
                     crece y en una pantalla baja el botón quedaba fuera. -->
                <div class="flex-1 space-y-4 overflow-y-auto px-6 py-4">
                <p v-if="form.corrige_movimiento_id" class="rounded-lg bg-superficie-2 p-3 text-sm text-suave">
                    Se registrará un movimiento nuevo que enmienda al #{{ form.corrige_movimiento_id }}.
                    El original no se modifica ni se borra: los dos se conservan.
                </p>

                <div class="grid gap-4 sm:grid-cols-2">
                    <CampoSelect
                        v-model="form.tipo_id"
                        etiqueta="Tipo de movimiento"
                        requerido
                        vacio="Elige un tipo"
                        :opciones="opciones.tipos.value"
                        :error="form.errors.tipo_id"
                        :ayuda="tipoElegido?.descripcion ?? undefined"
                    />
                    <CampoTexto
                        v-model="form.fecha_efectiva"
                        etiqueta="Fecha en que ocurrió"
                        tipo="date"
                        requerido
                        :error="form.errors.fecha_efectiva"
                        ayuda="No es la fecha de captura: ésa la anota el sistema."
                    />

                    <CampoSelect
                        v-if="tipoElegido?.pide_ciclo"
                        v-model="form.ciclo_id"
                        etiqueta="Ciclo"
                        vacio="Sin ciclo"
                        :opciones="opciones.ciclos.value"
                        :error="form.errors.ciclo_id"
                    />

                    <CampoSelect
                        v-if="tipoElegido?.pide_situacion"
                        v-model="form.situacion_nueva_id"
                        etiqueta="Situación a la que pasa"
                        vacio="Sin cambio de situación"
                        :opciones="opciones.situaciones.value"
                        :error="form.errors.situacion_nueva_id"
                        ayuda="La situación de la que viene la lee el sistema de la matrícula."
                    />

                    <CampoSelect
                        v-if="tipoElegido?.pide_grupos"
                        v-model="form.grupo_anterior_id"
                        etiqueta="Grupo del que sale"
                        vacio="Sin grupo previo"
                        :opciones="opciones.grupos.value"
                        :error="form.errors.grupo_anterior_id"
                    />
                    <CampoSelect
                        v-if="tipoElegido?.pide_grupos"
                        v-model="form.grupo_nuevo_id"
                        etiqueta="Grupo al que entra"
                        vacio="Sin grupo"
                        :opciones="opciones.grupos.value"
                        :error="form.errors.grupo_nuevo_id"
                    />

                    <CampoTexto
                        v-if="tipoElegido?.pide_periodo"
                        v-model="form.periodo_nuevo"
                        :etiqueta="`${unidadPeriodo} al que pasa`"
                        tipo="number"
                        paso="1"
                        :error="form.errors.periodo_nuevo"
                    />

                    <CampoTexto
                        v-if="tipoElegido?.pide_motivo"
                        v-model="form.motivo"
                        etiqueta="Motivo"
                        :maximo="255"
                        :error="form.errors.motivo"
                        class="sm:col-span-2"
                    />
                </div>

                <CampoTextarea
                    v-model="form.observaciones"
                    etiqueta="Observaciones"
                    :filas="3"
                    :maximo="2000"
                    :error="form.errors.observaciones"
                    ayuda="Queda para siempre: un movimiento no se edita ni se borra."
                />
                </div>

                <div class="flex justify-end gap-2 border-t border-borde px-6 py-4">
                    <button type="button" class="text-sm text-suave" @click="abierto = false">Cancelar</button>
                    <BotonPrincipal
                        texto="Registrar"
                        cargando="Registrando…"
                        icono="crear"
                        :procesando="form.processing"
                        :deshabilitado="!form.tipo_id"
                    />
                </div>
            </form>
        </Modal>
    </section>
</template>
