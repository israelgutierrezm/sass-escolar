<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BarraListado from '@/Components/BarraListado.vue';
import Paginacion from '@/Components/Paginacion.vue';
import TarjetaListado from '@/Components/TarjetaListado.vue';

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
    /** El usuario ve solo SUS matrículas (alumno/padre/tutor): sin buscador. */
    /**
     * De quién es la cartera que se está mirando.
     *
     * No basta un «es la mía o no»: un padre ve la de sus hijos, y titular eso
     * como «Mi saldo» es un número correcto con la etiqueta equivocada.
     */
    alcance: 'escuela' | 'propio' | 'familia' | 'ninguno';
}>();

const vista = ref<'lista' | 'cuadricula'>('lista');

const definicionFiltros = [
    { clave: 'deudores', etiqueta: 'Solo con saldo', tipo: 'booleano' as const },
    { clave: 'vencidos', etiqueta: 'Solo con vencido', tipo: 'booleano' as const },
];

/** Ve a muchos: el buscador y los filtros de cartera sólo tienen sentido ahí. */
const todaLaCartera = computed(() => props.alcance === 'escuela');

const titulo = computed(() => ({
    escuela: 'Finanzas',
    propio: 'Mis finanzas',
    familia: 'Finanzas de mis hijos',
    ninguno: 'Finanzas',
}[props.alcance]));

const etiquetaSaldo = computed(() => ({
    escuela: 'Saldo total',
    propio: 'Mi saldo',
    familia: 'Saldo de mis hijos',
    ninguno: 'Saldo',
}[props.alcance]));

const etiquetaConteo = computed(() => (todaLaCartera.value ? 'Con saldo' : 'Matrículas'));

const pesos = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' });
</script>

<template>
    <Head :title="titulo" />

    <AppLayout :titulo="titulo">
        <section class="grid gap-4 sm:grid-cols-3">
            <div class="tarjeta p-5">
                <p class="text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">
                    {{ etiquetaSaldo }}
                </p>
                <p class="mt-1 text-2xl font-semibold">{{ pesos.format(totales.saldo) }}</p>
            </div>
            <div class="tarjeta p-5">
                <p class="text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">Vencido</p>
                <p class="mt-1 text-2xl font-semibold" :class="totales.vencido > 0 ? 'text-red-600' : ''">
                    {{ pesos.format(totales.vencido) }}
                </p>
            </div>
            <div class="tarjeta p-5">
                <p class="text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">
                    {{ etiquetaConteo }}
                </p>
                <p class="mt-1 text-2xl font-semibold">{{ totales.deudores }}</p>
                <p class="text-xs" :style="{ color: 'var(--color-suave)' }">matrículas</p>
            </div>
        </section>

        <!-- El buscador y los filtros de cartera solo aparecen para quien ve a
             MUCHOS. Un alumno se ve a sí mismo: no hay a quién buscar. -->
        <BarraListado
            v-if="todaLaCartera"
            v-model:vista="vista"
            url="/finanzas"
            vista-clave="finanzas.cartera"
            clave-busqueda="q"
            :valores="filtros"
            :filtros="definicionFiltros"
            placeholder="Matrícula, CURP o nombre"
        />

        <!-- Cuadrícula -->
        <template v-if="vista === 'cuadricula'">
            <section v-if="matriculas.data.length" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                <TarjetaListado
                    v-for="fila in matriculas.data"
                    :key="fila.id"
                    :titulo="fila.nombre"
                    :clave="fila.matricula"
                    :href="`/finanzas/cuentas/${fila.id}`"
                    :metas="[
                        { etiqueta: 'Carrera', valor: fila.carrera },
                        { etiqueta: 'Cargos', valor: fila.adeudos },
                        { etiqueta: 'Saldo', valor: fila.saldo > 0 ? pesos.format(fila.saldo) : '—' },
                        { etiqueta: 'Vencido', valor: fila.vencido > 0 ? pesos.format(fila.vencido) : '—' },
                    ]"
                >
                    <template v-if="fila.estatus !== 'activo'" #insignia>
                        <span class="shrink-0 rounded px-1.5 py-0.5 text-xs" :style="{ backgroundColor: 'var(--color-borde)', color: 'var(--color-suave)' }">
                            {{ fila.estatus }}
                        </span>
                    </template>
                </TarjetaListado>
            </section>

            <p v-else class="tarjeta px-6 py-10 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                No hay matrículas que coincidan.
            </p>

            <section v-if="matriculas.links.length > 3" class="tarjeta">
                <Paginacion :enlaces="matriculas.links" :total="matriculas.total" :desde="matriculas.from" :hasta="matriculas.to" />
            </section>
        </template>

        <!-- Lista -->
        <section v-else class="tarjeta overflow-hidden">
            <div class="overflow-x-auto">
            <table v-if="matriculas.data.length" class="w-full text-sm">
                <thead class="text-left text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">
                    <tr>
                        <th class="px-6 py-3 font-medium">Matrícula</th>
                        <th class="px-4 py-3 font-medium">Alumno</th>
                        <th class="px-4 py-3 font-medium">Carrera</th>
                        <th class="px-4 py-3 text-right font-medium">Cargos</th>
                        <th class="px-4 py-3 text-right font-medium">Saldo</th>
                        <th class="px-4 py-3 text-right font-medium">Vencido</th>
                        <th class="px-6 py-3 font-medium text-right">Acciones</th>
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
            </div>

            <Paginacion
                :enlaces="matriculas.links"
                :total="matriculas.total"
                :desde="matriculas.from"
                :hasta="matriculas.to"
            />
        </section>
    </AppLayout>
</template>
