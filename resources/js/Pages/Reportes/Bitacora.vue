<script setup lang="ts">
import { computed, reactive, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import Paginacion from '@/Components/Paginacion.vue';
import PildoraEstado from '@/Components/PildoraEstado.vue';

interface FiltroAplicado {
    etiqueta: string;
    valor: string;
}

interface Ejecucion {
    id: number;
    momento: string | null;
    reporte: string;
    titulo: string | null;
    persona: string | null;
    formato: string;
    filas: number;
    milisegundos: number;
    filtros: FiltroAplicado[];
    columnas: number;
    omitidas: string[];
}

interface Pagina {
    data: Ejecucion[];
    links: { url: string | null; label: string; active: boolean }[];
    total: number;
    from: number | null;
    to: number | null;
}

const props = defineProps<{
    ejecuciones: Pagina;
    filtros: {
        reporte: string | null;
        formato: string | null;
        persona: string | null;
        desde: string | null;
        hasta: string | null;
    };
    reportes: { clave: string; titulo: string }[];
    formatos: string[];
    resumen: {
        ejecuciones: number;
        personas: number;
        descargas: number;
        filas_descargadas: number;
        mas_lento: number | null;
    };
}>();

const criterios = reactive({ ...props.filtros });

/*
 * Se pide al servidor cuando cambia un filtro, con `replace` para no llenar el
 * historial del navegador de estados intermedios: quien vuelve atrás desde aquí
 * quiere salir de la bitácora, no deshacer siete filtros de uno en uno.
 */
let temporizador: ReturnType<typeof setTimeout> | undefined;

watch(criterios, () => {
    clearTimeout(temporizador);
    temporizador = setTimeout(() => {
        router.get('/reportes/bitacora', { ...criterios }, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    }, 300);
});

const hayFiltro = computed(() => Object.values(criterios).some((v) => v !== null && v !== ''));

function limpiar(): void {
    criterios.reporte = null;
    criterios.formato = null;
    criterios.persona = null;
    criterios.desde = null;
    criterios.hasta = null;
}

/** Fecha y hora en el reloj de quien mira, que es el que usa para investigar. */
function cuando(iso: string | null): string {
    if (!iso) return '—';

    return new Date(iso).toLocaleString('es-MX', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

const MILES = new Intl.NumberFormat('es-MX');

/*
 * La DESCARGA se distingue de la pantalla con el color, no sólo con la palabra.
 * Es la diferencia que importa al auditar: un archivo sale de la escuela y se
 * reenvía; una pantalla se mira y se cierra.
 */
/** Lo que es MIRAR. Tiene que decir lo mismo que `Ejecutor::FORMATOS_DE_PANTALLA`. */
const DE_PANTALLA = ['pantalla', 'agrupado'];

function colorDelFormato(formato: string): string | undefined {
    return DE_PANTALLA.includes(formato) ? undefined : '#b45309';
}

/** Milisegundos como se leen: 340 ms, 2.4 s. */
function duracion(ms: number): string {
    return ms >= 1000 ? `${(ms / 1000).toFixed(1)} s` : `${ms} ms`;
}
</script>

<template>
    <Head title="Uso de los reportes" />

    <AppLayout titulo="Uso de los reportes">
        <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
            <p class="max-w-3xl text-sm" :style="{ color: 'var(--color-suave)' }">
                Quién corrió cada reporte, con qué filtros y cuántas filas se llevó.
                <strong>No guarda los datos</strong>: lo que se registra es lo que se pidió, nunca lo que
                salió, así que desde aquí no se ve la información de ningún reporte.
            </p>

            <Link
                href="/reportes"
                class="rounded-lg border border-borde px-3 py-1.5 text-sm hover:bg-slate-50"
            >Volver a Reportes</Link>
        </div>

        <!-- El resumen habla de LO FILTRADO, y lo dice, para que nadie cite la
             cifra creyendo que es la de toda la escuela. -->
        <div class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
            <div
                v-for="dato in [
                    { etiqueta: 'Ejecuciones', valor: MILES.format(resumen.ejecuciones) },
                    { etiqueta: 'Personas distintas', valor: MILES.format(resumen.personas) },
                    { etiqueta: 'Descargas', valor: MILES.format(resumen.descargas) },
                    { etiqueta: 'Filas descargadas', valor: MILES.format(resumen.filas_descargadas) },
                    { etiqueta: 'La más lenta', valor: resumen.mas_lento === null ? '—' : duracion(resumen.mas_lento) },
                ]"
                :key="dato.etiqueta"
                class="rounded-xl border p-3"
                :style="{ borderColor: 'var(--color-borde)', background: 'var(--color-superficie)' }"
            >
                <p class="text-xs" :style="{ color: 'var(--color-suave)' }">{{ dato.etiqueta }}</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums">{{ dato.valor }}</p>
            </div>
        </div>

        <TarjetaSeccion titulo="Bitácora" sin-relleno>
            <template #insignia>
                <button
                    v-if="hayFiltro"
                    type="button"
                    class="rounded-lg border border-borde px-3 py-1.5 text-sm hover:bg-slate-50"
                    @click="limpiar"
                >Quitar filtros</button>
            </template>

            <div class="grid gap-3 border-b px-6 py-4 sm:grid-cols-2 xl:grid-cols-5" :style="{ borderColor: 'var(--color-borde)' }">
                <CampoSelect
                    v-model="criterios.reporte"
                    etiqueta="Reporte"
                    vacio="Todos"
                    :opciones="props.reportes.map((r) => ({ valor: r.clave, texto: r.titulo }))"
                />
                <CampoSelect
                    v-model="criterios.formato"
                    etiqueta="Formato"
                    vacio="Todos"
                    :opciones="props.formatos.map((f) => ({ valor: f, texto: f }))"
                />
                <CampoTexto v-model="criterios.persona" etiqueta="Quién" marcador="Parte del nombre" />
                <CampoTexto v-model="criterios.desde" etiqueta="Desde" tipo="date" />
                <CampoTexto v-model="criterios.hasta" etiqueta="Hasta" tipo="date" />
            </div>

            <!-- Ancho mínimo y desplazamiento propio: con `overflow-hidden` la
                 tabla RECORTA en vez de desplazar, que es el defecto que este
                 proyecto ya corrigió en los lotes de certificación. -->
            <div class="overflow-x-auto">
                <table class="w-full min-w-[64rem] text-sm">
                    <thead>
                        <tr class="border-b text-left" :style="{ borderColor: 'var(--color-borde)', color: 'var(--color-suave)' }">
                            <th class="px-6 py-2 font-medium">Cuándo</th>
                            <th class="px-3 py-2 font-medium">Quién</th>
                            <th class="px-3 py-2 font-medium">Reporte</th>
                            <th class="px-3 py-2 font-medium">Formato</th>
                            <th class="px-3 py-2 text-right font-medium">Filas</th>
                            <th class="px-3 py-2 text-right font-medium">Tardó</th>
                            <th class="px-6 py-2 font-medium">Con qué filtros</th>
                        </tr>
                    </thead>

                    <tbody>
                        <tr
                            v-for="e in ejecuciones.data"
                            :key="e.id"
                            class="border-b align-top"
                            :style="{ borderColor: 'var(--color-borde)' }"
                        >
                            <td class="whitespace-nowrap px-6 py-3 tabular-nums">{{ cuando(e.momento) }}</td>

                            <!-- Sin persona es una corrida sin sesión: un comando
                                 o una tarea programada. Se dice, no se deja en
                                 blanco, porque un hueco se lee como un fallo. -->
                            <td class="px-3 py-3">
                                <span v-if="e.persona">{{ e.persona }}</span>
                                <span v-else :style="{ color: 'var(--color-suave)' }">Sin sesión</span>
                            </td>

                            <td class="px-3 py-3">
                                <span v-if="e.titulo">{{ e.titulo }}</span>
                                <!-- Un reporte retirado deja sus ejecuciones
                                     atrás: se enseña su clave para poder
                                     investigarlas. -->
                                <span v-else class="font-mono text-xs" :style="{ color: 'var(--color-suave)' }">
                                    {{ e.reporte }}
                                </span>
                            </td>

                            <td class="px-3 py-3">
                                <PildoraEstado :texto="e.formato" :color="colorDelFormato(e.formato)" />
                            </td>

                            <td class="px-3 py-3 text-right tabular-nums">{{ MILES.format(e.filas) }}</td>
                            <td class="px-3 py-3 text-right tabular-nums">{{ duracion(e.milisegundos) }}</td>

                            <td class="px-6 py-3">
                                <span v-if="!e.filtros.length" :style="{ color: 'var(--color-suave)' }">
                                    Sin filtros
                                </span>

                                <ul v-else class="space-y-0.5">
                                    <li v-for="(f, i) in e.filtros" :key="i" class="text-xs">
                                        <span :style="{ color: 'var(--color-suave)' }">{{ f.etiqueta }}:</span>
                                        {{ f.valor }}
                                    </li>
                                </ul>

                                <!-- Lo que NO se llevó, y por qué: es la mitad
                                     que explica que dos corridas del mismo
                                     reporte trajeran distinto Excel. -->
                                <p v-if="e.omitidas.length" class="mt-1 text-xs" :style="{ color: '#b45309' }">
                                    Sin permiso para: {{ e.omitidas.join(', ') }}
                                </p>
                            </td>
                        </tr>

                        <tr v-if="!ejecuciones.data.length">
                            <td colspan="7" class="px-6 py-10 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                                <span v-if="hayFiltro">Ninguna ejecución con esos filtros.</span>
                                <span v-else>Todavía no ha corrido ningún reporte.</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <Paginacion
                :enlaces="ejecuciones.links"
                :total="ejecuciones.total"
                :desde="ejecuciones.from"
                :hasta="ejecuciones.to"
            />
        </TarjetaSeccion>
    </AppLayout>
</template>
