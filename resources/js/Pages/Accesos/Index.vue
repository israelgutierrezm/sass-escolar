<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BarraListado from '@/Components/BarraListado.vue';
import Paginacion from '@/Components/Paginacion.vue';
import PildoraEstado from '@/Components/PildoraEstado.vue';

interface Fila {
    id: number;
    persona: string;
    tipo: string;
    /** Cómo entró, si no fue tecleando. Hoy sólo «recordado». */
    via?: string | null;
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

// Color SÓLIDO por tipo de movimiento (texto + punto de la píldora).
function colorTipo(tipo: string): string {
    if (tipo === 'entrada') return '#16a34a';
    if (tipo === 'salida') return 'var(--color-suave)';
    return '#d97706';
}

const ICONO_ACCESOS =
    'M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z';
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
            <!--
                La gráfica DESPLAZA en vez de apretarse. Cada columna es un día
                y su rótulo («28/08») pide unos 30 px: con dos semanas eso son
                460 px, y en un teléfono empujaba la página 132 px fuera de la
                pantalla. Con un ancho mínimo por columna se recorre con el
                dedo, y en pantalla ancha `flex-1` las sigue repartiendo.
            -->
            <div class="-mx-1 mt-4 flex items-end gap-2 overflow-x-auto px-1 pb-1" style="height: 152px">
                <div v-for="d in porDia" :key="d.dia" class="flex min-w-[2.25rem] flex-1 flex-col items-center justify-end gap-1">
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
            titulo="Bitácora de accesos"
            descripcion="Entradas, salidas y recuperaciones"
            :icono="ICONO_ACCESOS"
        >
            <template #conteo>
                <span class="rounded-full px-3 py-1 text-xs font-medium" :style="{ backgroundColor: 'color-mix(in srgb, var(--color-acento) 12%, transparent)', color: 'var(--color-acento)' }">
                    {{ registro.total }} registros
                </span>
            </template>
        </BarraListado>

        <!-- Registro a detalle -->
        <div class="tarjeta overflow-hidden">
            <div class="overflow-x-auto">
                <table v-if="registro.data.length" class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-[11px] uppercase tracking-wider" :style="{ color: 'var(--color-suave)', backgroundColor: 'color-mix(in srgb, var(--color-suave) 6%, transparent)' }">
                            <th class="px-6 py-3 font-semibold">Persona</th>
                            <th class="px-4 py-3 font-semibold">Movimiento</th>
                            <th class="px-4 py-3 font-semibold">Fecha y hora</th>
                            <th class="px-4 py-3 font-semibold">Equipo</th>
                            <th class="px-4 py-3 font-semibold">Navegador</th>
                            <th class="px-6 py-3 font-semibold">IP</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="f in registro.data" :key="f.id" class="fila-nueva border-t transition-colors" :style="{ borderColor: 'var(--color-borde)' }">
                            <td class="px-6 py-4 font-medium text-contenido">{{ f.persona }}</td>
                            <td class="px-4 py-4">
                                <PildoraEstado :texto="etiquetaTipo[f.tipo] ?? f.tipo" :color="colorTipo(f.tipo)" sin-capitalizar />
                                <!--
                                    Que la sesión se recuperó con la cookie y no
                                    tecleando. Es lo que distingue una máquina
                                    que alguien dejó abierta de alguien que
                                    demostró saber su contraseña, y sin decirlo
                                    las dos filas se leen igual.
                                -->
                                <span
                                    v-if="f.via === 'recordado'"
                                    class="ml-1.5 text-xs"
                                    :style="{ color: 'var(--color-suave)' }"
                                    title="La sesión se recuperó con la cookie de «recuérdame»: no se tecleó la contraseña."
                                >
                                    con «recuérdame»
                                </span>
                            </td>
                            <td class="px-4 py-4 tabular-nums" :style="{ color: 'var(--color-suave)' }">{{ f.momento ?? '—' }}</td>
                            <td class="px-4 py-4">{{ f.equipo ?? '—' }}</td>
                            <td class="px-4 py-4">{{ f.navegador ?? '—' }}</td>
                            <td class="px-6 py-4 font-mono text-xs" :style="{ color: 'var(--color-suave)' }">{{ f.ip ?? '—' }}</td>
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

<style scoped>
.fila-nueva:hover {
    background-color: color-mix(in srgb, var(--color-acento) 5%, transparent);
}
</style>

