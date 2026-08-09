<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import BarraListado from '@/Components/BarraListado.vue';
import Paginacion from '@/Components/Paginacion.vue';
import PildoraEstado from '@/Components/PildoraEstado.vue';
import TarjetaListado from '@/Components/TarjetaListado.vue';

interface Fila {
    id: number;
    uuid: string | null;
    estatus: string;
    receptor_rfc: string;
    receptor_razon_social: string;
    total: number;
    fecha_timbrado: string | null;
    matricula: string | null;
    alumno: string | null;
}

const props = defineProps<{
    facturas: {
        data: Fila[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
        from: number | null;
        to: number | null;
    };
    filtros: { estatus: string };
    estatus: string[];
}>();

const vista = ref<'lista' | 'cuadricula'>('lista');

const definicionFiltros = [
    { clave: 'estatus', etiqueta: 'Estatus', opciones: props.estatus.map((e) => ({ valor: e, texto: e })) },
];

const pesos = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' });

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
            </template>
        </BarraListado>

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
                        { etiqueta: 'Total', valor: pesos.format(f.total) },
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
                            <td class="px-4 py-4 text-right font-semibold tabular-nums">{{ pesos.format(f.total) }}</td>
                            <td class="px-4 py-4">
                                <PildoraEstado :texto="f.estatus" :color="colorEstatusSolido[f.estatus] ?? 'var(--color-suave)'" />
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
