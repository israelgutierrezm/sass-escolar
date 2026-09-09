<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import BarraListado from '@/Components/BarraListado.vue';
import Paginacion from '@/Components/Paginacion.vue';
import PildoraEstado from '@/Components/PildoraEstado.vue';
import TarjetaListado from '@/Components/TarjetaListado.vue';

interface Fila {
    id: number;
    uuid: string | null;
    tipo: string;
    estatus: string;
    discrepancia_sat: string | null;
    receptor_rfc: string;
    receptor_razon_social: string;
    total: number;
    fecha_timbrado: string | null;
    matricula: string | null;
    alumno: string | null;
    es_global: boolean;
}

const props = defineProps<{
    facturas: {
        data: Fila[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
        from: number | null;
        to: number | null;
    };
    filtros: { estatus: string; discrepancia: boolean };
    estatus: string[];
    discrepancias: number;
    /** Factura global: razones sociales, periodicidades y la previsualización. */
    emisoresGlobal: { id: number; razon_social: string; rfc: string }[];
    periodicidadesGlobal: { clave: string; texto: string }[];
    periodicidadDefault: string;
    previsualizacionGlobal: { emisor_id: number; mes: number; anio: number; pagos: number; total: number; desde: string; hasta: string } | null;
}>();

const vista = ref<'lista' | 'cuadricula'>('lista');

const definicionFiltros = [
    { clave: 'estatus', etiqueta: 'Estatus', opciones: props.estatus.map((e) => ({ valor: e, texto: e })) },
    // Booleano y no una opción más de «estatus»: no es un estado nuestro, es que
    // el nuestro y el del SAT no coinciden.
    { clave: 'discrepancia', etiqueta: 'Solo las que no coinciden con el SAT', tipo: 'booleano' as const },
];

const pesos = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' });

/*
 * El paquete del mes.
 *
 * Va como enlace y no como formulario POST porque es una LECTURA: se puede
 * repetir, compartir y guardar en favoritos, y el navegador la descarga sin
 * salir de la pantalla.
 *
 * Arranca en el mes pasado completo, que es el que se entrega a contabilidad:
 * el mes en curso todavía tiene facturas por emitir y bajarlo entero sería
 * bajarlo dos veces.
 */
const hoy = new Date();
const mesPasado = new Date(hoy.getFullYear(), hoy.getMonth() - 1, 1);
const finMesPasado = new Date(hoy.getFullYear(), hoy.getMonth(), 0);

const aIso = (d: Date) =>
    `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;

const paquete = ref({ desde: aIso(mesPasado), hasta: aIso(finMesPasado), conPdf: false });

const urlPaquete = computed(() => {
    const p = new URLSearchParams({ desde: paquete.value.desde, hasta: paquete.value.hasta });
    if (paquete.value.conPdf) p.set('con_pdf', '1');

    return `/finanzas/facturas/descargar-lote?${p.toString()}`;
});

const descargando = ref(false);

/*
 * La factura GLOBAL de un periodo: agrupa lo cobrado sin factura nominativa en
 * un CFDI al público en general. Arranca en el mes pasado, que es el que ya se
 * puede cerrar. La previsualización viaja por la URL (es una lectura); emitir es
 * un POST y hace el corte.
 */
const mesesNombre = [
    { v: 1, t: 'Enero' }, { v: 2, t: 'Febrero' }, { v: 3, t: 'Marzo' }, { v: 4, t: 'Abril' },
    { v: 5, t: 'Mayo' }, { v: 6, t: 'Junio' }, { v: 7, t: 'Julio' }, { v: 8, t: 'Agosto' },
    { v: 9, t: 'Septiembre' }, { v: 10, t: 'Octubre' }, { v: 11, t: 'Noviembre' }, { v: 12, t: 'Diciembre' },
];
const mostrarGlobal = ref(false);
const global = ref({
    emisor_id: props.emisoresGlobal[0]?.id ?? null,
    periodicidad: props.periodicidadDefault || '04',
    mes: mesPasado.getMonth() + 1,
    anio: mesPasado.getFullYear(),
});

function verGlobal(): void {
    router.get('/finanzas/facturas', {
        ...props.filtros,
        global_emisor: global.value.emisor_id,
        global_mes: global.value.mes,
        global_anio: global.value.anio,
    }, { preserveScroll: true, preserveState: true, only: ['previsualizacionGlobal'] });
}

const emitiendoGlobal = ref(false);
function emitirGlobal(): void {
    emitiendoGlobal.value = true;
    router.post('/finanzas/facturas/global', global.value, {
        onFinish: () => { emitiendoGlobal.value = false; },
    });
}

/** La previsualización corresponde al periodo que se está mirando. */
const previewVigente = computed(() =>
    props.previsualizacionGlobal
    && props.previsualizacionGlobal.emisor_id === global.value.emisor_id
    && props.previsualizacionGlobal.mes === global.value.mes
    && props.previsualizacionGlobal.anio === global.value.anio,
);

const colorEstatus: Record<string, string> = {
    borrador: 'text-suave bg-fondo',
    timbrando: 'text-blue-700 bg-blue-50',
    timbrada: 'text-emerald-700 bg-emerald-50',
    error: 'text-red-700 bg-red-50',
    cancelada: 'text-violet-700 bg-violet-50',
};

// Color SÓLIDO por estatus para la PildoraEstado de la tabla.
const colorEstatusSolido: Record<string, string> = {
    borrador: 'var(--color-suave)',
    timbrando: '#2563eb',
    timbrada: '#16a34a',
    error: '#dc2626',
    cancelada: '#7c3aed',
};

const ICONO_FACTURA =
    'M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 0 0 2.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 0 0-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25Z';
</script>

<template>
    <Head title="Facturas" />

    <AppLayout titulo="Facturación electrónica">
        <p class="max-w-2xl text-sm" :style="{ color: 'var(--color-suave)' }">
            Los CFDI se emiten contra PAGOS cobrados, no contra adeudos: el comprobante ampara
            dinero que entró. Una factura timbrada no se edita — corregirla es cancelarla y emitir
            otra, y las dos quedan.
        </p>

        <BarraListado
            v-model:vista="vista"
            url="/finanzas/facturas"
            vista-clave="finanzas.facturas"
            sin-buscador
            :valores="filtros"
            :filtros="definicionFiltros"
            titulo="Facturas"
            descripcion="CFDI emitidos"
            :icono="ICONO_FACTURA"
        >
            <template #conteo>
                <span class="rounded-full px-3 py-1 text-xs font-medium" :style="{ backgroundColor: 'color-mix(in srgb, var(--color-acento) 12%, transparent)', color: 'var(--color-acento)' }">
                    {{ facturas.total }} en total
                </span>
                <!--
                    El cero se calla: una cifra en cero ocupa el sitio de un dato
                    útil y entrena a ignorar la que sí importa. Y el conteo es de
                    TODAS, no de esta página: una discrepancia en la página 4
                    sería invisible.
                -->
                <a
                    v-if="discrepancias > 0"
                    href="/finanzas/facturas?discrepancia=1"
                    class="rounded-full px-3 py-1 text-xs font-medium"
                    :style="{ backgroundColor: 'color-mix(in srgb, #dc2626 12%, transparent)', color: '#dc2626' }"
                >
                    {{ discrepancias }} {{ discrepancias === 1 ? 'no coincide' : 'no coinciden' }} con el SAT
                </a>
            </template>
        </BarraListado>

        <!-- La factura global del periodo. Se despliega como el paquete mensual. -->
        <section class="tarjeta mb-4 p-4">
            <button
                type="button"
                class="flex w-full items-center justify-between gap-3 text-left text-sm font-medium"
                @click="mostrarGlobal = !mostrarGlobal"
            >
                <span>Factura global del periodo
                    <span class="font-normal" :style="{ color: 'var(--color-suave)' }">— lo cobrado sin factura nominativa, al público en general</span>
                </span>
                <span :style="{ color: 'var(--color-suave)' }">{{ mostrarGlobal ? '−' : '+' }}</span>
            </button>

            <div v-if="mostrarGlobal" class="mt-3">
                <p v-if="!emisoresGlobal.length" class="text-sm" :style="{ color: 'var(--color-suave)' }">
                    Da de alta una razón social en «Razones sociales» para poder emitir la global.
                </p>
                <template v-else>
                    <div class="grid gap-3 sm:grid-cols-4">
                        <label class="text-sm">
                            <span class="mb-1 block text-xs" :style="{ color: 'var(--color-suave)' }">Razón social</span>
                            <select v-model="global.emisor_id" class="w-full rounded-lg border bg-transparent px-3 py-1.5" :style="{ borderColor: 'var(--color-borde)' }">
                                <option v-for="e in emisoresGlobal" :key="e.id" :value="e.id">{{ e.razon_social }}</option>
                            </select>
                        </label>
                        <label class="text-sm">
                            <span class="mb-1 block text-xs" :style="{ color: 'var(--color-suave)' }">Mes</span>
                            <select v-model.number="global.mes" class="w-full rounded-lg border bg-transparent px-3 py-1.5" :style="{ borderColor: 'var(--color-borde)' }">
                                <option v-for="m in mesesNombre" :key="m.v" :value="m.v">{{ m.t }}</option>
                            </select>
                        </label>
                        <label class="text-sm">
                            <span class="mb-1 block text-xs" :style="{ color: 'var(--color-suave)' }">Año</span>
                            <input v-model.number="global.anio" type="number" class="w-full rounded-lg border bg-transparent px-3 py-1.5" :style="{ borderColor: 'var(--color-borde)' }" />
                        </label>
                        <label class="text-sm">
                            <span class="mb-1 block text-xs" :style="{ color: 'var(--color-suave)' }">Periodicidad</span>
                            <select v-model="global.periodicidad" class="w-full rounded-lg border bg-transparent px-3 py-1.5" :style="{ borderColor: 'var(--color-borde)' }">
                                <option v-for="p in periodicidadesGlobal" :key="p.clave" :value="p.clave">{{ p.texto }}</option>
                            </select>
                        </label>
                    </div>

                    <div class="mt-3 flex flex-wrap items-center gap-3">
                        <button type="button" class="rounded-lg border px-4 py-2 text-sm font-medium" :style="{ borderColor: 'var(--color-borde)' }" @click="verGlobal">
                            Ver qué incluye
                        </button>
                        <span v-if="previewVigente && previsualizacionGlobal" class="text-sm">
                            {{ previsualizacionGlobal.pagos }} {{ previsualizacionGlobal.pagos === 1 ? 'pago' : 'pagos' }} ·
                            <strong>{{ pesos.format(previsualizacionGlobal.total) }}</strong>
                        </span>
                        <button
                            v-if="previewVigente && previsualizacionGlobal && previsualizacionGlobal.pagos > 0"
                            type="button"
                            class="rounded-lg px-4 py-2 text-sm font-medium disabled:opacity-60"
                            :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                            :disabled="emitiendoGlobal"
                            @click="emitirGlobal"
                        >
                            {{ emitiendoGlobal ? 'Emitiendo…' : 'Emitir factura global' }}
                        </button>
                    </div>
                    <p class="mt-2 text-xs" :style="{ color: 'var(--color-suave)' }">
                        Emitirla hace el CORTE del periodo: esos pagos quedan facturados y ya no se pueden facturar a nombre del alumno.
                    </p>
                </template>
            </div>
        </section>

        <!--
            El paquete para contabilidad. Se despliega porque es mensual: tenerlo
            siempre abierto le quitaría sitio al listado, que es a lo que se
            entra todos los días.
        -->
        <section class="tarjeta mb-4 p-4">
            <button
                type="button"
                class="text-sm font-medium"
                :style="{ color: 'var(--color-acento)' }"
                @click="descargando = !descargando"
            >
                {{ descargando ? '−' : '+' }} Descargar los comprobantes de un periodo
            </button>

            <div v-if="descargando" class="mt-3">
                <p class="text-sm" :style="{ color: 'var(--color-suave)' }">
                    Un ZIP con los XML del periodo y un <strong>manifiesto</strong> que los lista con su
                    estado. Van también las canceladas —la contabilidad electrónica las pide— y el
                    manifiesto dice cuáles lo están y a cuáles les falta el XML.
                </p>

                <div class="mt-3 flex flex-wrap items-end gap-3">
                    <label class="text-sm">
                        <span class="mb-1 block font-medium">Desde</span>
                        <input
                            v-model="paquete.desde"
                            type="date"
                            class="rounded-lg border px-3 py-2 text-sm"
                            :style="{ borderColor: 'var(--color-borde)' }"
                        />
                    </label>
                    <label class="text-sm">
                        <span class="mb-1 block font-medium">Hasta</span>
                        <input
                            v-model="paquete.hasta"
                            type="date"
                            class="rounded-lg border px-3 py-2 text-sm"
                            :style="{ borderColor: 'var(--color-borde)' }"
                        />
                    </label>
                    <label class="flex items-center gap-2 pb-2 text-sm">
                        <input v-model="paquete.conPdf" type="checkbox" />
                        Incluir también los PDF
                    </label>
                    <a
                        :href="urlPaquete"
                        class="rounded-lg px-4 py-2 text-sm font-medium"
                        :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                    >
                        Descargar
                    </a>
                </div>
            </div>
        </section>

        <!-- Cuadrícula -->
        <template v-if="vista === 'cuadricula'">
            <section v-if="facturas.data.length" class="cuadricula-listado">
                <TarjetaListado
                    v-for="f in facturas.data"
                    :key="f.id"
                    :titulo="f.receptor_razon_social"
                    :clave="f.receptor_rfc"
                    :href="`/finanzas/facturas/${f.id}`"
                    :metas="[
                        { etiqueta: 'Alumno', valor: f.alumno },
                        {
                            etiqueta: f.tipo === 'E' ? 'Nota de crédito' : 'Total',
                            valor: (f.tipo === 'E' ? '−' : '') + pesos.format(f.total),
                        },
                        { etiqueta: 'Timbrado', valor: f.fecha_timbrado },
                        { etiqueta: 'Folio', valor: f.uuid },
                    ]"
                >
                    <template #insignia>
                        <span class="shrink-0 rounded px-2 py-0.5 text-xs font-medium" :class="colorEstatus[f.estatus] ?? ''">
                            {{ f.estatus }}
                        </span>
                    </template>
                </TarjetaListado>
            </section>

            <p v-else class="tarjeta px-6 py-10 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                No hay facturas que coincidan. Se emiten desde el estado de cuenta del alumno.
            </p>

            <section v-if="facturas.links.length > 3" class="tarjeta">
                <Paginacion :enlaces="facturas.links" :total="facturas.total" :desde="facturas.from" :hasta="facturas.to" />
            </section>
        </template>

        <!-- Lista -->
        <section v-else class="tarjeta overflow-hidden">
            <div class="overflow-x-auto">
                <table v-if="facturas.data.length" class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-[11px] uppercase tracking-wider" :style="{ color: 'var(--color-suave)', backgroundColor: 'color-mix(in srgb, var(--color-suave) 6%, transparent)' }">
                            <th class="px-6 py-3 font-semibold">Receptor</th>
                            <th class="px-4 py-3 font-semibold">Alumno</th>
                            <th class="px-4 py-3 font-semibold">Folio fiscal</th>
                            <th class="px-4 py-3 text-right font-semibold">Total</th>
                            <th class="px-4 py-3 font-semibold">Estatus</th>
                            <th class="px-6 py-3 font-semibold text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="f in facturas.data" :key="f.id" class="fila-nueva border-t transition-colors" :style="{ borderColor: 'var(--color-borde)' }">
                            <!-- Receptor -->
                            <td class="px-6 py-4">
                                <span class="block font-semibold text-contenido">{{ f.receptor_razon_social }}</span>
                                <span class="mt-0.5 block font-mono text-[11px]" :style="{ color: 'var(--color-suave)' }">{{ f.receptor_rfc }}</span>
                            </td>
                            <td class="px-4 py-4" :style="{ color: 'var(--color-suave)' }">
                                {{ f.alumno ?? '—' }}
                                <span v-if="f.matricula" class="block font-mono text-[11px]">{{ f.matricula }}</span>
                            </td>
                            <td class="px-4 py-4 font-mono text-[11px]" :style="{ color: 'var(--color-suave)' }">
                                {{ f.uuid ?? '—' }}
                                <span v-if="f.fecha_timbrado" class="mt-0.5 block tabular-nums">{{ f.fecha_timbrado }}</span>
                            </td>
                            <td class="px-4 py-4 text-right font-semibold tabular-nums">
                                <!--
                                    El importe de una nota de crédito RESTA. Sin
                                    decirlo, quien suma la columna a ojo cuenta
                                    como ingreso un documento que lo reduce, y
                                    dos comprobantes idénticos en la lista dicen
                                    cosas opuestas.
                                -->
                                <span v-if="f.tipo === 'E'">−{{ pesos.format(f.total) }}</span>
                                <span v-else>{{ pesos.format(f.total) }}</span>
                                <span
                                    v-if="f.tipo === 'E'"
                                    class="mt-0.5 block whitespace-nowrap text-[11px] font-medium"
                                    :style="{ color: 'var(--color-suave)' }"
                                >Nota de crédito</span>
                            </td>
                            <td class="px-4 py-4">
                                <PildoraEstado :texto="f.estatus" :color="colorEstatusSolido[f.estatus] ?? 'var(--color-suave)'" />
                                <!--
                                    La píldora dice lo que creemos nosotros. Si el
                                    SAT dice otra cosa, sin esta marca el renglón
                                    se lee como si estuviera en orden.
                                -->
                                <span
                                    v-if="f.discrepancia_sat"
                                    class="mt-1 block whitespace-nowrap text-[11px] font-medium"
                                    :style="{ color: '#dc2626' }"
                                    :title="f.discrepancia_sat"
                                >No coincide con el SAT</span>
                            </td>
                            <td class="px-6 py-4">
                                <div class="flex justify-end">
                                    <BotonAccion variante="ver" solo-icono :href="`/finanzas/facturas/${f.id}`" />
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <p v-else class="px-6 py-10 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                    No hay facturas que coincidan. Se emiten desde el estado de cuenta del alumno.
                </p>
            </div>

            <Paginacion :enlaces="facturas.links" :total="facturas.total" :desde="facturas.from" :hasta="facturas.to" />
        </section>
    </AppLayout>
</template>

<style scoped>
.fila-nueva:hover {
    background-color: color-mix(in srgb, var(--color-acento) 5%, transparent);
}
</style>
