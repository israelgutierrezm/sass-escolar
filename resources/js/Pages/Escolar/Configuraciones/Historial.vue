<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import PestanasSeccion from '@/Components/PestanasSeccion.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoTextarea from '@/Components/CampoTextarea.vue';

/**
 * Cómo se imprime el historial académico de la escuela.
 *
 * ── Por qué NO es un editor de cajas como el de la credencial ─────────────
 * Porque un historial CRECE. Una alumna de primer semestre trae siete renglones
 * y una egresada trescientos, así que no hay coordenada que valga para la fila
 * doscientos. Lo que se decide aquí es qué lleva el encabezado, QUÉ COLUMNAS
 * trae la tabla y en qué orden, cómo se agrupan las materias y qué se firma al
 * pie — que es justo lo que cambia entre los historiales reales de una
 * universidad, un bachillerato y un instituto.
 *
 * ── Sin rol que elegir ────────────────────────────────────────────────────
 * El historial es de los alumnos y de nadie más. Lo único que varía es el nivel
 * de estudios, y esa variante funciona igual que en la credencial: la del nivel
 * si existe, y si no la general.
 */

interface Diseno {
    id: number;
    nivel_estudios_id: number | null;
    titulo: string;
    subtitulo: string | null;
    muestra_logo: boolean;
    muestra_nombre_escuela: boolean;
    campos_alumno: string[] | null;
    columnas: string[] | null;
    agrupacion: string;
    bloques_por_fila: number;
    muestra_resumen: boolean;
    muestra_promedio: boolean;
    muestra_creditos: boolean;
    leyenda: string | null;
    responsable_nombre: string | null;
    responsable_cargo: string | null;
    tamano_papel: string;
    orientacion: string;
    descarga_alumno: boolean;
    marca_agua_alumno: boolean;
    marca_agua_texto: string;
    tiene_firma: boolean;
    tiene_sello: boolean;
}

const props = defineProps<{
    disenos: Diseno[];
    columnas: Record<string, { etiqueta: string; ayuda: string; ancho: number; alineacion: string }>;
    datos: Record<string, { etiqueta: string; ayuda: string }>;
    agrupaciones: Record<string, { etiqueta: string; ayuda: string }>;
    bloquesPorFila: Record<string, { etiqueta: string; ayuda: string }>;
    papeles: string[];
    orientaciones: string[];
    niveles: { id: number; nombre: string }[];
    omision: { campos_alumno: string[]; columnas: string[] };
}>();

const nivelId = ref<number | null>(null);

const guardado = computed(() => props.disenos.find((d) => d.nivel_estudios_id === nivelId.value) ?? null);

const nivelesConVariante = computed(() =>
    props.disenos.filter((d) => d.nivel_estudios_id !== null).map((d) => d.nivel_estudios_id),
);

function vacio() {
    return {
        nivel_estudios_id: nivelId.value,
        titulo: 'Historial académico',
        subtitulo: '',
        muestra_logo: true,
        muestra_nombre_escuela: true,
        campos_alumno: [...props.omision.campos_alumno],
        columnas: [...props.omision.columnas],
        agrupacion: 'periodo',
        bloques_por_fila: 1,
        muestra_resumen: true,
        muestra_promedio: true,
        muestra_creditos: true,
        leyenda: '',
        responsable_nombre: '',
        responsable_cargo: '',
        tamano_papel: 'carta',
        orientacion: 'vertical',
        descarga_alumno: false,
        marca_agua_alumno: true,
        marca_agua_texto: 'No válido sin sello ni firma',
    };
}

const form = useForm(vacio());

watch(
    nivelId,
    () => {
        const d = guardado.value;

        form.defaults(
            d
                ? {
                      nivel_estudios_id: d.nivel_estudios_id,
                      titulo: d.titulo,
                      subtitulo: d.subtitulo ?? '',
                      muestra_logo: d.muestra_logo,
                      muestra_nombre_escuela: d.muestra_nombre_escuela,
                      campos_alumno: d.campos_alumno ?? [...props.omision.campos_alumno],
                      columnas: d.columnas ?? [...props.omision.columnas],
                      agrupacion: d.agrupacion,
                      bloques_por_fila: d.bloques_por_fila,
                      muestra_resumen: d.muestra_resumen,
                      muestra_promedio: d.muestra_promedio,
                      muestra_creditos: d.muestra_creditos,
                      leyenda: d.leyenda ?? '',
                      responsable_nombre: d.responsable_nombre ?? '',
                      responsable_cargo: d.responsable_cargo ?? '',
                      tamano_papel: d.tamano_papel,
                      orientacion: d.orientacion,
                      descarga_alumno: d.descarga_alumno,
                      marca_agua_alumno: d.marca_agua_alumno,
                      marca_agua_texto: d.marca_agua_texto,
                  }
                : vacio(),
        );
        form.reset();
    },
    { immediate: true },
);

/**
 * Poner y quitar columnas conservando el ORDEN.
 *
 * Marcarlas con casillas sueltas habría sido más rápido de escribir y perdería
 * lo importante: en un historial, «Créditos antes de Ciclo» es una decisión de
 * diseño, no un detalle. Se agregan al final y se suben o bajan a mano.
 */
function alternar(lista: 'columnas' | 'campos_alumno', clave: string): void {
    const actual = form[lista] as string[];

    form[lista] = actual.includes(clave) ? actual.filter((c) => c !== clave) : [...actual, clave];
}

function mover(lista: 'columnas' | 'campos_alumno', i: number, delta: number): void {
    const actual = [...(form[lista] as string[])];
    const j = i + delta;

    if (j < 0 || j >= actual.length) return;

    [actual[i], actual[j]] = [actual[j], actual[i]];
    form[lista] = actual;
}

/** Los campos del formulario, tal como los espera el servidor. */
function camposDelFormulario(): Record<string, unknown> {
    return {
        ...form.data(),
        diseno_id: guardado.value?.id ?? '',
    };
}

function token(): string {
    return document.querySelector<HTMLMetaElement>('meta[name=csrf-token]')?.content ?? '';
}

/*
 * ── La vista previa VIVA ──────────────────────────────────────────────────
 *
 * Esta pantalla era un formulario a ciegas: listas con flechas y ningún dibujo
 * del documento. Para ver el efecto de mover una columna había que guardar y
 * abrir otra pestaña, o sea decidir a ojo y comprobar después.
 *
 * Lo que se pinta es el PDF DE VERDAD, el mismo que se imprime —no una
 * aproximación en HTML—, así que lo que se ve es lo que sale.
 */
const previa = ref<string | null>(null);
const refrescando = ref(false);
const fallo = ref(false);
let urlPrevia: string | null = null;
let temporizador: ReturnType<typeof setTimeout> | undefined;

async function refrescarPrevia(): Promise<void> {
    refrescando.value = true;
    fallo.value = false;

    try {
        const cuerpo = new FormData();

        for (const [clave, valor] of Object.entries(camposDelFormulario())) {
            for (const v of Array.isArray(valor) ? valor : [valor]) {
                cuerpo.append(Array.isArray(valor) ? `${clave}[]` : clave, typeof v === 'boolean' ? (v ? '1' : '0') : String(v ?? ''));
            }
        }

        const r = await fetch('/escolar/configuracion/historial/vista-previa', {
            method: 'POST',
            body: cuerpo,
            headers: { 'X-CSRF-TOKEN': token() },
        });

        if (!r.ok) {
            fallo.value = true;
            return;
        }

        const blob = await r.blob();

        // Se libera la anterior: cada refresco crea un objeto en memoria y sin
        // revocarlo la pestaña se va llenando de PDFs muertos mientras alguien
        // acomoda columnas.
        if (urlPrevia) URL.revokeObjectURL(urlPrevia);
        urlPrevia = URL.createObjectURL(blob);
        previa.value = urlPrevia;
    } catch {
        fallo.value = true;
    } finally {
        refrescando.value = false;
    }
}

/*
 * Con retraso: armar el PDF cuesta un par de segundos, así que pedirlo en cada
 * tecla dejaría al servidor generando documentos que nadie va a mirar.
 */
watch(
    () => JSON.stringify(form.data()),
    () => {
        clearTimeout(temporizador);
        temporizador = setTimeout(refrescarPrevia, 700);
    },
);

onMounted(refrescarPrevia);

onBeforeUnmount(() => {
    clearTimeout(temporizador);
    if (urlPrevia) URL.revokeObjectURL(urlPrevia);
});

/**
 * Abre el documento a tamaño completo, en otra pestaña.
 *
 * Sigue haciendo falta junto a la previa del costado: el recuadro sirve para
 * ver el EFECTO de un cambio, y la pestaña para juzgar si el nombre de una
 * asignatura cabe de verdad en su celda.
 */
function verComoQueda(): void {
    const f = document.createElement('form');
    f.method = 'POST';
    f.action = '/escolar/configuracion/historial/vista-previa';
    f.target = '_blank';

    const campos: Record<string, unknown> = {
        ...camposDelFormulario(),
        _token: token(),
    };

    for (const [clave, valor] of Object.entries(campos)) {
        // Los arreglos viajan como `columnas[]`, que es como PHP los recibe;
        // mandarlos con `JSON.stringify` los dejaría como una cadena y el
        // saneador del servidor los descartaría enteros.
        for (const v of Array.isArray(valor) ? valor : [valor]) {
            const i = document.createElement('input');
            i.type = 'hidden';
            i.name = Array.isArray(valor) ? `${clave}[]` : clave;
            i.value = typeof v === 'boolean' ? (v ? '1' : '0') : String(v ?? '');
            f.appendChild(i);
        }
    }

    document.body.appendChild(f);
    f.submit();
    f.remove();
}

function guardar(): void {
    form.put('/escolar/configuracion/historial', { preserveScroll: true });
}

function subir(campo: string, evento: Event): void {
    const archivo = (evento.target as HTMLInputElement).files?.[0];
    if (!archivo || !guardado.value) return;

    router.post(
        `/escolar/configuracion/historial/${guardado.value.id}/imagen`,
        { campo, archivo },
        { preserveScroll: true, forceFormData: true },
    );
}

function quitarImagen(campo: string): void {
    if (!guardado.value) return;

    router.post(
        `/escolar/configuracion/historial/${guardado.value.id}/imagen`,
        { campo },
        { preserveScroll: true },
    );
}

function eliminarVariante(): void {
    if (!guardado.value?.nivel_estudios_id) return;

    router.delete(`/escolar/configuracion/historial/${guardado.value.id}`, {
        preserveScroll: true,
        onSuccess: () => (nivelId.value = null),
    });
}

const columnasPuestas = computed(() => form.columnas as string[]);
const datosPuestos = computed(() => form.campos_alumno as string[]);
</script>

<template>
    <Head title="Diseño del historial académico" />

    <AppLayout titulo="Historial académico">
        <PestanasSeccion />

        <!--
            Formulario a la IZQUIERDA y el documento a la derecha, pegado al
            desplazarse: es lo que esta pantalla no tenía y por lo que se
            configuraba a ciegas. Debajo de `xl` se apila, porque un PDF en una
            columna de 300 px no se puede juzgar.
        -->
        <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_26rem]">
        <div class="space-y-4">
            <section class="tarjeta p-5">
                <h2 class="text-base font-semibold">Cómo se imprime el historial</h2>
                <p class="mt-1 max-w-3xl text-sm" :style="{ color: 'var(--color-suave)' }">
                    Un historial crece con el alumno, así que no se acomoda por coordenadas como una credencial:
                    aquí decides qué lleva el encabezado, qué columnas trae la tabla y en qué orden, cómo se
                    agrupan las materias y quién lo firma.
                </p>

                <div class="mt-4 flex flex-wrap items-center gap-2 border-t border-borde pt-4">
                    <span class="text-xs font-semibold uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">
                        Aplica a
                    </span>
                    <button
                        type="button"
                        class="rounded-full border px-3 py-1 text-xs"
                        :class="nivelId === null ? 'elegido-acento' : 'border-borde'"
                        @click="nivelId = null"
                    >
                        Todos los niveles
                    </button>
                    <button
                        v-for="n in niveles"
                        :key="n.id"
                        type="button"
                        class="rounded-full border px-3 py-1 text-xs"
                        :class="
                            nivelId === n.id
                                ? 'elegido-acento'
                                : nivelesConVariante.includes(n.id)
                                  ? 'border-emerald-300 text-emerald-700'
                                  : 'border-borde text-slate-500'
                        "
                        @click="nivelId = n.id"
                    >
                        {{ n.nombre }}
                    </button>

                    <button
                        v-if="guardado && nivelId !== null"
                        type="button"
                        class="ml-auto text-xs text-red-600 hover:underline"
                        @click="eliminarVariante"
                    >
                        Eliminar esta variante
                    </button>
                </div>
                <p class="mt-2 text-xs" :style="{ color: 'var(--color-suave)' }">
                    Un nivel sin diseño propio usa el general. Sirve para lo que de verdad cambia: un
                    bachillerato imprime semestres y una licenciatura, créditos.
                </p>
            </section>

            <div class="grid gap-4 lg:grid-cols-2">
                <section class="tarjeta space-y-4 p-5">
                    <p class="text-sm font-semibold">Encabezado</p>

                    <CampoTexto v-model="form.titulo" etiqueta="Título del documento" :maximo="120" requerido />
                    <CampoTexto
                        v-model="form.subtitulo"
                        etiqueta="Subtítulo"
                        ayuda="Ej. la facultad o la dirección que lo emite."
                        :maximo="160"
                    />

                    <div class="flex flex-wrap gap-4">
                        <label class="flex items-center gap-2 text-sm">
                            <input v-model="form.muestra_logo" type="checkbox" class="h-4 w-4 rounded border-borde" />
                            Logo de la escuela
                        </label>
                        <label class="flex items-center gap-2 text-sm">
                            <input v-model="form.muestra_nombre_escuela" type="checkbox" class="h-4 w-4 rounded border-borde" />
                            Nombre de la escuela
                        </label>
                    </div>

                    <div class="border-t border-borde pt-3">
                        <p class="mb-2 text-sm font-semibold">Datos del alumno</p>
                        <ul v-if="datosPuestos.length" class="mb-2 space-y-1">
                            <li
                                v-for="(clave, i) in datosPuestos"
                                :key="clave"
                                class="flex items-center gap-2 rounded border border-borde px-2 py-1 text-sm"
                            >
                                <span class="flex-1">{{ datos[clave]?.etiqueta ?? clave }}</span>
                                <button type="button" class="px-1 text-xs disabled:opacity-25" :disabled="i === 0" @click="mover('campos_alumno', i, -1)">↑</button>
                                <button type="button" class="px-1 text-xs disabled:opacity-25" :disabled="i === datosPuestos.length - 1" @click="mover('campos_alumno', i, 1)">↓</button>
                                <button type="button" class="px-1 text-xs text-red-600" @click="alternar('campos_alumno', clave)">✕</button>
                            </li>
                        </ul>
                        <div class="flex flex-wrap gap-1.5">
                            <button
                                v-for="(meta, clave) in datos"
                                :key="clave"
                                v-show="!datosPuestos.includes(String(clave))"
                                type="button"
                                class="rounded-full border border-borde px-2.5 py-1 text-xs hover:bg-slate-50"
                                :title="meta.ayuda"
                                @click="alternar('campos_alumno', String(clave))"
                            >
                                + {{ meta.etiqueta }}
                            </button>
                        </div>
                    </div>
                </section>

                <section class="tarjeta space-y-4 p-5">
                    <p class="text-sm font-semibold">Columnas de la tabla</p>
                    <p class="text-xs" :style="{ color: 'var(--color-suave)' }">
                        El orden es el que se imprime. Es lo que más cambia de una escuela a otra.
                    </p>

                    <ul v-if="columnasPuestas.length" class="space-y-1">
                        <li
                            v-for="(clave, i) in columnasPuestas"
                            :key="clave"
                            class="flex items-center gap-2 rounded border border-borde px-2 py-1 text-sm"
                        >
                            <span class="flex-1">{{ columnas[clave]?.etiqueta ?? clave }}</span>
                            <button type="button" class="px-1 text-xs disabled:opacity-25" :disabled="i === 0" @click="mover('columnas', i, -1)">↑</button>
                            <button type="button" class="px-1 text-xs disabled:opacity-25" :disabled="i === columnasPuestas.length - 1" @click="mover('columnas', i, 1)">↓</button>
                            <button type="button" class="px-1 text-xs text-red-600" @click="alternar('columnas', clave)">✕</button>
                        </li>
                    </ul>
                    <p v-else class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">
                        Sin columnas no hay tabla. Al imprimir se cae a «Asignatura», que es lo único sin lo cual
                        el documento no significa nada.
                    </p>

                    <div class="flex flex-wrap gap-1.5 border-t border-borde pt-3">
                        <button
                            v-for="(meta, clave) in columnas"
                            :key="clave"
                            v-show="!columnasPuestas.includes(String(clave))"
                            type="button"
                            class="rounded-full border border-borde px-2.5 py-1 text-xs hover:bg-slate-50"
                            :title="meta.ayuda"
                            @click="alternar('columnas', String(clave))"
                        >
                            + {{ meta.etiqueta }}
                        </button>
                    </div>
                </section>
            </div>

            <section class="tarjeta space-y-4 p-5">
                <p class="text-sm font-semibold">Agrupación y resumen</p>

                <div class="grid gap-2 sm:grid-cols-3">
                    <button
                        v-for="(meta, clave) in agrupaciones"
                        :key="clave"
                        type="button"
                        class="rounded-lg border p-3 text-left"
                        :class="form.agrupacion === clave ? 'elegido-acento' : 'border-borde hover:bg-slate-50'"
                        @click="form.agrupacion = String(clave)"
                    >
                        <span class="block text-sm font-medium">{{ meta.etiqueta }}</span>
                        <span class="mt-0.5 block text-xs" :style="{ color: 'var(--color-suave)' }">{{ meta.ayuda }}</span>
                    </button>
                </div>

                <!--
                    A dos columnas sólo tiene sentido con bloques: sin agrupar
                    es una lista corrida y partirla en dos no significa nada, así
                    que la opción se atenúa en vez de esconderse — que se
                    entienda que existe y por qué no aplica ahora.
                -->
                <div class="border-t border-borde pt-3" :class="form.agrupacion === 'ninguna' ? 'opacity-50' : ''">
                    <p class="mb-2 text-sm font-semibold">Cómo se acomodan los bloques</p>
                    <div class="grid gap-2 sm:grid-cols-2">
                        <button
                            v-for="(meta, cuantos) in bloquesPorFila"
                            :key="cuantos"
                            type="button"
                            :disabled="form.agrupacion === 'ninguna'"
                            class="rounded-lg border p-3 text-left disabled:cursor-not-allowed"
                            :class="form.bloques_por_fila === Number(cuantos) ? 'elegido-acento' : 'border-borde hover:bg-slate-50'"
                            @click="form.bloques_por_fila = Number(cuantos)"
                        >
                            <span class="block text-sm font-medium">{{ meta.etiqueta }}</span>
                            <span class="mt-0.5 block text-xs" :style="{ color: 'var(--color-suave)' }">{{ meta.ayuda }}</span>
                        </button>
                    </div>
                </div>

                <div class="flex flex-wrap gap-4 border-t border-borde pt-3">
                    <label class="flex items-center gap-2 text-sm">
                        <input v-model="form.muestra_resumen" type="checkbox" class="h-4 w-4 rounded border-borde" />
                        Recuadro de resumen
                    </label>
                    <label class="flex items-center gap-2 text-sm">
                        <input v-model="form.muestra_promedio" type="checkbox" class="h-4 w-4 rounded border-borde" :disabled="!form.muestra_resumen" />
                        Promedio general
                    </label>
                    <label class="flex items-center gap-2 text-sm">
                        <input v-model="form.muestra_creditos" type="checkbox" class="h-4 w-4 rounded border-borde" :disabled="!form.muestra_resumen" />
                        Créditos acumulados
                    </label>
                </div>
            </section>

            <section class="tarjeta space-y-4 p-5">
                <p class="text-sm font-semibold">Pie del documento</p>

                <CampoTextarea
                    v-model="form.leyenda"
                    etiqueta="Leyenda"
                    ayuda="El texto legal del pie. Ej. «Se extiende el presente para los fines que al interesado convengan…»."
                />

                <div class="grid gap-3 sm:grid-cols-2">
                    <CampoTexto v-model="form.responsable_nombre" etiqueta="Responsable que firma" :maximo="120" />
                    <CampoTexto v-model="form.responsable_cargo" etiqueta="Cargo" :maximo="120" />
                </div>

                <div v-if="guardado" class="grid gap-3 sm:grid-cols-2">
                    <div v-for="c in (['firma_imagen', 'sello_imagen'] as const)" :key="c">
                        <p class="mb-1 text-xs font-medium">{{ c === 'firma_imagen' ? 'Firma' : 'Sello' }}</p>
                        <img
                            v-if="c === 'firma_imagen' ? guardado.tiene_firma : guardado.tiene_sello"
                            :src="`/escolar/configuracion/historial/${guardado.id}/imagen/${c}`"
                            alt=""
                            class="mb-1.5 h-20 rounded border border-borde bg-white p-1"
                        />
                        <div class="flex items-center gap-2">
                            <input type="file" accept="image/png,image/jpeg" class="text-xs" @change="subir(c, $event)" />
                            <button
                                v-if="c === 'firma_imagen' ? guardado.tiene_firma : guardado.tiene_sello"
                                type="button"
                                class="text-xs text-red-600 hover:underline"
                                @click="quitarImagen(c)"
                            >
                                Quitar
                            </button>
                        </div>
                    </div>
                </div>
                <p v-else class="text-xs text-amber-700">
                    Guarda primero el diseño para poder cargar la firma y el sello.
                </p>
            </section>

            <section class="tarjeta space-y-4 p-5">
                <p class="text-sm font-semibold">Papel y descarga del alumno</p>

                <div class="flex flex-wrap gap-4">
                    <label class="text-sm">
                        <span class="mb-1 block font-medium">Tamaño</span>
                        <select v-model="form.tamano_papel" class="rounded-lg border border-borde px-3 py-2 text-sm capitalize">
                            <option v-for="p in papeles" :key="p" :value="p">{{ p }}</option>
                        </select>
                    </label>
                    <label class="text-sm">
                        <span class="mb-1 block font-medium">Orientación</span>
                        <select v-model="form.orientacion" class="rounded-lg border border-borde px-3 py-2 text-sm capitalize">
                            <option v-for="o in orientaciones" :key="o" :value="o">{{ o }}</option>
                        </select>
                    </label>
                </div>

                <div class="space-y-2 rounded-lg border border-borde p-3">
                    <label class="flex cursor-pointer items-start gap-2 text-sm">
                        <input v-model="form.descarga_alumno" type="checkbox" class="mt-0.5 h-4 w-4 rounded border-borde" />
                        <span>
                            <span class="font-medium">El alumno puede descargarlo</span>
                            <span class="block text-xs" :style="{ color: 'var(--color-suave)' }">
                                Sin marcar, el historial sólo se entrega en ventanilla. La opción no le aparece
                                en su portal y la dirección responde que no existe.
                            </span>
                        </span>
                    </label>

                    <template v-if="form.descarga_alumno">
                        <label class="flex cursor-pointer items-start gap-2 text-sm">
                            <input v-model="form.marca_agua_alumno" type="checkbox" class="mt-0.5 h-4 w-4 rounded border-borde" />
                            <span>
                                <span class="font-medium">Con marca de agua</span>
                                <span class="block text-xs" :style="{ color: 'var(--color-suave)' }">
                                    Su copia sale sin sello ni firma autógrafa. Sin la marca, un PDF idéntico al
                                    oficial circula sin que nadie pueda distinguir el que emitiste del que
                                    alguien editó.
                                </span>
                            </span>
                        </label>

                        <CampoTexto
                            v-if="form.marca_agua_alumno"
                            v-model="form.marca_agua_texto"
                            etiqueta="Texto de la marca"
                            :maximo="80"
                            requerido
                        />
                    </template>
                </div>
            </section>

            <div class="flex justify-end gap-2">
                <button
                    type="button"
                    class="rounded-lg border border-borde px-4 py-2 text-sm hover:bg-slate-50"
                    @click="verComoQueda"
                >
                    Ver cómo queda
                </button>
                <button
                    type="button"
                    class="rounded-lg px-4 py-2 text-sm font-medium disabled:opacity-60"
                    :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                    :disabled="form.processing"
                    @click="guardar"
                >
                    Guardar diseño
                </button>
            </div>
        </div>

            <aside class="xl:sticky xl:top-4 xl:self-start">
                <section class="tarjeta overflow-hidden">
                    <div class="flex items-center justify-between border-b border-borde px-4 py-2.5">
                        <p class="text-sm font-semibold">Así queda</p>
                        <span v-if="refrescando" class="text-xs" :style="{ color: 'var(--color-suave)' }">
                            actualizando…
                        </span>
                        <button
                            v-else
                            type="button"
                            class="text-xs underline"
                            :style="{ color: 'var(--color-suave)' }"
                            @click="verComoQueda"
                        >Abrir a tamaño completo</button>
                    </div>

                    <!--
                        El PDF de verdad, el mismo que se imprime. Se pinta en un
                        iframe porque el visor del navegador ya sabe paginarlo:
                        así se ve el corte de hoja, el membrete repetido y el
                        folio, que es justo lo que no se podía comprobar antes.
                    -->
                    <iframe
                        v-if="previa && !fallo"
                        :src="previa"
                        class="block w-full"
                        style="height: 34rem; border: 0"
                        title="Vista previa del historial"
                    ></iframe>

                    <p v-else-if="fallo" class="px-4 py-10 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                        No se pudo dibujar la vista previa. Revisa que el título y los demás campos
                        obligatorios estén llenos.
                    </p>

                    <p v-else class="px-4 py-10 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                        Preparando la vista previa…
                    </p>

                    <p class="border-t border-borde px-4 py-2 text-xs" :style="{ color: 'var(--color-suave)' }">
                        Datos inventados, a propósito: acomodar columnas no es motivo para abrir el
                        expediente de nadie. El ejemplo son 60 materias para que se vea el corte de hoja.
                    </p>
                </section>
            </aside>
        </div>
    </AppLayout>
</template>
