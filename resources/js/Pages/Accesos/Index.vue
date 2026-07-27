<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BarraListado from '@/Components/BarraListado.vue';
import Paginacion from '@/Components/Paginacion.vue';

interface Fila {
    id: number;
    persona: string;
    tipo: string;
    ip: string | null;
    navegador: string | null;
    equipo: string | null;
    momento: string | null;
}

const props = defineProps<{
    registro: {
        data: Fila[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
        from: number | null;
        to: number | null;
    };
    filtros: Record<string, any>;
    porDia: { dia: string; total: number }[];
    resumen: { entradas_hoy: number; entradas_semana: number; cuentas_semana: number };
}>();

const definicionFiltros = [
    {
        clave: 'tipo',
        etiqueta: 'Tipo',
        opciones: [
            { valor: 'entrada', texto: 'Entradas' },
            { valor: 'salida', texto: 'Salidas' },
            { valor: 'recuperacion_solicitada', texto: 'Recuperación pedida' },
            { valor: 'credenciales_enviadas', texto: 'Credenciales enviadas' },
        ],
    },
];

const etiquetaTipo: Record<string, string> = {
    entrada: 'Entrada',
    salida: 'Salida',
    recuperacion_solicitada: 'Recuperación pedida',
    recuperacion_completada: 'Recuperación completada',
    credenciales_enviadas: 'Credenciales enviadas',
};

const maximo = computed(() => Math.max(1, ...props.porDia.map((d) => d.total)));

function diaCorto(iso: string): string {
    const d = new Date(iso + 'T00:00:00');
    return d.toLocaleDateString('es-MX', { day: '2-digit', month: '2-digit' });
}

function colorTipo(tipo: string): string {
    if (tipo === 'entrada') {
        return 'color-mix(in srgb, #16a34a 16%, transparent)';
    }
    if (tipo === 'salida') {
        return 'var(--color-borde)';
    }
    return 'color-mix(in srgb, #d97706 18%, transparent)';
}
</script>

<template>
    <Head title="Accesos" />

    <AppLayout titulo="Bitácora de accesos">
        <!-- KPIs -->
        <section class="grid gap-4 sm:grid-cols-3">
            <div class="tarjeta p-5">
                <p class="text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">Entradas hoy</p>
                <p class="mt-1 text-2xl font-semibold">{{ resumen.entradas_hoy }}</p>
            </div>
            <div class="tarjeta p-5">
                <p class="text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">Entradas (7 días)</p>
                <p class="mt-1 text-2xl font-semibold">{{ resumen.entradas_semana }}</p>
            </div>
            <div class="tarjeta p-5">
                <p class="text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">Cuentas activas (7 días)</p>
                <p class="mt-1 text-2xl font-semibold">{{ resumen.cuentas_semana }}</p>
            </div>
        </section>

        <!-- Gráfica de entradas por día -->
        <section class="tarjeta p-5">
            <h2 class="text-sm font-semibold">Entradas por día (últimos 14 días)</h2>
            <div class="mt-4 flex items-end gap-2" style="height: 140px">
                <div v-for="d in porDia" :key="d.dia" class="flex flex-1 flex-col items-center justify-end gap-1">
                    <span class="text-xs tabular-nums" :style="{ color: 'var(--color-suave)' }">{{ d.total || '' }}</span>
                    <div
                        class="w-full rounded-t"
                        :style="{
                            height: `${Math.round((d.total / maximo) * 110)}px`,
                            minHeight: d.total ? '3px' : '0',
                            backgroundColor: 'var(--color-acento)',
                            opacity: d.total ? 1 : 0.15,
                        }"
                        :title="`${d.dia}: ${d.total}`"
                    />
                    <span class="text-[10px]" :style="{ color: 'var(--color-suave)' }">{{ diaCorto(d.dia) }}</span>
                </div>
            </div>
        </section>

        <BarraListado
            url="/plataforma/accesos"
            :valores="filtros"
            :filtros="definicionFiltros"
            placeholder="Buscar por nombre de la persona…"
        />

        <!-- Registro a detalle -->
        <div class="tarjeta overflow-hidden">
            <div class="overflow-x-auto">
                <table v-if="registro.data.length" class="w-full text-sm">
                    <thead class="text-left text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">
                        <tr>
                            <th class="px-4 py-3 font-medium">Persona</th>
                            <th class="px-4 py-3 font-medium">Movimiento</th>
                            <th class="px-4 py-3 font-medium">Fecha y hora</th>
                            <th class="px-4 py-3 font-medium">Equipo</th>
                            <th class="px-4 py-3 font-medium">Navegador</th>
                            <th class="px-4 py-3 font-medium">IP</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="f in registro.data" :key="f.id" class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
                            <td class="px-4 py-3 font-medium">{{ f.persona }}</td>
                            <td class="px-4 py-3">
                                <span class="rounded-full px-2 py-0.5 text-xs" :style="{ backgroundColor: colorTipo(f.tipo) }">
                                    {{ etiquetaTipo[f.tipo] ?? f.tipo }}
                                </span>
                            </td>
                            <td class="px-4 py-3 tabular-nums" :style="{ color: 'var(--color-suave)' }">{{ f.momento ?? '—' }}</td>
                            <td class="px-4 py-3">{{ f.equipo ?? '—' }}</td>
                            <td class="px-4 py-3">{{ f.navegador ?? '—' }}</td>
                            <td class="px-4 py-3 font-mono text-xs" :style="{ color: 'var(--color-suave)' }">{{ f.ip ?? '—' }}</td>
                        </tr>
                    </tbody>
                </table>

                <p v-else class="px-4 py-12 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                    No hay accesos registrados que coincidan.
                </p>
            </div>

            <Paginacion :enlaces="registro.links" :total="registro.total" :desde="registro.from" :hasta="registro.to" />
        </div>
    </AppLayout>
</template>
