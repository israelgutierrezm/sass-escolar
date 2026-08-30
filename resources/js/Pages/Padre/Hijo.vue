<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonVolver from '@/Components/BotonVolver.vue';
import PildoraEstado from '@/Components/PildoraEstado.vue';
import PanelPagoEnLinea from '@/Components/PanelPagoEnLinea.vue';

interface Materia {
    materia: string | null;
    ciclo: string | null;
    calificacion: string | number | null;
    estatus: string | null;
    estatus_clave: string | null;
}

interface Academico {
    matricula: string;
    programa_academico: string | null;
    plan: string | null;
    estatus: string;
    promedio: number | null;
    creditos: number;
    creditos_del_plan: number | null;
    materias: Materia[];
}

interface Adeudo {
    /** Hace falta para pagar: es lo que identifica el cargo ante el servidor. */
    id: number;
    concepto?: string;
    descripcion?: string;
    total: number;
    saldo: number;
    vence?: string | null;
    [k: string]: any;
}

interface Finanza {
    matricula_id: number;
    matricula: string;
    programa_academico: string | null;
    saldo: number;
    adeudos: Adeudo[];
    pagos: any[];
    facturas: { uuid: string | null; total: number; estatus: string; fecha: string | null }[];
}

const props = defineProps<{
    hijo: { id: number; nombre: string; foto: string | null; curp: string | null; parentesco: string };
    permisos: { academico: boolean; finanzas: boolean };
    /** Lo que estudia, aunque no se le deje ver el detalle de ninguna. */
    programas_academicos: { matricula: string; programa_academico: string | null; campus: string | null }[];
    academico: Academico[] | null;
    finanzas: Finanza[] | null;
    /** Con qué se puede pagar aquí mismo. Vacío = la escuela no tiene ninguna. */
    pasarelas: { clave: string; nombre: string; color: string | null; pruebas: boolean; meses: number[]; efectivo: boolean }[];
    /** Cuentas de la escuela para transferir sin pasarela. */
    cuentasBancarias: {
        id: number; nombre: string; banco: string; titular: string;
        clabe: string | null; numero_cuenta: string | null; instrucciones: string | null;
    }[];
    accesos: { tipo: string; ip: string | null; navegador: string | null; equipo: string | null; momento: string | null }[];
    conducta: {
        incidencias: { id: number; tipo: string | null; nivel: number; fecha: string | null; descripcion: string }[];
        sanciones: { id: number; tipo: string | null; fecha: string | null; desde: string | null; hasta: string | null; vigente: boolean; motivo: string }[];
    } | null;
}>();

/*
 * Un programa académico a la vez.
 *
 * Cuando el hijo estudia dos cosas, esta pantalla apilaba una tarjeta completa
 * por programa académico —con toda su tabla de materias— y la segunda quedaba tan abajo
 * que había que buscarla con scroll para descubrir que existía. Se elige entre
 * ellas, como en el expediente del alumno, y así las dos están a la vista desde
 * el primer momento.
 *
 * Se identifica por MATRÍCULA y no por posición: académico y finanzas son dos
 * listas distintas y sólo la matrícula garantiza que se está mirando la misma
 * programa académico en ambas.
 */
const enFoco = ref(props.programas_academicos[0]?.matricula ?? null);

const academicoEnFoco = computed(
    () => (props.academico ?? []).filter((a) => a.matricula === enFoco.value),
);

const finanzasEnFoco = computed(
    () => (props.finanzas ?? []).filter((f) => f.matricula === enFoco.value),
);

const etiquetaAcceso: Record<string, string> = { entrada: 'Entró', salida: 'Salió' };

const pesos = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' });

/*
 * Qué cuenta se está pagando, POR MATRÍCULA.
 *
 * Un hijo puede tener dos programas académicos y cada una su propio saldo: con una sola
 * bandera, abrir el pago de una abriría también el de la otra y se acabaría
 * pagando la equivocada.
 */
const pagando = ref<Record<number, boolean>>({});

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
        <!-- Encabezado -->
        <section class="tarjeta p-6">
            <BotonVolver href="/mis-hijos" texto="Mis hijos" class="mb-4" />

            <div class="flex flex-wrap items-center gap-4">
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
            </div>

            <!--
                Con una sola programa académico no hay nada que elegir y el selector sería
                un control que no hace nada.
            -->
            <div
                v-if="programas_academicos.length > 1"
                class="mt-4 border-t pt-4"
                :style="{ borderColor: 'var(--color-borde)' }"
            >
                <p class="mb-2 text-sm">
                    Estudia <strong>{{ programas_academicos.length }} programas académicos</strong>. Elige cuál quieres ver:
                </p>
                <div class="flex flex-wrap gap-2">
                    <button
                        v-for="c in programas_academicos"
                        :key="c.matricula"
                        type="button"
                        class="rounded-lg border px-3 py-2 text-left text-sm transition"
                        :style="c.matricula === enFoco
                            ? { borderColor: 'var(--color-acento)', backgroundColor: 'color-mix(in srgb, var(--color-acento) 10%, transparent)', color: 'var(--color-acento)' }
                            : { borderColor: 'var(--color-borde)' }"
                        @click="enFoco = c.matricula"
                    >
                        <span class="block font-medium">{{ c.programa_academico ?? 'ProgramaAcademico' }}</span>
                        <span class="block text-xs" :style="{ color: 'var(--color-suave)' }">
                            {{ c.matricula }}<template v-if="c.campus"> · {{ c.campus }}</template>
                        </span>
                    </button>
                </div>
            </div>
        </section>

        <!-- Académico -->
        <template v-if="permisos.academico && academico">
            <section v-for="(a, i) in academicoEnFoco" :key="'a' + i" class="tarjeta overflow-hidden">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b p-5" :style="{ borderColor: 'var(--color-borde)' }">
                    <div>
                        <h3 class="font-semibold">{{ a.programa_academico ?? 'ProgramaAcademico' }}</h3>
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
            <section v-for="(f, i) in finanzasEnFoco" :key="'f' + i" class="tarjeta p-5">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h3 class="font-semibold">Estado de cuenta</h3>
                        <p class="text-xs" :style="{ color: 'var(--color-suave)' }">{{ f.programa_academico ?? f.matricula }} · {{ f.matricula }}</p>
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

                <!--
                    Pagar aquí mismo.

                    Es el sitio donde el padre mira lo que se debe, así que es
                    donde tiene sentido pagarlo: mandarlo a buscar la cuenta de
                    su hijo por el menú de Finanzas —que además está escrito para
                    quien cobra, no para quien paga— es perder a la mitad por el
                    camino.

                    El panel es el MISMO componente del estado de cuenta: pedir
                    la liga y explicar los fallos se escribe una vez.
                -->
                <!-- Con pasarelas O con cuenta para transferir: la escuela puede ofrecer sólo una de las dos. -->
                <div v-if="(pasarelas.length || cuentasBancarias.length) && f.saldo > 0" class="mt-4 border-t pt-4" :style="{ borderColor: 'var(--color-borde)' }">
                    <button
                        v-if="!pagando[f.matricula_id]"
                        type="button"
                        class="rounded-lg px-4 py-2 text-sm font-medium"
                        :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                        @click="pagando[f.matricula_id] = true"
                    >
                        Pagar en línea
                    </button>

                    <template v-else>
                        <PanelPagoEnLinea
                            :matricula-id="f.matricula_id"
                            :adeudos="f.adeudos"
                            :pasarelas="pasarelas"
                            :cuentas="cuentasBancarias"
                        >
                            <template #nota>Se pagan todos los cargos con saldo.</template>
                        </PanelPagoEnLinea>

                        <button
                            type="button"
                            class="mt-3 text-sm"
                            :style="{ color: 'var(--color-suave)' }"
                            @click="pagando[f.matricula_id] = false"
                        >
                            Cancelar
                        </button>
                    </template>
                </div>

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
        <!--
            La conducta: incidencias y sanciones, de sólo lectura. El padre las
            consulta; registrarlas es de la escuela. Sólo aparece si la escuela
            tiene el módulo encendido y le concedió el permiso.
        -->
        <section v-if="conducta && (conducta.incidencias.length || conducta.sanciones.length)" class="tarjeta overflow-hidden">
            <header class="border-b px-6 py-4" :style="{ borderColor: 'var(--color-borde)' }">
                <h2 class="text-base font-semibold">Conducta</h2>
                <p class="mt-0.5 text-sm" :style="{ color: 'var(--color-suave)' }">Incidencias y sanciones registradas por la escuela.</p>
            </header>

            <div v-if="conducta.sanciones.length" class="border-b px-6 py-4" :style="{ borderColor: 'var(--color-borde)' }">
                <h3 class="text-xs font-semibold uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">Sanciones</h3>
                <ul class="mt-2 space-y-2">
                    <li v-for="sa in conducta.sanciones" :key="'s' + sa.id" class="text-sm">
                        <p class="flex flex-wrap items-center gap-2 font-medium">
                            <span>{{ sa.tipo }}</span>
                            <span v-if="sa.vigente" class="rounded-full px-2 py-0.5 text-[11px] font-medium" :style="{ backgroundColor: 'color-mix(in srgb, #dc2626 14%, transparent)', color: '#dc2626' }">Vigente</span>
                        </p>
                        <p class="whitespace-pre-line">{{ sa.motivo }}</p>
                        <p class="text-xs" :style="{ color: 'var(--color-suave)' }">
                            {{ sa.fecha }}<template v-if="sa.desde"> · del {{ sa.desde }} al {{ sa.hasta }}</template>
                        </p>
                    </li>
                </ul>
            </div>

            <div v-if="conducta.incidencias.length" class="px-6 py-4">
                <h3 class="text-xs font-semibold uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">Incidencias</h3>
                <ul class="mt-2 space-y-2">
                    <li v-for="inc in conducta.incidencias" :key="'i' + inc.id" class="text-sm">
                        <p class="font-medium">{{ inc.tipo }} <span class="text-xs font-normal" :style="{ color: 'var(--color-suave)' }">· {{ inc.fecha }}</span></p>
                        <p class="whitespace-pre-line">{{ inc.descripcion }}</p>
                    </li>
                </ul>
            </div>
        </section>

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
            v-if="!permisos.academico && !permisos.finanzas && !accesos.length && !(conducta && (conducta.incidencias.length || conducta.sanciones.length))"
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
