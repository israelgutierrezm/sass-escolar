<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import { ICONOS } from '@/iconos';
import { toast } from 'vue-sonner';

/**
 * Con qué se arma la matrícula de esta escuela.
 *
 * Ninguna escuela numera igual que la de al lado, así que en vez de ofrecer una
 * lista de formatos se ofrecen las PIEZAS: una plantilla con tokens, y por
 * separado las dos decisiones del consecutivo —sobre qué se cuenta y si se
 * reinicia cada año—. Con eso salen los tres casos que se pidieron y los que
 * falten.
 *
 * La vista previa se calcula aquí mismo mientras se teclea, sin ir al servidor
 * y sin gastar folio: es la única forma de que alguien entienda qué está
 * configurando antes de guardarlo.
 */
interface Regla {
    id: number;
    nombre: string | null;
    ambito: string;
    ambito_id: number | null;
    alcance: string;
    plantilla: string;
    consecutivo_por: string | null;
    consecutivo_anual: boolean;
    activo: boolean;
    ejemplo: string | null;
}

const props = defineProps<{
    reglas: Regla[];
    contadores: { clave: string; valor: number; descripcion: string }[];
    carreras: { id: number; clave: string; nombre: string }[];
    planes: { id: number; clave: string; nombre: string }[];
    tokens: Record<string, string>;
    consecutivoPor: string[];
    puedeEditar: boolean;
}>();

const page = usePage();

// ── Alta y edición ─────────────────────────────────────────────────────────

const editando = ref<number | null>(null);
const abierto = ref(false);

const form = useForm({
    nombre: '',
    ambito: 'global',
    ambito_id: null as number | null,
    plantilla: '{AAAA}{CARRERA}{####}',
    consecutivo_por: null as string | null,
    consecutivo_anual: true,
    activo: true,
});

function abrirAlta(): void {
    editando.value = null;
    form.reset();
    form.clearErrors();
    abierto.value = true;
}

function editar(regla: Regla): void {
    editando.value = regla.id;
    form.clearErrors();
    form.nombre = regla.nombre ?? '';
    form.ambito = regla.ambito;
    form.ambito_id = regla.ambito_id;
    form.plantilla = regla.plantilla;
    form.consecutivo_por = regla.consecutivo_por;
    form.consecutivo_anual = regla.consecutivo_anual;
    form.activo = regla.activo;
    abierto.value = true;
}

function guardar(): void {
    const opciones = {
        preserveScroll: true,
        onSuccess: () => {
            abierto.value = false;
            editando.value = null;
            form.reset();
        },
    };

    if (editando.value === null) {
        form.post('/admisiones/reglas-matricula', opciones);
    } else {
        form.put(`/admisiones/reglas-matricula/${editando.value}`, opciones);
    }
}

function eliminar(regla: Regla): void {
    if (!confirm(`¿Eliminar la regla de «${regla.alcance}»? Su contador se conserva.`)) {
        return;
    }

    router.delete(`/admisiones/reglas-matricula/${regla.id}`, { preserveScroll: true });
}

// ── Vista previa ───────────────────────────────────────────────────────────

/**
 * Ejemplos con los que enseñar el resultado.
 *
 * Valores de mentira pero con la forma de los de verdad: lo que importa es que
 * se vea DÓNDE cae cada pieza, no que la clave sea la real. Se rellena con la
 * primera carrera y el primer plan que existan para que se parezca a lo suyo.
 */
const muestra = computed(() => ({
    '{AAAA}': String(new Date().getFullYear()),
    '{AA}': String(new Date().getFullYear()).slice(-2),
    '{NIVEL}': 'LIC',
    '{CARRERA}': props.carreras[0]?.clave ?? 'ADM',
    '{PLAN}': props.planes[0]?.clave ?? '2022',
    '{CAMPUS}': 'CEN',
}));

/** La plantilla resuelta, con el consecutivo que se quiera probar. */
function renderizar(plantilla: string, consecutivo: number): string {
    let salida = plantilla;

    for (const [token, valor] of Object.entries(muestra.value)) {
        salida = salida.split(token).join(valor);
    }

    return salida.replace(/\{(#+)\}/g, (_, gatos: string) => String(consecutivo).padStart(gatos.length, '0'));
}

const vistaPrevia = computed(() => renderizar(form.plantilla, 1));

/** Tres seguidas: se entiende de un vistazo qué parte es la que avanza. */
const secuencia = computed(() => [1, 2, 3].map((n) => renderizar(form.plantilla, n)));

const faltaConsecutivo = computed(() => !/\{#+\}/.test(form.plantilla));

function agregarToken(token: string): void {
    form.plantilla += token;
}

// ── Contadores ─────────────────────────────────────────────────────────────

const ajustando = ref<string | null>(null);
const ajuste = useForm({ clave: '', valor: 0 });

function abrirAjuste(contador: { clave: string; valor: number }): void {
    ajustando.value = contador.clave;
    ajuste.clave = contador.clave;
    ajuste.valor = contador.valor;
    ajuste.clearErrors();
}

function guardarAjuste(): void {
    ajuste.post('/admisiones/reglas-matricula/contadores', {
        preserveScroll: true,
        onSuccess: () => {
            ajustando.value = null;
        },
    });
}

// ── Textos ─────────────────────────────────────────────────────────────────

const ETIQUETA_POR: Record<string, string> = {
    campus: 'por campus',
    nivel: 'por nivel de estudios',
    carrera: 'por carrera',
    plan: 'por plan de estudios',
};

function describirConsecutivo(regla: Pick<Regla, 'consecutivo_por' | 'consecutivo_anual'>): string {
    const sobre = regla.consecutivo_por === null
        ? 'Uno solo para toda la escuela'
        : `Uno ${ETIQUETA_POR[regla.consecutivo_por]}`;

    return `${sobre}, ${regla.consecutivo_anual ? 'reiniciando cada año' : 'histórico (no se reinicia)'}`;
}

const opcionesAmbito = [
    { valor: 'global', texto: 'Toda la escuela' },
    { valor: 'carrera', texto: 'Una carrera' },
    { valor: 'plan', texto: 'Un plan de estudios' },
];

const opcionesPor = computed(() => [
    { valor: null, texto: 'Uno solo para toda la escuela' },
    ...props.consecutivoPor.map((p) => ({ valor: p, texto: `Uno ${ETIQUETA_POR[p]}` })),
]);

const opcionesAlcance = computed(() =>
    (form.ambito === 'plan' ? props.planes : props.carreras).map((o) => ({
        valor: o.id,
        texto: `${o.clave} · ${o.nombre}`,
    })),
);

/** Sin regla global, la conversión de cualquier aspirante revienta. */
const sinReglaGlobal = computed(() => !props.reglas.some((r) => r.ambito === 'global' && r.activo));

const flash = computed(() => (page.props as any).flash ?? {});

function copiar(texto: string): void {
    navigator.clipboard?.writeText(texto);
    toast.success('Copiado: ' + texto);
}
</script>

<template>
    <Head title="Formato de matrícula" />

    <AppLayout titulo="Formato de matrícula">
        <div class="space-y-6">
            <!--
                Sin regla global no hay conversión posible: el generador la busca
                como último recurso y, si no está, lanza. Se avisa arriba porque
                el síntoma aparece lejos —al convertir a un aspirante— y ahí no
                se entiende de dónde viene.
            -->
            <div
                v-if="sinReglaGlobal"
                class="rounded-lg border-l-4 border-l-amber-500 p-4 text-sm"
                style="background-color: color-mix(in srgb, #f59e0b 8%, transparent)"
            >
                <p class="font-medium">No hay una regla general activa.</p>
                <p class="mt-1">
                    Es la que se usa para todo lo que no tenga una propia. Sin ella, convertir a un
                    aspirante en alumno falla porque no hay con qué numerarlo.
                </p>
            </div>

            <TarjetaSeccion
                titulo="Reglas"
                descripcion="De la más específica a la más general: si un plan tiene la suya se usa esa; si no, la de su carrera; si tampoco, la general."
                :icono="ICONOS.ajustes"
            >
                <template #insignia>
                    <BotonAccion v-if="puedeEditar && !abierto" variante="nuevo" texto="Nueva regla" @click="abrirAlta" />
                </template>

                <ul v-if="reglas.length" class="divide-y divide-borde">
                    <li v-for="r in reglas" :key="r.id" class="flex flex-wrap items-start justify-between gap-4 py-4 first:pt-0 last:pb-0">
                        <div class="min-w-0">
                            <p class="flex flex-wrap items-center gap-2 text-sm font-medium text-contenido">
                                {{ r.nombre || r.alcance }}
                                <span
                                    class="rounded-full px-2 py-0.5 text-xs font-normal"
                                    :style="{ backgroundColor: 'var(--color-fondo)', color: 'var(--color-suave)' }"
                                >{{ r.alcance }}</span>
                                <span
                                    v-if="!r.activo"
                                    class="rounded-full px-2 py-0.5 text-xs"
                                    style="background-color: color-mix(in srgb, #f59e0b 14%, transparent); color: #b45309"
                                >Inactiva</span>
                            </p>
                            <p class="mt-1 font-mono text-xs text-suave">{{ r.plantilla }}</p>
                            <p class="mt-1 text-xs text-suave">{{ describirConsecutivo(r) }}</p>
                        </div>

                        <div class="flex shrink-0 items-center gap-3">
                            <!-- Cómo se ve de verdad: es lo que la escuela reconoce
                                 como «sí, así son nuestras matrículas». -->
                            <button
                                v-if="r.ejemplo"
                                type="button"
                                class="rounded-lg px-2.5 py-1 font-mono text-sm"
                                :style="{ backgroundColor: 'color-mix(in srgb, var(--color-acento) 10%, transparent)', color: 'var(--color-acento)' }"
                                title="Ejemplo. Clic para copiar."
                                @click="copiar(r.ejemplo)"
                            >
                                {{ r.ejemplo }}
                            </button>
                            <template v-if="puedeEditar">
                                <BotonAccion variante="editar" @click="editar(r)" />
                                <BotonAccion v-if="r.ambito !== 'global'" variante="eliminar" @click="eliminar(r)" />
                            </template>
                        </div>
                    </li>
                </ul>

                <p v-else class="text-sm text-suave">
                    Todavía no hay ninguna regla. Crea al menos la general.
                </p>
            </TarjetaSeccion>

            <!-- Alta / edición -->
            <TarjetaSeccion
                v-if="abierto"
                :titulo="editando === null ? 'Nueva regla' : 'Editar regla'"
                descripcion="Arma la plantilla con las piezas de abajo y mira el resultado antes de guardar."
                :icono="ICONOS.documentoTexto"
            >
                <form class="space-y-5" @submit.prevent="guardar">
                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <CampoTexto
                            v-model="form.nombre"
                            etiqueta="Nombre"
                            ayuda="Para distinguirla en esta lista. Ej. «Posgrado»."
                            :error="form.errors.nombre"
                        />
                        <CampoSelect
                            v-model="form.ambito"
                            etiqueta="Se aplica a"
                            :opciones="opcionesAmbito"
                            :error="form.errors.ambito"
                            @update:model-value="form.ambito_id = null"
                        />
                        <CampoSelect
                            v-if="form.ambito !== 'global'"
                            v-model="form.ambito_id"
                            :etiqueta="form.ambito === 'plan' ? 'Plan' : 'Carrera'"
                            requerido
                            vacio="Elige…"
                            :opciones="opcionesAlcance"
                            :error="form.errors.ambito_id"
                        />
                    </div>

                    <!-- Plantilla + tokens -->
                    <div class="border-t pt-4" :style="{ borderColor: 'var(--color-borde)' }">
                        <p class="mb-3 text-xs font-semibold uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">
                            Con qué se arma
                        </p>

                        <div class="grid gap-4 lg:grid-cols-2">
                            <div>
                                <CampoTexto
                                    v-model="form.plantilla"
                                    etiqueta="Plantilla"
                                    requerido
                                    mono
                                    :error="form.errors.plantilla"
                                />

                                <p class="mt-2 text-xs text-suave">Agrega piezas:</p>
                                <div class="mt-1.5 flex flex-wrap gap-1.5">
                                    <button
                                        v-for="(descripcion, token) in tokens"
                                        :key="token"
                                        type="button"
                                        class="rounded-lg border px-2 py-1 font-mono text-xs transition hover:bg-fondo"
                                        :style="{ borderColor: 'var(--color-borde)' }"
                                        :title="descripcion"
                                        @click="agregarToken(token)"
                                    >
                                        {{ token }}
                                    </button>
                                </div>
                            </div>

                            <!--
                                Tres matrículas seguidas, no una.
                                Con un solo ejemplo no se distingue qué parte es
                                fija y cuál avanza, que es justo lo que hay que
                                revisar antes de dejar esto puesto para siempre.
                            -->
                            <div
                                class="rounded-lg p-4"
                                :style="{ backgroundColor: 'var(--color-fondo)' }"
                            >
                                <p class="text-xs font-semibold uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">
                                    Así quedarían
                                </p>
                                <ul v-if="!faltaConsecutivo" class="mt-2 space-y-1">
                                    <li v-for="(m, i) in secuencia" :key="i" class="font-mono text-base text-contenido">
                                        {{ m }}
                                    </li>
                                </ul>
                                <p v-else class="mt-2 text-sm text-amber-700">
                                    Falta el consecutivo: sin él todos los alumnos de la misma carrera y
                                    el mismo año tendrían la misma matrícula. Agrega {{ '{####}' }}.
                                </p>
                                <p class="mt-3 text-xs text-suave">
                                    Con claves de ejemplo. El año es el de hoy; el consecutivo real
                                    depende del contador.
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- El consecutivo: dos preguntas, no una -->
                    <div class="border-t pt-4" :style="{ borderColor: 'var(--color-borde)' }">
                        <p class="mb-3 text-xs font-semibold uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">
                            El consecutivo
                        </p>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <CampoSelect
                                v-model="form.consecutivo_por"
                                etiqueta="Se cuenta"
                                :opciones="opcionesPor"
                                ayuda="Con «uno por carrera», el alumno 1 de Derecho y el 1 de Medicina son distintos."
                                :error="form.errors.consecutivo_por"
                            />
                            <CampoSelect
                                v-model="form.consecutivo_anual"
                                etiqueta="Y se reinicia"
                                :opciones="[
                                    { valor: true, texto: 'Cada año (vuelve al 1 en enero)' },
                                    { valor: false, texto: 'Nunca: histórico' },
                                ]"
                                :error="form.errors.consecutivo_anual"
                            />
                        </div>

                        <p class="mt-3 rounded-lg p-3 text-sm" :style="{ backgroundColor: 'var(--color-fondo)' }">
                            {{ describirConsecutivo(form) }}.
                        </p>
                    </div>

                    <label class="flex items-center gap-2 text-sm">
                        <input v-model="form.activo" type="checkbox">
                        Activa
                    </label>

                    <div class="flex items-center gap-2 border-t pt-4" :style="{ borderColor: 'var(--color-borde)' }">
                        <BotonPrincipal
                            :procesando="form.processing"
                            :deshabilitado="faltaConsecutivo"
                            :texto="editando === null ? 'Crear regla' : 'Guardar cambios'"
                        />
                        <button
                            type="button"
                            class="rounded-lg border border-borde px-4 py-2 text-sm text-contenido hover:bg-fondo"
                            @click="abierto = false"
                        >
                            Cancelar
                        </button>
                    </div>
                </form>
            </TarjetaSeccion>

            <!-- Contadores -->
            <TarjetaSeccion
                titulo="En qué número va cada contador"
                descripcion="Se crean solos la primera vez que se usan. Ajústalos si la escuela llega con matrículas ya emitidas y quiere seguir desde su último número."
                :icono="ICONOS.lista"
            >
                <ul v-if="contadores.length" class="divide-y divide-borde">
                    <li v-for="c in contadores" :key="c.clave" class="py-3 first:pt-0 last:pb-0">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-sm text-contenido">{{ c.descripcion }}</p>
                                <p class="mt-0.5 font-mono text-xs text-suave">{{ c.clave }}</p>
                            </div>
                            <div class="flex shrink-0 items-center gap-3">
                                <span class="font-mono text-sm text-contenido">
                                    último: {{ c.valor }}
                                </span>
                                <button
                                    v-if="puedeEditar"
                                    type="button"
                                    class="rounded-lg border px-3 py-1 text-xs"
                                    :style="{ borderColor: 'var(--color-borde)' }"
                                    @click="abrirAjuste(c)"
                                >
                                    Ajustar
                                </button>
                            </div>
                        </div>

                        <form
                            v-if="ajustando === c.clave"
                            class="mt-3 flex flex-wrap items-end gap-3 border-l-2 py-3 pl-3"
                            :style="{ borderColor: 'var(--color-acento)' }"
                            @submit.prevent="guardarAjuste"
                        >
                            <div class="w-48">
                                <CampoTexto
                                    v-model="ajuste.valor"
                                    tipo="number"
                                    etiqueta="Último folio usado"
                                    ayuda="El siguiente alumno recibirá el que sigue."
                                    :error="ajuste.errors.valor"
                                />
                            </div>
                            <BotonPrincipal :procesando="ajuste.processing" texto="Guardar" />
                            <button type="button" class="pb-2 text-sm text-suave" @click="ajustando = null">
                                Cancelar
                            </button>
                        </form>
                    </li>
                </ul>

                <p v-else class="text-sm text-suave">
                    Todavía no se ha emitido ninguna matrícula, así que no hay contadores. El primero
                    se creará solo al convertir al primer aspirante.
                </p>
            </TarjetaSeccion>

            <p v-if="flash.vistaPrevia" class="text-sm text-suave">{{ flash.vistaPrevia }}</p>
        </div>
    </AppLayout>
</template>
