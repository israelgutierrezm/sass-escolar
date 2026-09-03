<script setup lang="ts">
/**
 * El constructor de reportes de la escuela.
 *
 * Un reporte de aquí es un PRESET sobre una fuente que escribió un
 * programador: nombre + columnas + filtros fijos + orden. No hay campo de SQL,
 * y la pantalla lo dice con palabras — es lo primero que alguien va a pedir.
 */
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

import AppLayout from '@/Layouts/AppLayout.vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import CampoFiltroReporte from '@/Components/CampoFiltroReporte.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoTextarea from '@/Components/CampoTextarea.vue';
import MenuAcciones from '@/Components/MenuAcciones.vue';
import PildoraEstado from '@/Components/PildoraEstado.vue';

interface Columna {
    clave: string;
    etiqueta: string;
    ayuda: string | null;
    sensible: boolean;
    ordenable: boolean;
}

interface Filtro {
    clave: string;
    etiqueta: string;
    tipo: string;
    ayuda: string | null;
    opciones: Record<string | number, string>;
}

interface Fuente {
    clave: string;
    titulo: string;
    grano: string;
    columnas: Columna[];
    filtros: Filtro[];
}

interface Reporte {
    id: number;
    clave: string;
    nombre: string;
    descripcion: string;
    fuente: string;
    fuenteTitulo: string;
    area_sugerida: string;
    columnas: string[];
    filtros_fijos: Record<string, unknown>;
    filtros_obligatorios: string[];
    orden_por: string | null;
    orden_dir: string | null;
    publicado: boolean;
    problema: string | null;
    editable: boolean;
}

const props = defineProps<{
    reportes: Reporte[];
    fuentes: Fuente[];
    areas: { valor: string; texto: string }[];
}>();

const editando = ref<Reporte | null>(null);
const abierto = ref(false);

const formulario = useForm({
    nombre: '',
    descripcion: '',
    fuente: '',
    area_sugerida: props.areas[0]?.valor ?? 'general',
    columnas: [] as string[],
    filtros_fijos: {} as Record<string, unknown>,
    filtros_obligatorios: [] as string[],
    orden_por: '' as string,
    orden_dir: 'asc',
    publicado: false,
});

const fuente = computed(() => props.fuentes.find((f) => f.clave === formulario.fuente) ?? null);

const ordenables = computed(() =>
    (fuente.value?.columnas ?? [])
        .filter((c) => c.ordenable && formulario.columnas.includes(c.clave))
        .map((c) => ({ valor: c.clave, texto: c.etiqueta })),
);

/*
 * Al cambiar de fuente se VACÍA lo elegido, y no es comodidad: las columnas y
 * los filtros de la anterior no significan nada en la nueva, y el servidor los
 * rechazaría uno por uno. Conservarlos sería ofrecer un formulario que no puede
 * guardarse.
 */
watch(() => formulario.fuente, (nueva, vieja) => {
    if (vieja === '' || nueva === vieja) {
        return;
    }

    formulario.columnas = [];
    formulario.filtros_fijos = {};
    formulario.filtros_obligatorios = [];
    formulario.orden_por = '';
});

function abrirNuevo(): void {
    editando.value = null;
    formulario.reset();
    formulario.clearErrors();
    formulario.area_sugerida = props.areas[0]?.valor ?? 'general';
    abierto.value = true;
}

function abrirEdicion(r: Reporte): void {
    editando.value = r;
    formulario.clearErrors();
    formulario.nombre = r.nombre;
    formulario.descripcion = r.descripcion;
    formulario.fuente = r.fuente;
    formulario.area_sugerida = r.area_sugerida;
    formulario.columnas = [...r.columnas];
    formulario.filtros_fijos = { ...r.filtros_fijos };
    formulario.filtros_obligatorios = [...r.filtros_obligatorios];
    formulario.orden_por = r.orden_por ?? '';
    formulario.orden_dir = r.orden_dir ?? 'asc';
    formulario.publicado = r.publicado;
    abierto.value = true;
}

function cerrar(): void {
    abierto.value = false;
    editando.value = null;
}

function alternarColumna(clave: string): void {
    const i = formulario.columnas.indexOf(clave);

    if (i === -1) {
        formulario.columnas.push(clave);
    } else {
        formulario.columnas.splice(i, 1);

        // El orden apuntaba a una columna que se acaba de quitar: el servidor lo
        // rechazaría, y dejarlo puesto haría creer que sigue valiendo.
        if (formulario.orden_por === clave) {
            formulario.orden_por = '';
        }
    }
}

/*
 * Con botones y no arrastrando: arrastrar en táctil pelea con el desplazamiento
 * de la página, y esto se abre también desde una tableta.
 */
function mover(i: number, salto: number): void {
    const j = i + salto;

    if (j < 0 || j >= formulario.columnas.length) {
        return;
    }

    const copia = [...formulario.columnas];
    [copia[i], copia[j]] = [copia[j], copia[i]];
    formulario.columnas = copia;
}

function etiquetaDe(clave: string): string {
    return fuente.value?.columnas.find((c) => c.clave === clave)?.etiqueta ?? clave;
}

function esFijo(clave: string): boolean {
    const v = formulario.filtros_fijos[clave];

    return v !== undefined && v !== null && v !== '' && !(Array.isArray(v) && v.length === 0);
}

function alternarObligatorio(clave: string): void {
    const i = formulario.filtros_obligatorios.indexOf(clave);

    i === -1
        ? formulario.filtros_obligatorios.push(clave)
        : formulario.filtros_obligatorios.splice(i, 1);
}

function guardar(): void {
    const destino = editando.value
        ? `/reportes/constructor/${editando.value.id}`
        : '/reportes/constructor';

    const opciones = { preserveScroll: true, onSuccess: () => cerrar() };

    editando.value ? formulario.put(destino, opciones) : formulario.post(destino, opciones);
}

function alternarPublicado(r: Reporte): void {
    router.patch(`/reportes/constructor/${r.id}/publicado`, {}, { preserveScroll: true });
}

function eliminar(r: Reporte): void {
    if (!confirm(`¿Eliminar «${r.nombre}»? Sus corridas siguen en la bitácora.`)) {
        return;
    }

    router.delete(`/reportes/constructor/${r.id}`, { preserveScroll: true });
}
</script>

<template>
    <Head title="Constructor de reportes" />

    <AppLayout titulo="Constructor de reportes">
        <section class="tarjeta mb-4 p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <h2 class="font-semibold">Reportes de la escuela</h2>
                    <!--
                        Qué es esto y qué NO es. Va aquí y no en un docblock:
                        «un campo donde escribir la consulta» es lo primero que
                        se pide en la primera demo.
                    -->
                    <p class="mt-1 max-w-3xl text-sm" :style="{ color: 'var(--color-suave)' }">
                        Un reporte de la escuela se arma sobre una <strong>fuente</strong> que ya existe:
                        eliges qué columnas trae, en qué orden, y qué filtros quedan fijos. Lo que cambia
                        entre una versión y otra —columnas, encabezado, orden— es lo que se configura aquí.
                        La consulta en sí la escribe el equipo de desarrollo como una fuente nueva:
                        <strong>no hay un campo donde escribir SQL</strong>, y no lo va a haber, porque
                        cualquiera con este permiso podría leer la base entera de la escuela.
                    </p>
                </div>

                <BotonAccion variante="nuevo" texto="Nuevo reporte" @click="abrirNuevo" />
            </div>
        </section>

        <!-- El formulario, desplegable. -->
        <section v-if="abierto" class="tarjeta mb-4 p-5">
            <form class="space-y-5" @submit.prevent="guardar">
                <div class="grid gap-4 sm:grid-cols-2">
                    <CampoTexto v-model="formulario.nombre" etiqueta="Nombre" requerido :error="formulario.errors.nombre" />

                    <CampoSelect
                        v-model="formulario.fuente"
                        etiqueta="Fuente"
                        :opciones="fuentes.map((f) => ({ valor: f.clave, texto: f.titulo }))"
                        vacio="Elige de dónde salen los datos…"
                        requerido
                        :deshabilitado="editando !== null"
                        :ayuda="editando ? 'La fuente no se cambia: sus columnas y filtros son de ella. Para otra pregunta, crea otro reporte.' : fuente?.grano"
                        :error="formulario.errors.fuente"
                    />
                </div>

                <CampoTextarea
                    v-model="formulario.descripcion"
                    etiqueta="Descripción"
                    requerido
                    :maximo="500"
                    ayuda="Qué contesta y qué NO. Es lo que se lee en el índice antes de correrlo."
                    :error="formulario.errors.descripcion"
                />

                <CampoSelect
                    v-model="formulario.area_sugerida"
                    etiqueta="Área"
                    :opciones="areas"
                    ayuda="Sólo dice en qué carpeta del índice aparece. No cambia quién puede verlo: eso lo decide el permiso de la fuente."
                />

                <template v-if="fuente">
                    <!-- COLUMNAS -->
                    <div class="grid gap-4 lg:grid-cols-2">
                        <div>
                            <p class="mb-2 text-sm font-medium">Columnas disponibles</p>
                            <div class="max-h-72 overflow-y-auto rounded-lg border border-borde p-2">
                                <label
                                    v-for="c in fuente.columnas"
                                    :key="c.clave"
                                    class="flex cursor-pointer items-start gap-2 rounded px-2 py-1.5 text-sm hover:bg-[color-mix(in_srgb,var(--color-suave)_8%,transparent)]"
                                >
                                    <input
                                        type="checkbox"
                                        class="mt-0.5 h-4 w-4 rounded border-borde"
                                        :checked="formulario.columnas.includes(c.clave)"
                                        @change="alternarColumna(c.clave)"
                                    />
                                    <span class="min-w-0">
                                        {{ c.etiqueta }}
                                        <!-- Una columna sensible pide un permiso extra: quien
                                             no lo tenga la verá omitida, no el reporte roto. -->
                                        <span v-if="c.sensible" class="ml-1 text-xs" :style="{ color: '#b45309' }">· sensible</span>
                                        <span v-if="c.ayuda" class="block text-xs" :style="{ color: 'var(--color-suave)' }">{{ c.ayuda }}</span>
                                    </span>
                                </label>
                            </div>
                        </div>

                        <div>
                            <p class="mb-2 text-sm font-medium">
                                En el reporte
                                <span class="font-normal" :style="{ color: 'var(--color-suave)' }">· en este orden</span>
                            </p>
                            <p v-if="!formulario.columnas.length" class="rounded-lg border border-dashed border-borde px-3 py-6 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                                Todavía no eliges ninguna columna.
                            </p>
                            <ul v-else class="space-y-1">
                                <li
                                    v-for="(clave, i) in formulario.columnas"
                                    :key="clave"
                                    class="flex items-center gap-2 rounded-lg border border-borde px-3 py-1.5 text-sm"
                                >
                                    <span class="w-5 shrink-0 text-xs tabular-nums" :style="{ color: 'var(--color-suave)' }">{{ i + 1 }}</span>
                                    <span class="min-w-0 flex-1 truncate">{{ etiquetaDe(clave) }}</span>
                                    <button type="button" class="px-1 text-xs" :disabled="i === 0" :class="i === 0 ? 'opacity-30' : ''" title="Subir" @click="mover(i, -1)">↑</button>
                                    <button type="button" class="px-1 text-xs" :disabled="i === formulario.columnas.length - 1" :class="i === formulario.columnas.length - 1 ? 'opacity-30' : ''" title="Bajar" @click="mover(i, 1)">↓</button>
                                    <button type="button" class="px-1 text-xs" title="Quitar" @click="alternarColumna(clave)">×</button>
                                </li>
                            </ul>
                            <p v-if="formulario.errors.columnas" class="mt-1 text-xs text-red-600">{{ formulario.errors.columnas }}</p>
                        </div>
                    </div>

                    <!-- ORDEN -->
                    <div class="grid gap-4 sm:grid-cols-2">
                        <CampoSelect
                            v-model="formulario.orden_por"
                            etiqueta="Ordenar por"
                            :opciones="ordenables"
                            vacio="Sin orden propio"
                            ayuda="Sólo las columnas que se pueden ordenar, y que estén en el reporte."
                            :error="formulario.errors.orden_por"
                        />
                        <CampoSelect
                            v-model="formulario.orden_dir"
                            etiqueta="Dirección"
                            :opciones="[{ valor: 'asc', texto: 'Ascendente' }, { valor: 'desc', texto: 'Descendente' }]"
                            :deshabilitado="formulario.orden_por === ''"
                        />
                    </div>

                    <!-- FILTROS -->
                    <div v-if="fuente.filtros.length">
                        <p class="mb-1 text-sm font-medium">Filtros</p>
                        <p class="mb-3 text-xs" :style="{ color: 'var(--color-suave)' }">
                            Un filtro <strong>fijo</strong> lo pone el reporte y quien lo corre no lo puede cambiar:
                            es lo que hace que conteste su pregunta y no otra. Uno <strong>obligatorio</strong> hay que
                            elegirlo para poder correrlo, y sirve para que no barra la escuela entera.
                        </p>

                        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            <div v-for="f in fuente.filtros" :key="f.clave" class="rounded-lg border border-borde p-3">
                                <p class="mb-1 text-xs font-medium">{{ f.etiqueta }}</p>

                                <CampoFiltroReporte
                                    v-model="formulario.filtros_fijos[f.clave]"
                                    :tipo="f.tipo"
                                    :opciones="f.opciones"
                                    vacio="Sin fijar"
                                />

                                <label
                                    class="mt-2 flex items-center gap-2 text-xs"
                                    :class="esFijo(f.clave) ? 'opacity-50' : 'cursor-pointer'"
                                >
                                    <input
                                        type="checkbox"
                                        class="h-3.5 w-3.5 rounded border-borde"
                                        :disabled="esFijo(f.clave)"
                                        :checked="formulario.filtros_obligatorios.includes(f.clave)"
                                        @change="alternarObligatorio(f.clave)"
                                    />
                                    <span>Hay que elegirlo para correr el reporte</span>
                                </label>

                                <p v-if="f.ayuda" class="mt-1 text-xs" :style="{ color: 'var(--color-suave)' }">{{ f.ayuda }}</p>
                            </div>
                        </div>
                    </div>
                </template>

                <label class="flex cursor-pointer items-center gap-2 text-sm">
                    <input v-model="formulario.publicado" type="checkbox" class="h-4 w-4 rounded border-borde" />
                    <span>
                        Publicado
                        <span class="text-xs" :style="{ color: 'var(--color-suave)' }">
                            · sin esto no aparece en el índice de nadie
                        </span>
                    </span>
                </label>

                <div class="flex items-center gap-2">
                    <BotonPrincipal :texto="editando ? 'Guardar cambios' : 'Crear reporte'" :procesando="formulario.processing" />
                    <BotonAccion variante="cerrar" texto="Cancelar" @click="cerrar" />
                </div>
            </form>
        </section>

        <!-- LISTADO -->
        <section class="tarjeta overflow-hidden">
            <div class="overflow-x-auto">
                <table v-if="reportes.length" class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-[11px] uppercase tracking-wider" :style="{ color: 'var(--color-suave)', backgroundColor: 'color-mix(in srgb, var(--color-suave) 6%, transparent)' }">
                            <th class="px-6 py-3 font-semibold">Reporte</th>
                            <th class="px-4 py-3 font-semibold">Fuente</th>
                            <th class="px-4 py-3 font-semibold text-center">Columnas</th>
                            <th class="px-4 py-3 font-semibold">Estado</th>
                            <th class="px-6 py-3 font-semibold text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in reportes" :key="r.id" class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
                            <td class="px-6 py-4">
                                <span class="font-semibold text-contenido">{{ r.nombre }}</span>
                                <span class="mt-0.5 block max-w-md text-xs" :style="{ color: 'var(--color-suave)' }">{{ r.descripcion }}</span>
                                <!--
                                    Un reporte que ya no casa con su fuente se
                                    RETIRA del índice con su razón escrita, en
                                    vez de desaparecer: uno que desaparece sin
                                    decir por qué se vuelve a armar igual de roto.
                                -->
                                <span v-if="r.problema" class="mt-1 block text-xs" :style="{ color: '#b45309' }">
                                    ⚠ No se está sirviendo: {{ r.problema }}
                                </span>
                            </td>
                            <td class="px-4 py-4" :style="{ color: 'var(--color-suave)' }">{{ r.fuenteTitulo }}</td>
                            <td class="px-4 py-4 text-center tabular-nums">{{ r.columnas.length }}</td>
                            <td class="px-4 py-4">
                                <PildoraEstado
                                    :texto="r.publicado ? 'Publicado' : 'Borrador'"
                                    :color="r.publicado ? '#16a34a' : 'var(--color-suave)'"
                                />
                            </td>
                            <td class="px-6 py-4">
                                <div class="flex justify-end">
                                    <MenuAcciones
                                        :opciones="[
                                            {
                                                variante: 'editar',
                                                clave: 'editar',
                                                deshabilitado: !r.editable,
                                                motivo: r.editable ? undefined : 'Su fuente no está a tu alcance: lo editarías a ciegas',
                                            },
                                            {
                                                variante: r.publicado ? 'cerrar' : 'ver',
                                                clave: 'publicado',
                                                texto: r.publicado ? 'Retirar del índice' : 'Publicar',
                                            },
                                            { variante: 'eliminar', clave: 'eliminar' },
                                        ]"
                                        @elegir="(que) => que === 'editar' ? abrirEdicion(r) : que === 'publicado' ? alternarPublicado(r) : eliminar(r)"
                                    />
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <p v-else class="px-6 py-12 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                    Todavía no hay reportes armados por la escuela.
                </p>
            </div>
        </section>
    </AppLayout>
</template>
