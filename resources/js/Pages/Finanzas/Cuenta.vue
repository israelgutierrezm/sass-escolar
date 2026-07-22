<script setup lang="ts">
import { Head, useForm, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';

interface Adeudo {
    id: number;
    concepto: string | null;
    periodo: string | null;
    ciclo: string | null;
    monto: number;
    recargos: number;
    descuentos: number;
    total: number;
    aplicado: number;
    saldo: number;
    generacion: string | null;
    vencimiento: string | null;
    estatus: string;
    vencido: boolean;
    dias_vencido: number;
}

interface PagoFila {
    id: number;
    monto: number;
    metodo: string | null;
    referencia: string | null;
    estatus: string;
    cobrado: boolean;
    momento: string | null;
    sin_aplicar: number;
    cubre: string[];
}

const props = defineProps<{
    matricula: {
        id: number;
        matricula: string;
        nombre: string | null;
        carrera: string | null;
        campus: string | null;
        estatus: string;
        situacion: string | null;
        ingreso: string | null;
    };
    cuenta: {
        adeudos: Adeudo[];
        pagos: PagoFila[];
        resumen: {
            saldo: number;
            vencido: number;
            adeudos_por_cobrar: number;
            adeudos_vencidos: number;
            pagado: number;
            por_confirmar: number;
            a_favor: number;
        };
        situacion: { clave: string; nombre: string; bloquea: boolean; motivo: string | null; momento: string } | null;
        bitacora: { id: number; situacion: string | null; bloquea: boolean; motivo: string | null; momento: string }[];
    };
    planCobro: { id: number; nombre: string; aplica_a: string; reglas: number } | null;
    metodosPago: { id: number; clave: string; nombre: string; requiere_confirmacion: boolean }[];
    situacionesPago: { id: number; clave: string; nombre: string; bloquea: boolean }[];
    permisos: { registrarPagos: boolean; condonar: boolean };
}>();

const pesos = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' });

const cobrando = ref(false);
const seleccionados = ref<number[]>([]);

const pago = useForm({
    metodo_pago_id: props.metodosPago[0]?.id ?? null,
    monto: '' as string | number,
    referencia: '',
    adeudo_ids: [] as number[],
});

const metodoElegido = computed(() =>
    props.metodosPago.find((m) => m.id === Number(pago.metodo_pago_id)),
);

const seleccionadoTotal = computed(() =>
    props.cuenta.adeudos
        .filter((a) => seleccionados.value.includes(a.id))
        .reduce((suma, a) => suma + a.saldo, 0),
);

// Marcar cargos precarga el monto: es lo que se hace en ventanilla —"vengo a
// pagar marzo y abril"— y teclear la suma a mano es donde se equivoca uno.
function alternar(adeudo: Adeudo): void {
    const i = seleccionados.value.indexOf(adeudo.id);
    i === -1 ? seleccionados.value.push(adeudo.id) : seleccionados.value.splice(i, 1);
    pago.monto = seleccionadoTotal.value > 0 ? seleccionadoTotal.value.toFixed(2) : '';
}

function cobrar(): void {
    pago.adeudo_ids = [...seleccionados.value];
    pago.post(`/finanzas/cuentas/${props.matricula.id}/pagos`, {
        preserveScroll: true,
        onSuccess: () => {
            pago.reset('monto', 'referencia');
            seleccionados.value = [];
            cobrando.value = false;
        },
    });
}

function generar(): void {
    router.post(`/finanzas/cuentas/${props.matricula.id}/generar`, {}, { preserveScroll: true });
}

function confirmar(id: number): void {
    router.post(`/finanzas/pagos/${id}/confirmar`, {}, { preserveScroll: true });
}

function revertir(id: number, estatus: string): void {
    router.post(`/finanzas/pagos/${id}/revertir`, { estatus }, { preserveScroll: true });
}

const resolviendo = ref<Adeudo | null>(null);
const resolver = useForm({ estatus: 'condonado', motivo: '' });

function enviarResolucion(): void {
    if (!resolviendo.value) return;

    resolver.put(`/finanzas/adeudos/${resolviendo.value.id}/resolver`, {
        preserveScroll: true,
        onSuccess: () => {
            resolver.reset();
            resolviendo.value = null;
        },
    });
}

const cambiandoSituacion = ref(false);
const situacion = useForm({
    situacion_id: props.situacionesPago[0]?.id ?? null,
    motivo: '',
});

function guardarSituacion(): void {
    situacion.put(`/finanzas/cuentas/${props.matricula.id}/situacion`, {
        preserveScroll: true,
        onSuccess: () => {
            situacion.reset('motivo');
            cambiandoSituacion.value = false;
        },
    });
}

const colorEstatus: Record<string, string> = {
    pendiente: 'text-amber-700 bg-amber-50',
    parcial: 'text-blue-700 bg-blue-50',
    pagado: 'text-emerald-700 bg-emerald-50',
    cancelado: 'text-slate-600 bg-slate-100',
    condonado: 'text-violet-700 bg-violet-50',
    completado: 'text-emerald-700 bg-emerald-50',
    fallido: 'text-red-700 bg-red-50',
    reembolsado: 'text-slate-600 bg-slate-100',
};
</script>

<template>
    <Head :title="`Estado de cuenta · ${matricula.matricula}`" />

    <AppLayout :titulo="matricula.nombre ?? 'Estado de cuenta'">
        <section class="tarjeta p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="font-mono text-sm">{{ matricula.matricula }}</p>
                    <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                        {{ matricula.carrera ?? '—' }}
                        <span v-if="matricula.campus"> · {{ matricula.campus }}</span>
                        <span v-if="matricula.ingreso"> · ingresó el {{ matricula.ingreso }}</span>
                    </p>
                </div>
                <a href="/finanzas" class="text-sm" :style="{ color: 'var(--color-acento)' }">← Cartera</a>
            </div>

            <!--
                La situación financiera es lo que bloquea trámites, y no se
                deduce del saldo: hay escuelas que no bloquean nunca. Por eso se
                muestra como estado propio y con su motivo.
            -->
            <div
                v-if="cuenta.situacion"
                class="mt-4 rounded-lg border px-4 py-3 text-sm"
                :class="cuenta.situacion.bloquea ? 'border-red-300 bg-red-50 text-red-800' : ''"
                :style="cuenta.situacion.bloquea ? {} : { borderColor: 'var(--color-borde)' }"
            >
                <strong>{{ cuenta.situacion.nombre }}</strong>
                <span v-if="cuenta.situacion.bloquea"> — bloquea reinscripción y trámites.</span>
                <span v-if="cuenta.situacion.motivo"> {{ cuenta.situacion.motivo }}</span>
            </div>
            <p v-else class="mt-4 text-sm" :style="{ color: 'var(--color-suave)' }">
                Sin situación financiera registrada.
            </p>

            <div v-if="permisos.registrarPagos" class="mt-4 flex flex-wrap gap-2">
                <button
                    type="button"
                    class="rounded-lg px-4 py-2 text-sm font-medium"
                    :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                    @click="generar"
                >
                    Generar cargos
                </button>
                <button
                    type="button"
                    class="rounded-lg border px-4 py-2 text-sm"
                    :style="{ borderColor: 'var(--color-borde)' }"
                    @click="cambiandoSituacion = !cambiandoSituacion"
                >
                    Cambiar situación
                </button>
            </div>

            <p v-if="!planCobro" class="mt-3 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800">
                No hay plan de cobro vigente que aplique a esta matrícula: generar cargos no producirá nada.
            </p>
            <p v-else class="mt-3 text-xs" :style="{ color: 'var(--color-suave)' }">
                Sus cargos salen del plan <strong>{{ planCobro.nombre }}</strong>
                ({{ planCobro.aplica_a }}, {{ planCobro.reglas }} reglas).
            </p>

            <form v-if="cambiandoSituacion" class="mt-4 grid gap-3 sm:grid-cols-[auto_1fr_auto]" @submit.prevent="guardarSituacion">
                <select
                    v-model="situacion.situacion_id"
                    class="rounded-lg border px-3 py-2 text-sm"
                    :style="{ borderColor: 'var(--color-borde)' }"
                >
                    <option v-for="s in situacionesPago" :key="s.id" :value="s.id">
                        {{ s.nombre }}{{ s.bloquea ? ' (bloquea)' : '' }}
                    </option>
                </select>
                <input
                    v-model="situacion.motivo"
                    type="text"
                    placeholder="Motivo (queda en la bitácora)"
                    class="rounded-lg border px-3 py-2 text-sm"
                    :style="{ borderColor: 'var(--color-borde)' }"
                />
                <button
                    type="submit"
                    class="rounded-lg px-4 py-2 text-sm font-medium"
                    :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                >
                    Registrar
                </button>
            </form>
        </section>

        <section class="grid gap-4 sm:grid-cols-4">
            <div class="tarjeta p-5">
                <p class="text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">Saldo</p>
                <p class="mt-1 text-xl font-semibold">{{ pesos.format(cuenta.resumen.saldo) }}</p>
                <p class="text-xs" :style="{ color: 'var(--color-suave)' }">
                    {{ cuenta.resumen.adeudos_por_cobrar }} cargos abiertos
                </p>
            </div>
            <div class="tarjeta p-5">
                <p class="text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">Vencido</p>
                <p class="mt-1 text-xl font-semibold" :class="cuenta.resumen.vencido > 0 ? 'text-red-600' : ''">
                    {{ pesos.format(cuenta.resumen.vencido) }}
                </p>
                <p class="text-xs" :style="{ color: 'var(--color-suave)' }">
                    {{ cuenta.resumen.adeudos_vencidos }} cargos
                </p>
            </div>
            <div class="tarjeta p-5">
                <p class="text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">Pagado</p>
                <p class="mt-1 text-xl font-semibold">{{ pesos.format(cuenta.resumen.pagado) }}</p>
                <p v-if="cuenta.resumen.por_confirmar > 0" class="text-xs text-amber-700">
                    + {{ pesos.format(cuenta.resumen.por_confirmar) }} por confirmar
                </p>
            </div>
            <div class="tarjeta p-5">
                <p class="text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">A favor</p>
                <p class="mt-1 text-xl font-semibold">{{ pesos.format(cuenta.resumen.a_favor) }}</p>
                <p class="text-xs" :style="{ color: 'var(--color-suave)' }">pagos sin aplicar</p>
            </div>
        </section>

        <section class="tarjeta overflow-hidden">
            <header class="flex flex-wrap items-center justify-between gap-3 px-6 py-4">
                <h2 class="text-base font-semibold">Cargos</h2>
                <button
                    v-if="permisos.registrarPagos && cuenta.resumen.adeudos_por_cobrar > 0"
                    type="button"
                    class="rounded-lg px-4 py-2 text-sm font-medium"
                    :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                    @click="cobrando = !cobrando"
                >
                    {{ cobrando ? 'Cancelar cobro' : 'Registrar pago' }}
                </button>
            </header>

            <form v-if="cobrando" class="border-t px-6 py-4" :style="{ borderColor: 'var(--color-borde)' }" @submit.prevent="cobrar">
                <div class="grid gap-3 sm:grid-cols-4">
                    <label class="text-sm">
                        <span class="mb-1 block font-medium">Método</span>
                        <select
                            v-model="pago.metodo_pago_id"
                            class="w-full rounded-lg border px-3 py-2 text-sm"
                            :style="{ borderColor: 'var(--color-borde)' }"
                        >
                            <option v-for="m in metodosPago" :key="m.id" :value="m.id">{{ m.nombre }}</option>
                        </select>
                    </label>
                    <label class="text-sm">
                        <span class="mb-1 block font-medium">Monto</span>
                        <input
                            v-model="pago.monto"
                            type="number"
                            step="0.01"
                            min="0.01"
                            required
                            class="w-full rounded-lg border px-3 py-2 text-sm"
                            :style="{ borderColor: 'var(--color-borde)' }"
                        />
                    </label>
                    <label class="text-sm sm:col-span-2">
                        <span class="mb-1 block font-medium">Referencia</span>
                        <input
                            v-model="pago.referencia"
                            type="text"
                            class="w-full rounded-lg border px-3 py-2 text-sm"
                            :style="{ borderColor: 'var(--color-borde)' }"
                        />
                    </label>
                </div>

                <p v-if="metodoElegido?.requiere_confirmacion" class="mt-3 rounded-lg bg-amber-50 px-4 py-2 text-sm text-amber-800">
                    {{ metodoElegido.nombre }} requiere confirmación: el pago quedará PENDIENTE y no liquidará
                    los cargos hasta que se confirme.
                </p>

                <p class="mt-3 text-sm" :style="{ color: 'var(--color-suave)' }">
                    <template v-if="seleccionados.length">
                        Se aplicará a los {{ seleccionados.length }} cargos marcados, en ese orden.
                    </template>
                    <template v-else>
                        Sin cargos marcados se cubren los más vencidos primero. Lo que sobre queda a favor.
                    </template>
                </p>

                <button
                    type="submit"
                    :disabled="pago.processing"
                    class="mt-3 rounded-lg px-4 py-2 text-sm font-medium disabled:opacity-50"
                    :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                >
                    Registrar pago
                </button>
            </form>

            <table v-if="cuenta.adeudos.length" class="w-full text-sm">
                <thead class="text-left text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">
                    <tr>
                        <th v-if="cobrando" class="px-6 py-3"></th>
                        <th class="px-6 py-3 font-medium" :class="cobrando ? 'pl-0' : ''">Concepto</th>
                        <th class="px-4 py-3 font-medium">Periodo</th>
                        <th class="px-4 py-3 font-medium">Vence</th>
                        <th class="px-4 py-3 text-right font-medium">Monto</th>
                        <th class="px-4 py-3 text-right font-medium">Saldo</th>
                        <th class="px-4 py-3 font-medium">Estatus</th>
                        <th class="px-6 py-3"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="adeudo in cuenta.adeudos"
                        :key="adeudo.id"
                        class="border-t"
                        :style="{ borderColor: 'var(--color-borde)' }"
                    >
                        <td v-if="cobrando" class="px-6 py-3">
                            <input
                                type="checkbox"
                                :disabled="adeudo.saldo <= 0"
                                :checked="seleccionados.includes(adeudo.id)"
                                class="rounded"
                                @change="alternar(adeudo)"
                            />
                        </td>
                        <td class="px-6 py-3" :class="cobrando ? 'pl-0' : ''">
                            <span class="font-medium">{{ adeudo.concepto ?? '—' }}</span>
                            <span v-if="adeudo.ciclo" class="ml-2 text-xs" :style="{ color: 'var(--color-suave)' }">
                                {{ adeudo.ciclo }}
                            </span>
                        </td>
                        <td class="px-4 py-3" :style="{ color: 'var(--color-suave)' }">{{ adeudo.periodo ?? '—' }}</td>
                        <td class="px-4 py-3">
                            {{ adeudo.vencimiento ?? '—' }}
                            <span v-if="adeudo.vencido" class="ml-1 text-xs font-medium text-red-600">
                                ({{ adeudo.dias_vencido }} d)
                            </span>
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums">
                            {{ pesos.format(adeudo.total) }}
                            <!--
                                Se desglosa solo cuando hay algo que explicar:
                                la pregunta de ventanilla es "¿por qué me cobran
                                2 300 si son 2 000?" y un solo número no la
                                responde.
                            -->
                            <span
                                v-if="adeudo.recargos > 0 || adeudo.descuentos > 0"
                                class="block text-xs"
                                :style="{ color: 'var(--color-suave)' }"
                            >
                                {{ pesos.format(adeudo.monto) }}
                                <span v-if="adeudo.recargos > 0" class="text-red-600">+{{ pesos.format(adeudo.recargos) }}</span>
                                <span v-if="adeudo.descuentos > 0" class="text-emerald-600">−{{ pesos.format(adeudo.descuentos) }}</span>
                            </span>
                        </td>
                        <td class="px-4 py-3 text-right font-medium tabular-nums">
                            {{ adeudo.saldo > 0 ? pesos.format(adeudo.saldo) : '—' }}
                        </td>
                        <td class="px-4 py-3">
                            <span class="rounded px-2 py-0.5 text-xs font-medium" :class="colorEstatus[adeudo.estatus] ?? ''">
                                {{ adeudo.estatus }}
                            </span>
                        </td>
                        <td class="px-6 py-3 text-right">
                            <button
                                v-if="permisos.condonar && adeudo.estatus !== 'pagado' && adeudo.estatus !== 'condonado' && adeudo.estatus !== 'cancelado'"
                                type="button"
                                class="text-xs font-medium"
                                :style="{ color: 'var(--color-acento)' }"
                                @click="resolviendo = adeudo"
                            >
                                Condonar / cancelar
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>

            <p v-else class="px-6 py-10 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                Todavía no tiene cargos. Usa "Generar cargos" para correr el plan de cobro.
            </p>
        </section>

        <section v-if="resolviendo" class="tarjeta p-6">
            <h2 class="text-base font-semibold">
                Condonar o cancelar: {{ resolviendo.concepto }} {{ resolviendo.periodo }}
            </h2>
            <form class="mt-4 grid gap-3 sm:grid-cols-[auto_1fr_auto_auto]" @submit.prevent="enviarResolucion">
                <select
                    v-model="resolver.estatus"
                    class="rounded-lg border px-3 py-2 text-sm"
                    :style="{ borderColor: 'var(--color-borde)' }"
                >
                    <option value="condonado">Condonar (se le perdona)</option>
                    <option value="cancelado">Cancelar (no debió emitirse)</option>
                </select>
                <input
                    v-model="resolver.motivo"
                    type="text"
                    required
                    minlength="10"
                    placeholder="Motivo — mínimo 10 caracteres, queda en la bitácora"
                    class="rounded-lg border px-3 py-2 text-sm"
                    :style="{ borderColor: 'var(--color-borde)' }"
                />
                <button
                    type="submit"
                    class="rounded-lg px-4 py-2 text-sm font-medium"
                    :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                >
                    Aplicar
                </button>
                <button
                    type="button"
                    class="rounded-lg border px-4 py-2 text-sm"
                    :style="{ borderColor: 'var(--color-borde)' }"
                    @click="resolviendo = null"
                >
                    Cerrar
                </button>
            </form>
            <p v-if="resolver.errors.motivo" class="mt-2 text-sm text-red-600">{{ resolver.errors.motivo }}</p>
        </section>

        <section class="tarjeta overflow-hidden">
            <header class="px-6 py-4">
                <h2 class="text-base font-semibold">Pagos</h2>
            </header>

            <table v-if="cuenta.pagos.length" class="w-full text-sm">
                <thead class="text-left text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">
                    <tr>
                        <th class="px-6 py-3 font-medium">Fecha</th>
                        <th class="px-4 py-3 font-medium">Método</th>
                        <th class="px-4 py-3 font-medium">Referencia</th>
                        <th class="px-4 py-3 font-medium">Cubre</th>
                        <th class="px-4 py-3 text-right font-medium">Monto</th>
                        <th class="px-4 py-3 font-medium">Estatus</th>
                        <th class="px-6 py-3"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="p in cuenta.pagos" :key="p.id" class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
                        <td class="px-6 py-3">{{ p.momento ?? '—' }}</td>
                        <td class="px-4 py-3">{{ p.metodo ?? '—' }}</td>
                        <td class="px-4 py-3 font-mono text-xs">{{ p.referencia ?? '—' }}</td>
                        <td class="px-4 py-3 text-xs" :style="{ color: 'var(--color-suave)' }">
                            {{ p.cubre.length ? p.cubre.join(', ') : '—' }}
                            <span v-if="p.sin_aplicar > 0" class="block">
                                {{ pesos.format(p.sin_aplicar) }} sin aplicar
                            </span>
                        </td>
                        <td class="px-4 py-3 text-right font-medium tabular-nums">{{ pesos.format(p.monto) }}</td>
                        <td class="px-4 py-3">
                            <span class="rounded px-2 py-0.5 text-xs font-medium" :class="colorEstatus[p.estatus] ?? ''">
                                {{ p.estatus }}
                            </span>
                        </td>
                        <td class="px-6 py-3 text-right">
                            <div v-if="permisos.registrarPagos" class="flex justify-end gap-3">
                                <button
                                    v-if="p.estatus === 'pendiente'"
                                    type="button"
                                    class="text-xs font-medium"
                                    :style="{ color: 'var(--color-acento)' }"
                                    @click="confirmar(p.id)"
                                >
                                    Confirmar
                                </button>
                                <button
                                    v-if="p.estatus === 'pendiente'"
                                    type="button"
                                    class="text-xs font-medium text-red-600"
                                    @click="revertir(p.id, 'fallido')"
                                >
                                    Marcar fallido
                                </button>
                                <button
                                    v-if="p.estatus === 'completado'"
                                    type="button"
                                    class="text-xs font-medium text-red-600"
                                    @click="revertir(p.id, 'reembolsado')"
                                >
                                    Reembolsar
                                </button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>

            <p v-else class="px-6 py-10 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                Sin pagos registrados.
            </p>
        </section>

        <section v-if="cuenta.bitacora.length" class="tarjeta p-6">
            <h2 class="text-base font-semibold">Historial de situación financiera</h2>
            <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                Se agrega, no se pisa: levantar un bloqueo deja el renglón que lo explicaba.
            </p>
            <ul class="mt-4 space-y-2 text-sm">
                <li
                    v-for="renglon in cuenta.bitacora"
                    :key="renglon.id"
                    class="flex flex-wrap gap-2 border-t pt-2"
                    :style="{ borderColor: 'var(--color-borde)' }"
                >
                    <span class="tabular-nums" :style="{ color: 'var(--color-suave)' }">{{ renglon.momento }}</span>
                    <span class="font-medium" :class="renglon.bloquea ? 'text-red-600' : ''">{{ renglon.situacion }}</span>
                    <span v-if="renglon.motivo" :style="{ color: 'var(--color-suave)' }">— {{ renglon.motivo }}</span>
                </li>
            </ul>
        </section>
    </AppLayout>
</template>
