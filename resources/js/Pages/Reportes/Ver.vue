<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';

import { celdaReporte, type TipoDato } from '@/utils/celdaReporte';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import CampoFiltroReporte from '@/Components/CampoFiltroReporte.vue';
import Paginacion from '@/Components/Paginacion.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';

interface Columna {
    clave: string;
    etiqueta: string;
    alineacion: string;
    ordenable: boolean;
}

/**
 * Una forma guardada de ver el reporte.
 *
 * Guarda COLUMNAS y FILTROS, jamas filas: al abrirla, el motor rehace el
 * pipeline con el permiso y el alcance de QUIEN la abre. Por eso una vista se
 * puede compartir sin compartir datos.
 */
interface Vista {
    id: number;
    nombre: string;
    descripcion: string | null;
    predeterminada: boolean;
    deLaEscuela: boolean;
    mia: boolean;
    puedeEditar: boolean;
}

interface Filtro {
    clave: string;
    etiqueta: string;
    tipo: string;
    ayuda: string | null;
    opciones: Record<string, string>;
}

const props = defineProps<{
    reporte: { clave: string; titulo: string; descripcion: string; grano: string };
    columnas: Columna[];
    disponibles: { clave: string; etiqueta: string; ayuda: string | null; sensible: boolean }[];
    filtros: Filtro[];
    filtrosFijos: string[];
    aplicados: Record<string, unknown>;
    filas: Record<string, unknown>[];
    paginacion: { total: number; links: any[]; from: number | null; to: number | null };
    omitidas: string[];
    /**
     * El pie de la tabla. Null si ninguna columna elegida se totaliza.
     *
     * `cuadra` dice si la consulta agregada vio las MISMAS filas que el
     * paginador. En falso no se pintan las cifras: un total inflado por un join
     * que multiplica no da error, da otro numero — y el pie es lo que alguien
     * copia a una junta.
     */
    totales: {
        cuadra: boolean;
        filas: number;
        valores: Record<string, number | null>;
    } | null;
    ms: number;
    vistas: Vista[];
    vistaActiva: number | null;
    puedeCompartir: boolean;
    esFavorito: boolean;
    /** Por que columna se ordena y hacia donde. `por` en null = por la llave. */
    orden: { por: string | null; dir: string };
    /** Por que se PUEDE agrupar. Vacio = esta fuente no se agrupa. */
    dimensiones: { clave: string; etiqueta: string; ayuda: string | null }[];
    agrupadoPor: string | null;
    /**
     * Por que el reporte no se pudo correr todavia, o null.
     *
     * Tres reportes exigen un filtro y el motor se niega sin el, con razon. Lo
     * que no puede ser es que pulsarlos desde el indice de una pagina de error:
     * el reporte existe, solo necesita que le digas por donde empezar.
     */
    faltaFiltro: string | null;
    agrupado: {
        dimension: string;
        grupos: { etiqueta: string | null; filas: number; valores: Record<string, number | null> }[];
        medidas: { clave: string; etiqueta: string; tipo: string; alineacion: string }[];
        filas: number;
        truncado: boolean;
    } | null;
}>();

const eligiendo = ref(false);
const valores = ref<Record<string, unknown>>({ ...props.aplicados });
const elegidas = ref<string[]>(props.columnas.map((c) => c.clave));

const agrupadoPor = ref<string | null>(props.agrupadoPor);

/**
 * Agrupar o dejar de agrupar recarga con TODO lo demas puesto.
 *
 * Es la misma pregunta mirada de otra forma, asi que no se pierden ni los
 * filtros ni las columnas: perderlos obligaria a volver a armar el reporte cada
 * vez que alguien quiere ver el resumen.
 */
function agrupar(clave: string | null): void {
    agrupadoPor.value = agrupadoPor.value === clave ? null : clave;
    aplicar();
}

/**
 * El ancho de la barra, sobre el TOTAL de las filas agrupadas.
 *
 * Ojo: NO es la misma barra que la del panel, aunque se parezca. Alla se mide
 * contra el MAYOR de la serie y esta decidido asi a proposito --en un embudo que
 * arranca en 200 y termina en 3, medir contra el total deja invisibles las
 * ultimas etapas, que son las que interesan--. Aqui la pregunta es <<que parte
 * del total es este grupo>>, y la respuesta es una proporcion: medir contra el
 * mayor diria que el grupo mas grande es el 100 % de la escuela.
 *
 * Dos escalas distintas para dos preguntas distintas. Si algun dia se unifican,
 * el denominador tiene que ser un parametro.
 */
function anchoDelGrupo(filas: number): string {
    const total = props.agrupado?.filas ?? 0;

    return total === 0 ? '0%' : Math.max(1, Math.round((filas / total) * 100)) + '%';
}

/**
 * La primera columna que NO se totaliza, para colgarle el rotulo del pie.
 *
 * Null si todas se totalizan --y entonces no hay hueco donde ponerlo, que es
 * correcto: la fila entera son cifras--.
 */
const primeraSinTotal = computed(() => {
    const sinTotal = props.columnas.find((c) => props.totales?.valores[c.clave] === undefined);

    return sinTotal?.clave ?? null;
});

function sumaDeMedida(clave: string): number {
    return (props.agrupado?.grupos ?? []).reduce((s, g) => s + Number(g.valores[clave] ?? 0), 0);
}

const ordenPor = ref<string | null>(props.orden.por);
const ordenDir = ref<string>(props.orden.dir);

/** Recarga con lo que hay en pantalla. La URL lleva todo: el resultado es enlazable. */
function aplicar(): void {
    router.get(
        `/reportes/${props.reporte.clave}`,
        {
            filtros: valores.value,
            columnas: elegidas.value,
            agrupar_por: agrupadoPor.value,
            // El orden viaja por la MISMA via que los filtros, asi que entra en
            // la URL y de ahi lo recoge la descarga: el Excel sale ordenado como
            // la pantalla sin escribir una linea mas.
            orden_por: ordenPor.value,
            orden_dir: ordenDir.value,
        },
        { preserveState: true, preserveScroll: true },
    );
}

/**
 * Pulsar una cabecera ordena por ella; volver a pulsarla le da la vuelta.
 *
 * El backend aceptaba `orden_por` desde el primer dia y habia 165 ranuras
 * `ordenable` declaradas en las 14 fuentes, pero el `<th>` no era pulsable: al
 * orden solo se llegaba escribiendo la direccion a mano. Una suite entera lo
 * comprobaba sobre un camino que nadie tenia.
 *
 * La primera pulsada va ASCENDENTE porque es lo que se espera de una lista;
 * para una fecha o un importe, la segunda da lo que casi siempre se busca.
 */
function ordenarPor(clave: string): void {
    if (ordenPor.value === clave) {
        ordenDir.value = ordenDir.value === 'asc' ? 'desc' : 'asc';
    } else {
        ordenPor.value = clave;
        ordenDir.value = 'asc';
    }

    aplicar();
}

function alternarColumna(clave: string): void {
    elegidas.value = elegidas.value.includes(clave)
        ? elegidas.value.filter((c) => c !== clave)
        : [...elegidas.value, clave];
}

const page = usePage();

/**
 * La cadena de consulta VIVA, para que la descarga lleve lo mismo que la
 * pantalla.
 *
 * Sale de `usePage().url`, que Inertia actualiza en cada navegacion. Iba con
 * `window.location.search` dentro de un computed, y eso NO es reactivo: un
 * computed sin dependencias se calcula una vez y se queda cacheado con la URL
 * del montaje. O sea que quien filtraba y despues pulsaba «Excel» se bajaba el
 * archivo SIN sus filtros --el archivo abre bien y trae de mas, que es la peor
 * forma de fallar--.
 */
const consulta = computed(() => {
    const i = page.url.indexOf('?');

    return i === -1 ? '' : page.url.slice(i);
});

const guardando = ref(false);

const formVista = useForm({
    nombre: '',
    descripcion: '',
    columnas: [] as string[],
    filtros: {} as Record<string, unknown>,
    predeterminada: false as boolean,
    de_la_escuela: false as boolean,
});

function abrirGuardar(): void {
    guardando.value = true;
    formVista.clearErrors();
    formVista.nombre = '';
    formVista.descripcion = '';
    // Se guarda LO QUE HAY EN PANTALLA: es lo que la persona acaba de armar.
    formVista.columnas = [...elegidas.value];
    formVista.filtros = { ...valores.value };
}

function guardarVista(): void {
    formVista.post(`/reportes/${props.reporte.clave}/vistas`, {
        preserveScroll: true,
        onSuccess: () => {
            guardando.value = false;
            formVista.reset();
        },
    });
}

function abrirVista(id: number | null): void {
    router.get(`/reportes/${props.reporte.clave}`, id === null ? {} : { vista: id }, { preserveScroll: true });
}

function eliminarVista(v: Vista): void {
    if (!confirm(`Eliminar la vista «${v.nombre}»?`)) return;

    router.delete(`/reportes/vistas/${v.id}`, { preserveScroll: true });
}

function alternarFavorito(): void {
    router.post(`/reportes/${props.reporte.clave}/favorito`, {}, { preserveScroll: true });
}

function claseAlineacion(a: string): string {
    return a === 'derecha' ? 'text-right' : a === 'centro' ? 'text-center' : '';
}
</script>

<template>
    <Head :title="reporte.titulo" />

    <AppLayout :titulo="reporte.titulo">
        <div class="mb-3">
            <Link href="/reportes" class="text-xs hover:underline" :style="{ color: 'var(--color-suave)' }">
                ← Todos los reportes
            </Link>
        </div>

        <!--
            Las vistas guardadas. Es la respuesta al «los mismos reportes de
            formas personalizadas»: se guarda la configuracion, no una consulta
            nueva. Compartir una vista NO comparte datos --el motor rehace el
            pipeline con el alcance de quien la abre--.
        -->
        <section class="tarjeta mb-4 flex flex-wrap items-center gap-2 p-3">
            <button
                type="button"
                class="text-lg leading-none"
                :title="esFavorito ? 'Quitar de favoritos' : 'Marcar como favorito'"
                @click="alternarFavorito"
            >{{ esFavorito ? '★' : '☆' }}</button>

            <button
                type="button"
                class="rounded-full border px-3 py-1 text-xs"
                :class="vistaActiva === null ? 'elegido-acento' : 'border-borde hover:bg-slate-50'"
                @click="abrirVista(null)"
            >Como viene</button>

            <span v-for="v in vistas" :key="v.id" class="inline-flex items-center">
                <button
                    type="button"
                    class="rounded-full border px-3 py-1 text-xs"
                    :class="vistaActiva === v.id ? 'elegido-acento' : 'border-borde hover:bg-slate-50'"
                    :title="v.descripcion ?? ''"
                    @click="abrirVista(v.id)"
                >
                    {{ v.nombre }}
                    <span v-if="v.deLaEscuela" :style="{ color: 'var(--color-suave)' }" title="De la escuela"> · escuela</span>
                    <span v-else-if="v.predeterminada" :style="{ color: 'var(--color-suave)' }" title="Se abre sola"> · ★</span>
                </button>
                <button
                    v-if="v.puedeEditar"
                    type="button"
                    class="ml-0.5 text-xs text-red-600"
                    title="Eliminar la vista"
                    @click="eliminarVista(v)"
                >✕</button>
            </span>

            <button
                type="button"
                class="ml-auto rounded-lg border border-borde px-3 py-1 text-xs hover:bg-slate-50"
                @click="abrirGuardar"
            >Guardar esta vista</button>
        </section>

        <div v-if="guardando" class="tarjeta mb-4 space-y-3 p-4">
            <p class="text-sm font-medium">Guardar la vista actual</p>
            <p class="text-xs" :style="{ color: 'var(--color-suave)' }">
                Se guardan las columnas y los filtros que tienes puestos, no las filas: quien la abra verá
                lo que su permiso y su campus le permitan.
            </p>

            <div class="grid gap-3 sm:grid-cols-2">
                <CampoTexto v-model="formVista.nombre" etiqueta="Nombre" :maximo="120" requerido :error="formVista.errors.nombre" />
                <CampoTexto v-model="formVista.descripcion" etiqueta="Para qué sirve" :maximo="255" :error="formVista.errors.descripcion" />
            </div>

            <label class="flex items-center gap-2 text-sm">
                <input v-model="formVista.predeterminada" type="checkbox" class="h-4 w-4 rounded border-borde" />
                Que se abra sola cuando entre a este reporte
            </label>

            <label v-if="puedeCompartir" class="flex items-start gap-2 text-sm">
                <input v-model="formVista.de_la_escuela" type="checkbox" class="mt-0.5 h-4 w-4 rounded border-borde" />
                <span>
                    <span class="font-medium">Para toda la escuela</span>
                    <span class="block text-xs" :style="{ color: 'var(--color-suave)' }">
                        La verá cualquiera que pueda ejecutar este reporte. No le concede acceso a nada:
                        cada quien sigue viendo sólo lo suyo.
                    </span>
                </span>
            </label>

            <div class="flex gap-2">
                <button
                    type="button"
                    class="rounded-lg px-3 py-1.5 text-sm font-medium disabled:opacity-60"
                    :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                    :disabled="formVista.processing"
                    @click="guardarVista"
                >Guardar</button>
                <button type="button" class="rounded-lg border border-borde px-3 py-1.5 text-sm" @click="guardando = false">Cancelar</button>
            </div>
        </div>

        <section class="tarjeta mb-4 p-5">
            <p class="text-sm">{{ reporte.descripcion }}</p>
            <!--
                QUÉ ES UNA FILA. Va arriba y no en una nota al pie: es la
                diferencia entre leer «28 alumnos» y «28 materias de una alumna»,
                y quien se lleva el número a una junta lo lee aquí o no lo lee.
            -->
            <p class="mt-2 text-xs font-medium" :style="{ color: 'var(--color-suave)' }">{{ reporte.grano }}</p>
        </section>

        <!-- Lo que se omitió por permiso se DICE. Callarlo haría creer que el
             reporte no trae esa columna. -->
        <p v-if="omitidas.length" class="mb-3 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">
            No se muestran estas columnas porque tu rol no las alcanza: {{ omitidas.join(', ') }}.
        </p>

        <section class="tarjeta mb-4 space-y-3 p-4">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <div v-for="f in filtros" :key="f.clave">
                    <p class="mb-1 text-xs font-medium">
                        {{ f.etiqueta }}
                        <!-- Un filtro FIJO se enseña pero no se toca: es lo que
                             hace que el reporte conteste su pregunta. -->
                        <span v-if="filtrosFijos.includes(f.clave)" class="font-normal" :style="{ color: 'var(--color-suave)' }">
                            · lo fija el reporte
                        </span>
                    </p>

                    <!--
                        El control lo dibuja `CampoFiltroReporte`, que comparte
                        con el CONSTRUCTOR de reportes: con el `v-if` por tipo
                        copiado en las dos pantallas, la segunda nace divergida
                        y un tipo nuevo se atiende sólo en una.

                        Al extraerlo salió que `lista` no tenía rama: caía a
                        caja de TEXTO y había que teclear a mano el valor exacto.
                    -->
                    <CampoFiltroReporte
                        v-model="valores[f.clave]"
                        :tipo="f.tipo"
                        :opciones="f.opciones"
                        :deshabilitado="filtrosFijos.includes(f.clave)"
                    />

                    <p v-if="f.ayuda" class="mt-0.5 text-xs" :style="{ color: 'var(--color-suave)' }">{{ f.ayuda }}</p>
                </div>
            </div>

            <!--
                El reporte pide un filtro antes de poder contestar. No es un
                error: es la pregunta a medio hacer.
            -->
            <p
                v-if="faltaFiltro"
                class="rounded-lg border px-3 py-2 text-sm"
                :style="{ borderColor: '#b45309', color: '#b45309' }"
            >{{ faltaFiltro }}</p>

            <div class="flex flex-wrap items-center gap-2 border-t border-borde pt-3">
                <button
                    type="button"
                    class="rounded-lg px-3 py-1.5 text-sm font-medium"
                    :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                    @click="aplicar"
                >Aplicar</button>
                <button
                    type="button"
                    class="rounded-lg border border-borde px-3 py-1.5 text-sm"
                    @click="eligiendo = !eligiendo"
                >Columnas ({{ elegidas.length }})</button>
                <!--
                    Las descargas llevan los MISMOS filtros y columnas que la
                    pantalla: es el mismo motor, así que lo que se ve es lo que
                    se baja. Van como enlaces y no con `router`: son archivos.
                -->
                <a
                    :href="`/reportes/${reporte.clave}/descargar/xlsx${consulta}`"
                    class="rounded-lg border border-borde px-3 py-1.5 text-sm hover:bg-slate-50"
                >Excel</a>
                <a
                    :href="`/reportes/${reporte.clave}/descargar/csv${consulta}`"
                    class="rounded-lg border border-borde px-3 py-1.5 text-sm hover:bg-slate-50"
                    title="Sin límite de filas: se escribe renglón por renglón."
                >CSV</a>

                <span class="ml-auto text-xs" :style="{ color: 'var(--color-suave)' }">
                    {{ paginacion.total }} en {{ ms }} ms
                </span>
            </div>

            <div v-if="eligiendo" class="flex flex-wrap gap-1.5 border-t border-borde pt-3">
                <button
                    v-for="d in disponibles"
                    :key="d.clave"
                    type="button"
                    class="rounded-full border px-2.5 py-1 text-xs"
                    :class="elegidas.includes(d.clave) ? 'elegido-acento' : 'border-borde hover:bg-slate-50'"
                    :title="d.ayuda ?? ''"
                    @click="alternarColumna(d.clave)"
                >
                    {{ d.etiqueta }}<span v-if="d.sensible" title="Dato sensible"> ·</span>
                </button>
            </div>
        </section>

        <!-- El AGRUPADO, arriba del detalle y no en su lugar: verlos juntos es
             lo que deja comprobar de un vistazo que los subtotales suman. -->
        <TarjetaSeccion
            v-if="dimensiones.length"
            :titulo="agrupado ? `Por ${agrupado.dimension.toLowerCase()}` : 'Resumen'"
            sin-relleno
            class="mb-4"
        >
            <div class="flex flex-wrap items-center gap-2 px-6 py-3">
                <span class="text-sm" :style="{ color: 'var(--color-suave)' }">Agrupar por:</span>

                <button
                    v-for="d in dimensiones"
                    :key="d.clave"
                    type="button"
                    class="rounded-lg border px-3 py-1 text-sm"
                    :class="agrupadoPor === d.clave ? 'elegido-acento' : 'border-borde hover:bg-slate-50'"
                    :title="d.ayuda ?? ''"
                    @click="agrupar(d.clave)"
                >{{ d.etiqueta }}</button>

                <button
                    v-if="agrupadoPor"
                    type="button"
                    class="ml-1 text-sm underline"
                    :style="{ color: 'var(--color-suave)' }"
                    @click="agrupar(null)"
                >Quitar</button>
            </div>

            <!-- Con cero grupos no se dibuja la tabla: un encabezado y un
                 renglon de totales en cero se leen como «la escuela no tiene
                 nada», cuando lo que pasa es que el filtro no dejo filas. -->
            <div
                v-if="agrupado && agrupado.grupos.length"
                class="overflow-x-auto border-t"
                :style="{ borderColor: 'var(--color-borde)' }"
            >
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs" :style="{ color: 'var(--color-suave)' }">
                            <th class="px-4 pb-2 pt-3 font-medium">{{ agrupado.dimension }}</th>
                            <th class="px-4 pb-2 pt-3 text-right font-medium">Filas</th>
                            <th class="w-1/3 px-4 pb-2 pt-3 font-medium">Parte del total</th>
                            <th
                                v-for="m in agrupado.medidas"
                                :key="m.clave"
                                class="whitespace-nowrap px-4 pb-2 pt-3 font-medium"
                                :class="claseAlineacion(m.alineacion)"
                            >{{ m.etiqueta }}</th>
                        </tr>
                    </thead>

                    <tbody>
                        <tr
                            v-for="(g, i) in agrupado.grupos"
                            :key="i"
                            class="border-t"
                            :style="{ borderColor: 'var(--color-borde)' }"
                        >
                            <td class="px-4 py-2">
                                <span v-if="g.etiqueta">{{ g.etiqueta }}</span>
                                <!-- El grupo SIN etiqueta se enseña, no se
                                     esconde: si se escondiera, los subtotales
                                     dejarían de sumar el total. -->
                                <span v-else :style="{ color: 'var(--color-suave)' }">Sin asignar</span>
                            </td>

                            <td class="px-4 py-2 text-right tabular-nums">{{ g.filas.toLocaleString('es-MX') }}</td>

                            <td class="px-4 py-2">
                                <div
                                    class="h-2 w-full overflow-hidden rounded-full"
                                    :style="{ background: 'var(--color-borde)' }"
                                >
                                    <div
                                        class="fondo-acento h-full rounded-full"
                                        :style="{ width: anchoDelGrupo(g.filas) }"
                                    />
                                </div>
                            </td>

                            <td
                                v-for="m in agrupado.medidas"
                                :key="m.clave"
                                class="px-4 py-2 tabular-nums"
                                :class="claseAlineacion(m.alineacion)"
                            >{{ celdaReporte(g.valores[m.clave], m.tipo as TipoDato) }}</td>
                        </tr>

                        <tr class="border-t-2 font-semibold" :style="{ borderColor: 'var(--color-borde)' }">
                            <td class="px-4 py-3">
                                {{ agrupado.grupos.length }}
                                {{ agrupado.grupos.length === 1 ? 'grupo' : 'grupos' }}
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ agrupado.filas.toLocaleString('es-MX') }}</td>
                            <td />
                            <td
                                v-for="m in agrupado.medidas"
                                :key="m.clave"
                                class="px-4 py-3 tabular-nums"
                                :class="claseAlineacion(m.alineacion)"
                            >{{ celdaReporte(sumaDeMedida(m.clave), m.tipo as TipoDato) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p
                v-if="agrupado && !agrupado.grupos.length"
                class="border-t px-6 py-6 text-center text-sm"
                :style="{ borderColor: 'var(--color-borde)', color: 'var(--color-suave)' }"
            >Ninguna fila cumple con lo que pediste, así que no hay nada que agrupar.</p>

            <!-- Cortado se DICE: una tabla truncada en silencio se lee como el
                 total de la escuela, y sus subtotales no sumarían. -->
            <p
                v-if="agrupado?.truncado"
                class="border-t px-6 py-3 text-sm"
                :style="{ borderColor: 'var(--color-borde)', color: '#b45309' }"
            >
                Sólo se muestran los primeros grupos: esta columna tiene demasiados valores distintos
                para ser un agrupado. Los subtotales de arriba NO suman el total del reporte.
            </p>
        </TarjetaSeccion>

        <TarjetaSeccion titulo="Resultado" sin-relleno>
            <!-- Se desplaza dentro de su tarjeta: con doce columnas no cabe en
                 una pantalla, y eso no debe arrastrar la página entera. -->
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs" :style="{ color: 'var(--color-suave)' }">
                            <th
                                v-for="c in columnas"
                                :key="c.clave"
                                class="whitespace-nowrap px-4 pb-2 pt-3 font-medium"
                                :class="claseAlineacion(c.alineacion)"
                                :aria-sort="orden.por === c.clave
                                    ? (orden.dir === 'asc' ? 'ascending' : 'descending')
                                    : 'none'"
                            >
                                <!-- Boton solo donde SE PUEDE ordenar: una
                                     cabecera que invita a pulsarla y no hace
                                     nada es peor que una que no invita. Lo que
                                     no es ordenable no tiene columna SQL, y el
                                     motor lo descartaria en silencio. -->
                                <button
                                    v-if="c.ordenable"
                                    type="button"
                                    class="inline-flex items-center gap-1 hover:underline"
                                    :title="`Ordenar por ${c.etiqueta}`"
                                    @click="ordenarPor(c.clave)"
                                >
                                    {{ c.etiqueta }}
                                    <span
                                        v-if="orden.por === c.clave"
                                        aria-hidden="true"
                                    >{{ orden.dir === 'asc' ? '↑' : '↓' }}</span>
                                </button>
                                <span v-else>{{ c.etiqueta }}</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="(fila, i) in filas"
                            :key="i"
                            class="border-t"
                            :style="{ borderColor: 'var(--color-borde)' }"
                        >
                            <td
                                v-for="c in columnas"
                                :key="c.clave"
                                class="px-4 py-2"
                                :class="claseAlineacion(c.alineacion)"
                            >{{ celdaReporte(fila[c.clave], c.tipo as TipoDato) }}</td>
                        </tr>
                    </tbody>

                    <!-- El pie: los totales DEL REPORTE, no de la pagina. Se
                         dice con palabras al lado, porque un numero al pie de
                         una tabla paginada se lee como el de lo que se ve. -->
                    <tfoot v-if="totales && totales.cuadra && filas.length">
                        <tr
                            class="border-t-2 font-semibold"
                            :style="{ borderColor: 'var(--color-borde)' }"
                        >
                            <td
                                v-for="(c, i) in columnas"
                                :key="c.clave"
                                class="px-4 py-3 tabular-nums"
                                :class="claseAlineacion(c.alineacion)"
                            >
                                <span v-if="totales.valores[c.clave] !== undefined">
                                    {{ celdaReporte(totales.valores[c.clave], c.tipo as TipoDato) }}
                                </span>
                                <!-- La primera columna SIN total rotula el
                                     renglon; las demas quedan en blanco a
                                     proposito: un cero ahi se leeria como «vale
                                     cero», que es otra afirmacion.
                                     Iba atado a `i === 0`, asi que el rotulo
                                     desaparecia en cuanto la primera columna
                                     elegida era de dinero y el pie quedaba sin
                                     decir de cuantas filas hablaba. -->
                                <span
                                    v-else-if="c.clave === primeraSinTotal"
                                    class="font-normal"
                                    :style="{ color: 'var(--color-suave)' }"
                                >Total de {{ totales.filas.toLocaleString('es-MX') }}</span>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <!-- Y si NO cuadra se dice, en vez de ensenar una cifra que no se
                 pudo verificar. -->
            <p
                v-if="totales && !totales.cuadra"
                class="border-t px-6 py-3 text-sm"
                :style="{ borderColor: 'var(--color-borde)', color: '#b45309' }"
            >
                No se pueden mostrar los totales de este reporte: la suma vio
                {{ totales.filas.toLocaleString('es-MX') }} renglones y el listado tiene
                {{ paginacion.total.toLocaleString('es-MX') }}. Enseñar la cifra sin cuadrarla
                sería enseñar otro número.
            </p>

            <p v-if="!filas.length" class="px-6 py-8 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                <!-- Sin correr todavia no es lo mismo que sin resultados: decir
                     «ninguna fila cumple con lo que pediste» cuando no se ha
                     pedido nada hace pensar que la escuela no tiene datos. -->
                <span v-if="faltaFiltro">Elige lo que falta arriba y pulsa «Aplicar» para ver el reporte.</span>
                <span v-else>Ninguna fila cumple con lo que pediste.</span>
            </p>
        </TarjetaSeccion>

        <Paginacion
            :enlaces="paginacion.links"
            :total="paginacion.total"
            :desde="paginacion.from"
            :hasta="paginacion.to"
            class="mt-4"
        />
    </AppLayout>
</template>
