<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Paginacion from '@/Components/Paginacion.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';

interface Columna {
    clave: string;
    etiqueta: string;
    alineacion: string;
    ordenable: boolean;
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
    ms: number;
}>();

const eligiendo = ref(false);
const valores = ref<Record<string, unknown>>({ ...props.aplicados });
const elegidas = ref<string[]>(props.columnas.map((c) => c.clave));

/** Recarga con lo que hay en pantalla. La URL lleva todo: el resultado es enlazable. */
function aplicar(): void {
    router.get(
        `/reportes/${props.reporte.clave}`,
        { filtros: valores.value, columnas: elegidas.value },
        { preserveState: true, preserveScroll: true },
    );
}

function alternarColumna(clave: string): void {
    elegidas.value = elegidas.value.includes(clave)
        ? elegidas.value.filter((c) => c !== clave)
        : [...elegidas.value, clave];
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

                    <select
                        v-if="f.tipo === 'lista_multiple'"
                        multiple
                        class="h-20 w-full rounded-lg border border-borde px-2 py-1 text-xs"
                        :disabled="filtrosFijos.includes(f.clave)"
                        @change="valores[f.clave] = Array.from(($event.target as HTMLSelectElement).selectedOptions).map((o) => o.value)"
                    >
                        <option v-for="(etiqueta, valor) in f.opciones" :key="valor" :value="valor">{{ etiqueta }}</option>
                    </select>

                    <input
                        v-else
                        :type="f.tipo === 'fecha' ? 'date' : 'text'"
                        class="w-full rounded-lg border border-borde px-2 py-1.5 text-sm"
                        :disabled="filtrosFijos.includes(f.clave)"
                        @input="valores[f.clave] = ($event.target as HTMLInputElement).value"
                    />

                    <p v-if="f.ayuda" class="mt-0.5 text-xs" :style="{ color: 'var(--color-suave)' }">{{ f.ayuda }}</p>
                </div>
            </div>

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
                            >{{ c.etiqueta }}</th>
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
                            >{{ fila[c.clave] ?? '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p v-if="!filas.length" class="px-6 py-8 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                Ninguna fila cumple con lo que pediste.
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
