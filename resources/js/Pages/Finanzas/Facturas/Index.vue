<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BarraListado from '@/Components/BarraListado.vue';
import Paginacion from '@/Components/Paginacion.vue';
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
    borrador: 'text-slate-600 bg-slate-100',
    timbrando: 'text-blue-700 bg-blue-50',
    timbrada: 'text-emerald-700 bg-emerald-50',
    error: 'text-red-700 bg-red-50',
    cancelada: 'text-violet-700 bg-violet-50',
};
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
        />

        <!-- Cuadrícula -->
        <template v-if="vista === 'cuadricula'">
            <section v-if="facturas.data.length" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
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
                    <thead class="text-left text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">
                        <tr>
                            <th class="px-6 py-3 font-medium">Folio fiscal</th>
                            <th class="px-4 py-3 font-medium">Receptor</th>
                            <th class="px-4 py-3 font-medium">Alumno</th>
                            <th class="px-4 py-3 text-right font-medium">Total</th>
                            <th class="px-4 py-3 font-medium">Timbrado</th>
                            <th class="px-4 py-3 font-medium">Estatus</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="f in facturas.data" :key="f.id" class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
                            <td class="px-6 py-3 font-mono text-xs">
                                {{ f.uuid ?? '—' }}
                            </td>
                            <td class="px-4 py-3">
                                <span class="font-medium">{{ f.receptor_razon_social }}</span>
                                <span class="block font-mono text-xs" :style="{ color: 'var(--color-suave)' }">
                                    {{ f.receptor_rfc }}
                                </span>
                            </td>
                            <td class="px-4 py-3" :style="{ color: 'var(--color-suave)' }">
                                {{ f.alumno ?? '—' }}
                                <span v-if="f.matricula" class="block font-mono text-xs">{{ f.matricula }}</span>
                            </td>
                            <td class="px-4 py-3 text-right font-medium tabular-nums">{{ pesos.format(f.total) }}</td>
                            <td class="px-4 py-3 tabular-nums" :style="{ color: 'var(--color-suave)' }">
                                {{ f.fecha_timbrado ?? '—' }}
                            </td>
                            <td class="px-4 py-3">
                                <span class="rounded px-2 py-0.5 text-xs font-medium" :class="colorEstatus[f.estatus] ?? ''">
                                    {{ f.estatus }}
                                </span>
                            </td>
                            <td class="px-6 py-3 text-right">
                                <a :href="`/finanzas/facturas/${f.id}`" class="text-sm font-medium" :style="{ color: 'var(--color-acento)' }">
                                    Ver
                                </a>
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
