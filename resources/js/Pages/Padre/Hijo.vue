<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PildoraEstado from '@/Components/PildoraEstado.vue';

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
    accesos: { tipo: string; ip: string | null; navegador: string | null; equipo: string | null; momento: string | null }[];
}>();

const etiquetaAcceso: Record<string, string> = { entrada: 'Entró', salida: 'Salió' };

const pesos = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' });

function iniciales(nombre: string | null): string {
    if (!nombre) return '—';
    const partes = nombre.trim().split(/\s+/);
    return ((partes[0]?.[0] ?? '') + (partes[1]?.[0] ?? '')).toUpperCase() || '—';
}

// Color SÓLIDO por resultado de la materia (aprobada/reprobada; neutro si va en curso).
function colorCalif(estatusClave: string | null): string {
    if (estatusClave === 'aprobada') return '#16a34a';
    if (estatusClave === 'reprobada') return '#dc2626';
    return 'var(--color-suave)';
}
</script>

<template>
    <Head :title="hijo.nombre" />

    <AppLayout titulo="Expediente de mi hijo">
        <Link href="/mis-hijos" class="text-sm font-medium" :style="{ color: 'var(--color-acento)' }">← Mis hijos</Link>

        <!-- Encabezado -->
        <section class="tarjeta flex flex-wrap items-center gap-4 p-6">
            <img v-if="hijo.foto" :src="hijo.foto" alt="" class="h-16 w-16 rounded-full object-cover ring-1 ring-black/5" />
            <span
                v-else
                class="grid h-16 w-16 shrink-0 place-items-center rounded-full text-lg font-semibold ring-1 ring-black/5"
                :style="{ backgroundColor: 'color-mix(in srgb, var(--color-acento) 15%, transparent)', color: 'var(--color-acento)' }"
            >{{ iniciales(hijo.nombre) }}</span>
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
                        <thead>
                            <tr class="text-left text-[11px] uppercase tracking-wider" :style="{ color: 'var(--color-suave)', backgroundColor: 'color-mix(in srgb, var(--color-suave) 6%, transparent)' }">
                                <th class="px-6 py-3 font-semibold">Materia</th>
                                <th class="px-4 py-3 font-semibold">Ciclo</th>
                                <th class="px-4 py-3 font-semibold text-center">Calif.</th>
                                <th class="px-4 py-3 font-semibold">Estatus</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(m, j) in a.materias" :key="j" class="fila-nueva border-t transition-colors" :style="{ borderColor: 'var(--color-borde)' }">
                                <td class="px-6 py-3 font-medium text-contenido">{{ m.materia ?? '—' }}</td>
                                <td class="px-4 py-3 font-mono text-xs" :style="{ color: 'var(--color-suave)' }">{{ m.ciclo ?? '—' }}</td>
                                <td class="px-4 py-3 text-center">
                                    <span
                                        class="inline-block rounded-full px-2.5 py-0.5 text-xs font-semibold tabular-nums"
                                        :style="{ color: colorCalif(m.estatus_clave), backgroundColor: `color-mix(in srgb, ${colorCalif(m.estatus_clave)} 14%, transparent)` }"
                                    >{{ m.calificacion ?? '—' }}</span>
                                </td>
                                <td class="px-4 py-3">
                                    <PildoraEstado :texto="m.estatus" :color="colorCalif(m.estatus_clave)" />
                                </td>
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
                        <thead>
                            <tr class="text-left text-[11px] uppercase tracking-wider" :style="{ color: 'var(--color-suave)' }">
                                <th class="py-2 font-semibold">Concepto</th>
                                <th class="py-2 text-right font-semibold">Total</th>
                                <th class="py-2 text-right font-semibold">Saldo</th>
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

        <!-- Accesos del hijo -->
        <section v-if="accesos.length" class="tarjeta overflow-hidden">
            <div class="border-b p-5" :style="{ borderColor: 'var(--color-borde)' }">
                <h3 class="font-semibold">Accesos recientes</h3>
                <p class="text-xs" :style="{ color: 'var(--color-suave)' }">Cuándo y desde dónde entró y salió tu hijo.</p>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-[11px] uppercase tracking-wider" :style="{ color: 'var(--color-suave)', backgroundColor: 'color-mix(in srgb, var(--color-suave) 6%, transparent)' }">
                            <th class="px-6 py-3 font-semibold">Movimiento</th>
                            <th class="px-4 py-3 font-semibold">Fecha y hora</th>
                            <th class="px-4 py-3 font-semibold">Equipo</th>
                            <th class="px-4 py-3 font-semibold">Navegador</th>
                            <th class="px-6 py-3 font-semibold">IP</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(ac, i) in accesos" :key="i" class="fila-nueva border-t transition-colors" :style="{ borderColor: 'var(--color-borde)' }">
                            <td class="px-6 py-3">
                                <PildoraEstado :texto="etiquetaAcceso[ac.tipo] ?? ac.tipo" :color="ac.tipo === 'entrada' ? '#16a34a' : 'var(--color-suave)'" />
                            </td>
                            <td class="px-4 py-3 tabular-nums" :style="{ color: 'var(--color-suave)' }">{{ ac.momento ?? '—' }}</td>
                            <td class="px-4 py-3">{{ ac.equipo ?? '—' }}</td>
                            <td class="px-4 py-3">{{ ac.navegador ?? '—' }}</td>
                            <td class="px-6 py-3 font-mono text-xs" :style="{ color: 'var(--color-suave)' }">{{ ac.ip ?? '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <p
            v-if="!permisos.academico && !permisos.finanzas && !accesos.length"
            class="tarjeta px-6 py-12 text-center text-sm"
            :style="{ color: 'var(--color-suave)' }"
        >
            La escuela no te ha habilitado ver la información de este alumno.
        </p>
    </AppLayout>
</template>

<style scoped>
.fila-nueva:hover {
    background-color: color-mix(in srgb, var(--color-acento) 5%, transparent);
}
</style>
