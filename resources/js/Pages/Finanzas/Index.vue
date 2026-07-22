<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Paginacion from '@/Components/Paginacion.vue';

interface Fila {
    id: number;
    matricula: string;
    nombre: string;
    carrera: string | null;
    campus: string | null;
    estatus: string;
    saldo: number;
    vencido: number;
    adeudos: number;
}

const props = defineProps<{
    matriculas: {
        data: Fila[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
        from: number | null;
        to: number | null;
    };
    filtros: { q: string; deudores: boolean; vencidos: boolean };
    totales: { saldo: number; vencido: number; deudores: number };
    puedeRegistrarPagos: boolean;
}>();

const busqueda = ref(props.filtros.q);
const soloDeudores = ref(props.filtros.deudores);
const soloVencidos = ref(props.filtros.vencidos);

let temporizador: ReturnType<typeof setTimeout> | undefined;

function consultar(): void {
    router.get(
        '/finanzas',
        {
            q: busqueda.value || undefined,
            deudores: soloDeudores.value ? 1 : undefined,
            vencidos: soloVencidos.value ? 1 : undefined,
        },
        { preserveState: true, replace: true },
    );
}

// La búsqueda se teclea de a poco; sin la espera se dispara una consulta por
// letra contra una tabla que agrega toda la cartera.
watch(busqueda, () => {
    clearTimeout(temporizador);
    temporizador = setTimeout(consultar, 350);
});

watch([soloDeudores, soloVencidos], consultar);

const pesos = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' });
</script>

<template>
    <Head title="Cartera" />

    <AppLayout titulo="Finanzas">
        <section class="grid gap-4 sm:grid-cols-3">
            <div class="tarjeta p-5">
                <p class="text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">Saldo total</p>
                <p class="mt-1 text-2xl font-semibold">{{ pesos.format(totales.saldo) }}</p>
            </div>
            <div class="tarjeta p-5">
                <p class="text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">Vencido</p>
                <p class="mt-1 text-2xl font-semibold" :class="totales.vencido > 0 ? 'text-red-600' : ''">
                    {{ pesos.format(totales.vencido) }}
                </p>
            </div>
            <div class="tarjeta p-5">
                <p class="text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">Con saldo</p>
                <p class="mt-1 text-2xl font-semibold">{{ totales.deudores }}</p>
                <p class="text-xs" :style="{ color: 'var(--color-suave)' }">matrículas</p>
            </div>
        </section>

        <section class="tarjeta p-5">
            <div class="flex flex-wrap items-center gap-4">
                <input
                    v-model="busqueda"
                    type="search"
                    placeholder="Matrícula, CURP o nombre"
                    class="min-w-64 flex-1 rounded-lg border px-3 py-2 text-sm"
                    :style="{ borderColor: 'var(--color-borde)' }"
                />
                <label class="flex items-center gap-2 text-sm">
                    <input v-model="soloDeudores" type="checkbox" class="rounded" />
                    Solo con saldo
                </label>
                <label class="flex items-center gap-2 text-sm">
                    <input v-model="soloVencidos" type="checkbox" class="rounded" />
                    Solo con vencido
                </label>
            </div>
        </section>

        <section class="tarjeta overflow-hidden">
            <table v-if="matriculas.data.length" class="w-full text-sm">
                <thead class="text-left text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">
                    <tr>
                        <th class="px-6 py-3 font-medium">Matrícula</th>
                        <th class="px-4 py-3 font-medium">Alumno</th>
                        <th class="px-4 py-3 font-medium">Carrera</th>
                        <th class="px-4 py-3 text-right font-medium">Cargos</th>
                        <th class="px-4 py-3 text-right font-medium">Saldo</th>
                        <th class="px-4 py-3 text-right font-medium">Vencido</th>
                        <th class="px-6 py-3"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="fila in matriculas.data"
                        :key="fila.id"
                        class="border-t"
                        :style="{ borderColor: 'var(--color-borde)' }"
                    >
                        <td class="px-6 py-3 font-mono text-xs">{{ fila.matricula }}</td>
                        <td class="px-4 py-3">
                            <span class="font-medium">{{ fila.nombre }}</span>
                            <span
                                v-if="fila.estatus !== 'activo'"
                                class="ml-2 rounded px-1.5 py-0.5 text-xs"
                                :style="{ backgroundColor: 'var(--color-borde)', color: 'var(--color-suave)' }"
                            >
                                {{ fila.estatus }}
                            </span>
                        </td>
                        <td class="px-4 py-3" :style="{ color: 'var(--color-suave)' }">
                            {{ fila.carrera ?? '—' }}
                            <span v-if="fila.campus" class="text-xs"> · {{ fila.campus }}</span>
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ fila.adeudos }}</td>
                        <td class="px-4 py-3 text-right font-medium tabular-nums">
                            {{ fila.saldo > 0 ? pesos.format(fila.saldo) : '—' }}
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums" :class="fila.vencido > 0 ? 'font-semibold text-red-600' : ''">
                            {{ fila.vencido > 0 ? pesos.format(fila.vencido) : '—' }}
                        </td>
                        <td class="px-6 py-3 text-right">
                            <a
                                :href="`/finanzas/cuentas/${fila.id}`"
                                class="text-sm font-medium"
                                :style="{ color: 'var(--color-acento)' }"
                            >
                                Estado de cuenta
                            </a>
                        </td>
                    </tr>
                </tbody>
            </table>

            <p v-else class="px-6 py-10 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                No hay matrículas que coincidan.
            </p>

            <Paginacion
                :enlaces="matriculas.links"
                :total="matriculas.total"
                :desde="matriculas.from"
                :hasta="matriculas.to"
            />
        </section>
    </AppLayout>
</template>
