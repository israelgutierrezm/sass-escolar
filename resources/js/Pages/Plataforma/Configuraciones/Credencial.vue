<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import EditorCajasCredencial from '@/Components/EditorCajasCredencial.vue';
import Modal from '@/Components/Modal.vue';

/**
 * La credencial virtual de cada rol.
 *
 * ── Una pantalla, un rol a la vez ─────────────────────────────────────────
 * A la izquierda están todos los roles de la escuela con su estado —emite,
 * apagada, sin configurar— y a la derecha se trabaja el elegido. Enseñar todas
 * las credenciales a la vez habría sido una rejilla de formularios idénticos
 * donde no se distingue cuál se está editando.
 *
 * ── El nivel de estudios, sólo para alumnos ───────────────────────────────
 * Un docente no cursa nada, así que una credencial de docente atada a
 * «Licenciatura» no la elegiría nunca nadie. El selector de variante sólo
 * aparece en los roles de la faceta alumno, y el servidor lo vuelve a
 * comprobar: esto es de lo que se configura una vez y se descubre roto un año
 * después.
 */

interface Rol {
    id: number;
    nombre: string;
    faceta: string | null;
    es_alumno: boolean;
}

interface Caja {
    clave: string;
    x: number;
    y: number;
    ancho: number;
    alto: number;
    tamano?: number;
    alineacion?: 'izquierda' | 'centro' | 'derecha';
    etiqueta?: string | null;
    color?: string | null;
    color_etiqueta?: string | null;
}

interface Config {
    id: number;
    rol_id: number;
    nivel_estudios_id: number | null;
    activa: boolean;
    diseno: string;
    ancho: number;
    alto: number;
    campos_anverso: Caja[] | null;
    campos_reverso: Caja[] | null;
    vigencia: string | null;
    qr_activo: boolean;
    qr_publico: boolean;
    firma_nombre: string | null;
    firma_cargo: string | null;
    tiene_machote_anverso: boolean;
    tiene_machote_reverso: boolean;
    tiene_firma: boolean;
}

const props = defineProps<{
    roles: Rol[];
    configuraciones: Config[];
    campos: Record<string, { etiqueta: string; ayuda: string; tipo: string }>;
    disenos: Record<string, { nombre: string; descripcion: string }>;
    niveles: { id: number; nombre: string }[];
}>();

const rolId = ref<number | null>(props.roles[0]?.id ?? null);
const nivelId = ref<number | null>(null);
const cara = ref<'anverso' | 'reverso'>('anverso');

const rol = computed(() => props.roles.find((r) => r.id === rolId.value) ?? null);

/** La fila guardada de esta combinación, si existe. */
const guardada = computed(
    () =>
        props.configuraciones.find(
            (c) => c.rol_id === rolId.value && c.nivel_estudios_id === nivelId.value,
        ) ?? null,
);

/**
 * Los tamaños que se ofrecen.
 *
 * CR80 es el de una tarjeta de crédito, que es el plástico que imprimen las
 * escuelas; a 300 ppp da estas medidas. Se ofrecen las dos orientaciones porque
 * son dos gafetes distintos —el vertical es el que cuelga del cordón— y aun así
 * se puede escribir cualquier medida: hay escuelas con troqueles propios.
 */
const TAMANOS = [
    { etiqueta: 'Tarjeta horizontal (CR80)', ancho: 1011, alto: 638 },
    { etiqueta: 'Tarjeta vertical (CR80)', ancho: 638, alto: 1011 },
    { etiqueta: 'Gafete grande vertical', ancho: 1004, alto: 1417 },
];

function vacia(): Omit<Config, 'id' | 'tiene_machote_anverso' | 'tiene_machote_reverso' | 'tiene_firma'> {
    return {
        rol_id: rolId.value!,
        nivel_estudios_id: nivelId.value,
        activa: false,
        diseno: 'clasico',
        ancho: 1011,
        alto: 638,
        campos_anverso: [],
        campos_reverso: [],
        vigencia: null,
        qr_activo: false,
        qr_publico: false,
        firma_nombre: null,
        firma_cargo: null,
    };
}

const form = useForm(vacia());

/** Al cambiar de rol o de variante se recarga el formulario con lo guardado. */
watch(
    [rolId, nivelId],
    () => {
        const c = guardada.value;

        form.defaults(
            c
                ? {
                      rol_id: c.rol_id,
                      nivel_estudios_id: c.nivel_estudios_id,
                      activa: c.activa,
                      diseno: c.diseno,
                      ancho: c.ancho,
                      alto: c.alto,
                      campos_anverso: c.campos_anverso ?? [],
                      campos_reverso: c.campos_reverso ?? [],
                      vigencia: c.vigencia,
                      qr_activo: c.qr_activo,
                      qr_publico: c.qr_publico,
                      firma_nombre: c.firma_nombre,
                      firma_cargo: c.firma_cargo,
                  }
                : vacia(),
        );
        form.reset();
    },
    { immediate: true },
);

// Un rol que no es alumno no tiene variantes: si se cambia a uno así estando en
// una, hay que volver a la general o se editaría una fila imposible.
watch(rol, (r) => {
    if (r && !r.es_alumno) nivelId.value = null;
});

/*
 * El fondo del editor lo dibuja el servidor.
 *
 * Se pide SIN campos —sólo el diseño o el machote— porque encima van las cajas
 * arrastrables. Y se pide con retardo: cada tecleo en el ancho dispararía una
 * composición de imagen completa en el servidor.
 */
const fondo = ref<string | null>(null);
let pendiente: number | undefined;

function pedirFondo(): void {
    window.clearTimeout(pendiente);

    pendiente = window.setTimeout(async () => {
        const respuesta = await fetch(`/plataforma/configuraciones/credencial/vista-previa/${cara.value}`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name=csrf-token]')?.content ?? '',
            },
            body: JSON.stringify({
                credencial_id: guardada.value?.id ?? null,
                diseno: form.diseno,
                ancho: form.ancho,
                alto: form.alto,
                campos_anverso: [],
                campos_reverso: [],
            }),
        });

        if (!respuesta.ok) return;

        // El blob anterior se libera: sin esto, mover una caja treinta veces
        // deja treinta imágenes retenidas en memoria del navegador.
        if (fondo.value) URL.revokeObjectURL(fondo.value);
        fondo.value = URL.createObjectURL(await respuesta.blob());
    }, 350);
}

watch([() => form.diseno, () => form.ancho, () => form.alto, cara, guardada], pedirFondo, { immediate: true });

onBeforeUnmount(() => {
    window.clearTimeout(pendiente);
    if (fondo.value) URL.revokeObjectURL(fondo.value);
});

/**
 * La composición de verdad, con los campos puestos.
 *
 * ── Va en un MODAL, y esto no es preferencia ──────────────────────────────
 * Estaba debajo del editor y parecía que el botón no servía: medido, la imagen
 * aparecía 391 px por debajo del botón —fuera de la pantalla— y con la
 * credencial vertical caía mucho más abajo todavía. Se pulsaba, no pasaba nada
 * visible y a nadie se le ocurre desplazarse para buscar lo que acaba de pedir.
 * Encima, el diálogo distingue el resultado del lienzo del editor, que se le
 * parece bastante cuando la cara aún no tiene campos.
 */
const renderizada = ref<string | null>(null);
const renderizando = ref(false);
const verPrevia = ref(false);

/** Por qué no se pudo dibujar, si no se pudo. Callarlo es peor que decirlo. */
const fallo = ref<string | null>(null);

async function verComoQueda(): Promise<void> {
    renderizando.value = true;
    verPrevia.value = true;

    try {
        const respuesta = await fetch(`/plataforma/configuraciones/credencial/vista-previa/${cara.value}`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name=csrf-token]')?.content ?? '',
            },
            body: JSON.stringify({
                credencial_id: guardada.value?.id ?? null,
                diseno: form.diseno,
                ancho: form.ancho,
                alto: form.alto,
                vigencia: form.vigencia,
                campos_anverso: form.campos_anverso,
                campos_reverso: form.campos_reverso,
            }),
        });

        if (!respuesta.ok) {
            fallo.value = `El servidor respondió ${respuesta.status}.`;

            return;
        }

        fallo.value = null;

        if (renderizada.value) URL.revokeObjectURL(renderizada.value);
        renderizada.value = URL.createObjectURL(await respuesta.blob());
    } catch (e) {
        fallo.value = 'No se pudo contactar al servidor.';
    } finally {
        // En `finally` a la fuerza: sin esto, un fallo de red dejaba el botón
        // en «Dibujando…» y deshabilitado para siempre, y la única salida era
        // recargar.
        renderizando.value = false;
    }
}

const cajasDeLaCara = computed({
    get: () => (cara.value === 'anverso' ? form.campos_anverso : form.campos_reverso) ?? [],
    set: (v: Caja[]) => {
        if (cara.value === 'anverso') form.campos_anverso = v;
        else form.campos_reverso = v;
    },
});

/** Cuántos datos lleva la cara que se está mirando. */
const cuantosCampos = computed(() => cajasDeLaCara.value.length);

function guardar(): void {
    form.put('/plataforma/configuraciones/credencial', { preserveScroll: true });
}

function subir(campo: string, evento: Event): void {
    const archivo = (evento.target as HTMLInputElement).files?.[0];
    if (!archivo || !guardada.value) return;

    router.post(
        `/plataforma/configuraciones/credencial/${guardada.value.id}/imagen`,
        { campo, archivo },
        { preserveScroll: true, forceFormData: true },
    );
}

function quitarImagen(campo: string): void {
    if (!guardada.value) return;

    router.post(
        `/plataforma/configuraciones/credencial/${guardada.value.id}/imagen`,
        { campo },
        { preserveScroll: true },
    );
}

function eliminarVariante(): void {
    if (!guardada.value?.nivel_estudios_id) return;

    router.delete(`/plataforma/configuraciones/credencial/${guardada.value.id}`, {
        preserveScroll: true,
        onSuccess: () => (nivelId.value = null),
    });
}

/** Qué decir de cada rol en la lista, sin obligar a entrar para saberlo. */
function estadoDe(r: Rol): { texto: string; clase: string } {
    const suyas = props.configuraciones.filter((c) => c.rol_id === r.id);

    if (!suyas.length) return { texto: 'Sin configurar', clase: 'text-slate-400' };
    if (suyas.some((c) => c.activa)) {
        const variantes = suyas.filter((c) => c.nivel_estudios_id !== null).length;

        return {
            texto: variantes ? `Emite · ${variantes} variante(s)` : 'Emite',
            clase: 'text-emerald-600',
        };
    }

    return { texto: 'Apagada', clase: 'text-amber-600' };
}

const nivelesConVariante = computed(() =>
    props.configuraciones
        .filter((c) => c.rol_id === rolId.value && c.nivel_estudios_id !== null)
        .map((c) => c.nivel_estudios_id),
);
</script>

<template>
    <Head title="Credencial virtual" />

    <AppLayout titulo="Credencial virtual">
        <div class="grid gap-4 lg:grid-cols-[15rem_minmax(0,1fr)]">
            <aside class="tarjeta h-fit p-2">
                <p class="px-2 py-1.5 text-xs font-semibold uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">
                    Roles
                </p>
                <button
                    v-for="r in roles"
                    :key="r.id"
                    type="button"
                    class="block w-full rounded-lg px-2 py-1.5 text-left text-sm"
                    :class="r.id === rolId ? 'bg-indigo-50 font-semibold text-indigo-700' : 'hover:bg-slate-50'"
                    @click="rolId = r.id"
                >
                    {{ r.nombre }}
                    <span class="block text-xs font-normal" :class="estadoDe(r).clase">{{ estadoDe(r).texto }}</span>
                </button>
            </aside>

            <div v-if="rol" class="space-y-4">
                <section class="tarjeta p-5">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h2 class="text-base font-semibold">Credencial de {{ rol.nombre }}</h2>
                            <p class="mt-0.5 text-sm" :style="{ color: 'var(--color-suave)' }">
                                Sin encender, nadie con este rol ve su credencial ni la puede descargar.
                            </p>
                        </div>

                        <label class="flex cursor-pointer items-center gap-2 text-sm">
                            <input v-model="form.activa" type="checkbox" class="h-4 w-4 rounded border-borde" />
                            <span class="font-medium">Emite credencial</span>
                        </label>
                    </div>

                    <!-- La variante por nivel: sólo donde tiene sentido. -->
                    <div v-if="rol.es_alumno" class="mt-4 flex flex-wrap items-center gap-2 border-t border-borde pt-4">
                        <span class="text-xs font-semibold uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">
                            Variante
                        </span>
                        <button
                            type="button"
                            class="rounded-full border px-3 py-1 text-xs"
                            :class="nivelId === null ? 'border-indigo-500 bg-indigo-50 text-indigo-700' : 'border-borde'"
                            @click="nivelId = null"
                        >
                            General del rol
                        </button>
                        <button
                            v-for="n in niveles"
                            :key="n.id"
                            type="button"
                            class="rounded-full border px-3 py-1 text-xs"
                            :class="
                                nivelId === n.id
                                    ? 'border-indigo-500 bg-indigo-50 text-indigo-700'
                                    : nivelesConVariante.includes(n.id)
                                      ? 'border-emerald-300 text-emerald-700'
                                      : 'border-borde text-slate-500'
                            "
                            @click="nivelId = n.id"
                        >
                            {{ n.nombre }}
                        </button>

                        <button
                            v-if="guardada && nivelId !== null"
                            type="button"
                            class="ml-auto text-xs text-red-600 hover:underline"
                            @click="eliminarVariante"
                        >
                            Eliminar esta variante
                        </button>
                    </div>
                    <p v-if="rol.es_alumno" class="mt-2 text-xs" :style="{ color: 'var(--color-suave)' }">
                        Un nivel sin variante propia usa la credencial general del rol.
                    </p>
                </section>

                <section class="tarjeta space-y-5 p-5">
                    <div>
                        <p class="mb-2 text-sm font-semibold">Diseño</p>
                        <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                            <button
                                v-for="(d, clave) in disenos"
                                :key="clave"
                                type="button"
                                class="rounded-lg border p-3 text-left"
                                :class="form.diseno === clave ? 'border-indigo-500 bg-indigo-50' : 'border-borde hover:bg-slate-50'"
                                @click="form.diseno = String(clave)"
                            >
                                <span class="block text-sm font-medium">{{ d.nombre }}</span>
                                <span class="mt-0.5 block text-xs" :style="{ color: 'var(--color-suave)' }">
                                    {{ d.descripcion }}
                                </span>
                            </button>

                            <button
                                type="button"
                                class="rounded-lg border p-3 text-left"
                                :class="form.diseno === 'propio' ? 'border-indigo-500 bg-indigo-50' : 'border-borde hover:bg-slate-50'"
                                @click="form.diseno = 'propio'"
                            >
                                <span class="block text-sm font-medium">Machote propio</span>
                                <span class="mt-0.5 block text-xs" :style="{ color: 'var(--color-suave)' }">
                                    Tu imagen de fondo, ya diseñada. Sólo se colocan los datos encima.
                                </span>
                            </button>
                        </div>
                    </div>

                    <div v-if="form.diseno === 'propio'" class="rounded-lg border border-borde p-3">
                        <p v-if="!guardada" class="text-sm text-amber-700">
                            Guarda primero la credencial para poder cargarle las imágenes.
                        </p>
                        <div v-else class="grid gap-3 sm:grid-cols-2">
                            <div v-for="c in (['machote_anverso', 'machote_reverso'] as const)" :key="c">
                                <p class="mb-1 text-xs font-medium">
                                    {{ c === 'machote_anverso' ? 'Anverso' : 'Reverso (opcional)' }}
                                </p>
                                <img
                                    v-if="c === 'machote_anverso' ? guardada.tiene_machote_anverso : guardada.tiene_machote_reverso"
                                    :src="`/plataforma/configuraciones/credencial/${guardada.id}/imagen/${c}`"
                                    alt=""
                                    class="mb-1.5 w-full rounded border border-borde"
                                />
                                <div class="flex items-center gap-2">
                                    <input type="file" accept="image/png,image/jpeg" class="text-xs" @change="subir(c, $event)" />
                                    <button
                                        v-if="c === 'machote_anverso' ? guardada.tiene_machote_anverso : guardada.tiene_machote_reverso"
                                        type="button"
                                        class="text-xs text-red-600 hover:underline"
                                        @click="quitarImagen(c)"
                                    >
                                        Quitar
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div>
                        <p class="mb-2 text-sm font-semibold">Tamaño</p>
                        <div class="flex flex-wrap items-end gap-2">
                            <button
                                v-for="t in TAMANOS"
                                :key="t.etiqueta"
                                type="button"
                                class="rounded-full border px-3 py-1 text-xs"
                                :class="
                                    form.ancho === t.ancho && form.alto === t.alto
                                        ? 'border-indigo-500 bg-indigo-50 text-indigo-700'
                                        : 'border-borde'
                                "
                                @click="
                                    form.ancho = t.ancho;
                                    form.alto = t.alto;
                                "
                            >
                                {{ t.etiqueta }}
                            </button>

                            <div class="flex items-end gap-2">
                                <CampoTexto v-model="form.ancho" etiqueta="Ancho (px)" tipo="number" class="w-28" />
                                <CampoTexto v-model="form.alto" etiqueta="Alto (px)" tipo="number" class="w-28" />
                            </div>
                        </div>
                    </div>
                </section>

                <section class="tarjeta space-y-4 p-5">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <p class="text-sm font-semibold">Dónde va cada dato</p>

                        <div class="flex items-center gap-2">
                            <div class="flex rounded-lg border border-borde p-0.5">
                                <button
                                    v-for="c in (['anverso', 'reverso'] as const)"
                                    :key="c"
                                    type="button"
                                    class="rounded-md px-3 py-1 text-xs capitalize"
                                    :class="cara === c ? 'bg-indigo-500 text-white' : ''"
                                    @click="cara = c"
                                >
                                    {{ c }}
                                </button>
                            </div>

                            <button
                                type="button"
                                class="rounded-lg border border-borde px-3 py-1.5 text-xs hover:bg-slate-50 disabled:opacity-50"
                                :disabled="renderizando"
                                @click="verComoQueda"
                            >
                                {{ renderizando ? 'Dibujando…' : 'Ver cómo queda' }}
                            </button>
                        </div>
                    </div>

                    <EditorCajasCredencial
                        v-model="cajasDeLaCara"
                        :catalogo="campos"
                        :ancho="Number(form.ancho)"
                        :alto="Number(form.alto)"
                        :fondo="fondo"
                    />

                </section>

                <section class="tarjeta space-y-4 p-5">
                    <p class="text-sm font-semibold">Vigencia, QR y firma</p>

                    <CampoTexto
                        v-model="form.vigencia"
                        etiqueta="Leyenda de vigencia"
                        ayuda="Igual para todas las credenciales de este rol. Ej. «Vigente hasta julio 2027»."
                        :maximo="120"
                    />

                    <div class="space-y-2 rounded-lg border border-borde p-3">
                        <label class="flex cursor-pointer items-center gap-2 text-sm">
                            <input v-model="form.qr_activo" type="checkbox" class="h-4 w-4 rounded border-borde" />
                            <span class="font-medium">Incluir código QR</span>
                        </label>
                        <p class="text-xs" :style="{ color: 'var(--color-suave)' }">
                            Al escanearlo se abre una ficha con la foto y los datos que el sistema tiene guardados,
                            para comprobar que la credencial no fue alterada. Agrégalo al reverso desde el editor.
                        </p>

                        <label v-if="form.qr_activo" class="flex cursor-pointer items-start gap-2 text-sm">
                            <input v-model="form.qr_publico" type="checkbox" class="mt-0.5 h-4 w-4 rounded border-borde" />
                            <span>
                                <span class="font-medium">Cualquiera puede verla</span>
                                <span class="block text-xs" :style="{ color: 'var(--color-suave)' }">
                                    Sin marcar, hay que iniciar sesión para abrirla. Déjalo cerrado si sólo la va a
                                    escanear personal de la escuela: abierto, quien fotografíe una credencial ajena
                                    puede consultar sus datos.
                                </span>
                            </span>
                        </label>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <CampoTexto v-model="form.firma_nombre" etiqueta="Nombre de quien firma" :maximo="120" />
                        <CampoTexto v-model="form.firma_cargo" etiqueta="Cargo" :maximo="120" />
                    </div>

                    <div v-if="guardada">
                        <p class="mb-1 text-xs font-medium">Imagen de la firma</p>
                        <img
                            v-if="guardada.tiene_firma"
                            :src="`/plataforma/configuraciones/credencial/${guardada.id}/imagen/firma_imagen`"
                            alt=""
                            class="mb-1.5 h-16 rounded border border-borde bg-white p-1"
                        />
                        <div class="flex items-center gap-2">
                            <input type="file" accept="image/png,image/jpeg" class="text-xs" @change="subir('firma_imagen', $event)" />
                            <button
                                v-if="guardada.tiene_firma"
                                type="button"
                                class="text-xs text-red-600 hover:underline"
                                @click="quitarImagen('firma_imagen')"
                            >
                                Quitar
                            </button>
                        </div>
                    </div>
                    <p v-else class="text-xs text-amber-700">
                        Guarda primero para poder cargar la imagen de la firma.
                    </p>
                </section>

                <div class="flex justify-end">
                    <button
                        type="button"
                        class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-60"
                        :disabled="form.processing"
                        @click="guardar"
                    >
                        Guardar credencial
                    </button>
                </div>
            </div>
        </div>

        <Modal
            v-if="verPrevia"
            etiqueta="Así se va a imprimir"
            ancho="max-w-2xl"
            @cerrar="verPrevia = false"
        >
            <template #default="{ cerrar }">
                <div class="p-5">
                    <h2 class="text-base font-semibold">Así se va a imprimir · {{ cara }}</h2>
                    <p class="mt-1 text-xs" :style="{ color: 'var(--color-suave)' }">
                        Con datos de ejemplo a propósito: el nombre y la carrera son largos, para que se vea si
                        alguna caja quedó chica.
                    </p>

                    <p v-if="fallo" class="mt-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">
                        No se pudo dibujar. {{ fallo }}
                    </p>

                    <!-- Sin campos, la imagen sale idéntica al lienzo del editor
                         y parece que el botón no hizo nada. Se dice. -->
                    <p v-else-if="cuantosCampos === 0" class="mt-4 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800">
                        Esta cara no tiene ningún dato puesto todavía, así que sale sólo el fondo. Agrégale
                        campos desde el editor.
                    </p>

                    <!-- Acotada al ALTO de la ventana, no al ancho del diálogo.
                         Una credencial vertical a 672 px de ancho mide 1064 de
                         alto: medido, se salía de la pantalla por arriba y no se
                         veía ni la cabeza de la foto. `object-contain` la encoge
                         entera en vez de recortarla. -->
                    <img
                        v-if="renderizada && !fallo"
                        :src="renderizada"
                        alt="Vista previa"
                        class="mx-auto mt-4 max-h-[65vh] w-auto max-w-full rounded-lg border border-borde object-contain"
                    />
                    <p v-else-if="renderizando" class="mt-6 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                        Dibujando…
                    </p>

                    <div class="mt-5 flex justify-end">
                        <button
                            type="button"
                            class="rounded-lg border border-borde px-4 py-2 text-sm hover:bg-slate-50"
                            @click="cerrar"
                        >
                            Cerrar
                        </button>
                    </div>
                </div>
            </template>
        </Modal>
    </AppLayout>
</template>
