<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

interface Materia {
    materia: string | null;
    ciclo: string | null;
    calificacion: string | number | null;
    estatus: string | null;
    estatus_clave: string | null;
}

interface Academico {
    matricula: string;
    carrera: string | null;
    plan: string | null;
    estatus: string;
    promedio: number | null;
    creditos: number;
    creditos_del_plan: number | null;
    materias: Materia[];
}

interface Adeudo {
    concepto?: string;
    descripcion?: string;
    total: number;
    saldo: number;
    vence?: string | null;
    [k: string]: any;
}

interface Finanza {
    matricula: string;
    carrera: string | null;
    saldo: number;
    adeudos: Adeudo[];
    pagos: any[];
    facturas: { uuid: string | null; total: number; estatus: string; fecha: string | null }[];
}

defineProps<{
    hijo: { id: number; nombre: string; foto: string | null; curp: string | null; parentesco: string };
    permisos: { academico: boolean; finanzas: boolean };
    academico: Academico[] | null;
    finanzas: Finanza[] | null;
}>();

const pesos = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' });

function colorCalif(estatusClave: string | null): string | undefined {
    if (estatusClave === 'aprobada') {
        return 'color-mix(in srgb, #16a34a 16%, transparent)';
    }
    if (estatusClave === 'reprobada') {
        return 'color-mix(in srgb, #dc2626 16%, transparent)';
    }
    return undefined;
}
</script>

<template>
    <Head :title="hijo.nombre" />

    <AppLayout titulo="Expediente de mi hijo">
        <Link href="/mis-hijos" class="text-sm font-medium" :style="{ color: 'var(--color-acento)' }">← Mis hijos</Link>

        <!-- Encabezado -->
        <section class="tarjeta flex flex-wrap items-center gap-4 p-6">
            <img v-if="hijo.foto" :src="hijo.foto" alt="" class="h-16 w-16 rounded-full object-cover" />
            <div>
                <h2 class="text-lg font-semibold">{{ hijo.nombre }}</h2>
                <p class="text-sm capitalize" :style="{ color: 'var(--color-suave)' }">
                    {{ hijo.parentesco }}
                    <span v-if="hijo.curp" class="font-mono"> · {{ hijo.curp }}</span>
                </p>
            </div>
        </section>

        <!-- Académico -->
        <template v-if="permisos.academico && academico">
            <section v-for="(a, i) in academico" :key="'a' + i" class="tarjeta overflow-hidden">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b p-5" :style="{ borderColor: 'var(--color-borde)' }">
                    <div>
                        <h3 class="font-semibold">{{ a.carrera ?? 'Carrera' }}</h3>
                        <p class="text-xs" :style="{ color: 'var(--color-suave)' }">
                            {{ a.matricula }} <span v-if="a.plan">· {{ a.plan }}</span>
                        </p>
                    </div>
                    <div class="flex gap-6 text-sm">
                        <div>
                            <p class="text-xs" :style="{ color: 'var(--color-suave)' }">Promedio</p>
                            <p class="text-lg font-semibold">{{ a.promedio ?? '—' }}</p>
                        </div>
                        <div>
                            <p class="text-xs" :style="{ color: 'var(--color-suave)' }">Créditos</p>
                            <p class="text-lg font-semibold">
                                {{ a.creditos }}<span v-if="a.creditos_del_plan" class="text-sm" :style="{ color: 'var(--color-suave)' }"> / {{ a.creditos_del_plan }}</span>
                            </p>
                        </div>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table v-if="a.materias.length" class="w-full text-sm">
                        <thead class="text-left text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">
                            <tr>
                                <th class="px-5 py-3 font-medium">Materia</th>
                                <th class="px-4 py-3 font-medium">Ciclo</th>
                                <th class="px-4 py-3 font-medium">Calif.</th>
                                <th class="px-4 py-3 font-medium">Estatus</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(m, j) in a.materias" :key="j" class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
                                <td class="px-5 py-2.5">{{ m.materia ?? '—' }}</td>
                                <td class="px-4 py-2.5 font-mono text-xs" :style="{ color: 'var(--color-suave)' }">{{ m.ciclo ?? '—' }}</td>
                                <td class="px-4 py-2.5">
                                    <span class="rounded px-2 py-0.5" :style="{ backgroundColor: colorCalif(m.estatus_clave) }">
                                        {{ m.calificacion ?? '—' }}
                                    </span>
                                </td>
                                <td class="px-4 py-2.5" :style="{ color: 'var(--color-suave)' }">{{ m.estatus ?? '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                    <p v-else class="px-5 py-8 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                        Todavía no hay materias en el historial.
                    </p>
                </div>
            </section>
        </template>

        <!-- Finanzas -->
        <template v-if="permisos.finanzas && finanzas">
            <section v-for="(f, i) in finanzas" :key="'f' + i" class="tarjeta p-5">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h3 class="font-semibold">Estado de cuenta</h3>
                        <p class="text-xs" :style="{ color: 'var(--color-suave)' }">{{ f.carrera ?? f.matricula }} · {{ f.matricula }}</p>
                    </div>
                    <div class="text-right">
                        <p class="text-xs" :style="{ color: 'var(--color-suave)' }">Saldo</p>
                        <p class="text-xl font-semibold" :class="f.saldo > 0 ? 'text-red-600' : ''">{{ pesos.format(f.saldo) }}</p>
                    </div>
                </div>

                <div v-if="f.adeudos.length" class="mt-4 overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-left text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">
                            <tr>
                                <th class="py-2 font-medium">Concepto</th>
                                <th class="py-2 text-right font-medium">Total</th>
                                <th class="py-2 text-right font-medium">Saldo</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(ad, j) in f.adeudos" :key="j" class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
                                <td class="py-2">{{ ad.concepto ?? ad.descripcion ?? 'Cargo' }}</td>
                                <td class="py-2 text-right tabular-nums">{{ pesos.format(ad.total) }}</td>
                                <td class="py-2 text-right tabular-nums" :class="ad.saldo > 0 ? 'font-medium text-red-600' : ''">
                                    {{ ad.saldo > 0 ? pesos.format(ad.saldo) : 'Pagado' }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-else class="mt-3 text-sm" :style="{ color: 'var(--color-suave)' }">Sin adeudos.</p>

                <div v-if="f.facturas.length" class="mt-4 border-t pt-3" :style="{ borderColor: 'var(--color-borde)' }">
                    <p class="mb-2 text-xs font-medium uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">Facturas</p>
                    <ul class="space-y-1 text-sm">
                        <li v-for="(fa, j) in f.facturas" :key="j" class="flex flex-wrap items-center justify-between gap-2">
                            <span class="font-mono text-xs" :style="{ color: 'var(--color-suave)' }">{{ fa.uuid ?? 'sin timbrar' }}</span>
                            <span>{{ pesos.format(fa.total) }} · {{ fa.estatus }} <span v-if="fa.fecha">· {{ fa.fecha }}</span></span>
                        </li>
                    </ul>
                </div>
            </section>
        </template>

        <p
            v-if="!permisos.academico && !permisos.finanzas"
            class="tarjeta px-6 py-12 text-center text-sm"
            :style="{ color: 'var(--color-suave)' }"
        >
            La escuela no te ha habilitado ver la información de este alumno.
        </p>
    </AppLayout>
</template>
