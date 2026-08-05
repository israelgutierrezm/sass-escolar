<script setup lang="ts">
import { Head, useForm, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import PildoraEstado from '@/Components/PildoraEstado.vue';
import BotonVolver from '@/Components/BotonVolver.vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoSelect from '@/Components/CampoSelect.vue';

/** Un ajuste que movió el monto: beca, descuento o recargo. Monto CON signo. */
interface Ajuste {
    tipo: string;
    etiqueta: string;
    monto: number;
    periodo: string | null;
}

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
    ajustes: Ajuste[];
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
    /**
     * El plan del que salen sus cargos. `aplica_a` y `reglas` se pedían aquí y
     * el servidor nunca los mandó —quedaron de un refactor—, así que el renglón
     * se leía «(, reglas)».
     */
    planCobro: { id: number; nombre: string; ciclo: string | null; conceptos: number; total_planes: number } | null;
    metodosPago: { id: number; clave: string; nombre: string; requiere_confirmacion: boolean }[];
    situacionesPago: { id: number; clave: string; nombre: string; bloquea: boolean }[];
    permisos: { registrarPagos: boolean; condonar: boolean; facturar: boolean };
    facturas: { id: number; uuid: string | null; estatus: string; total: number; fecha_timbrado: string | null }[];
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

</script>

<template>
    <Head :title="`Estado de cuenta · ${matricula.matricula}`" />

    <AppLayout :titulo="matricula.nombre ?? 'Estado de cuenta'">
        <section class="tarjeta p-6">
            <BotonVolver href="/finanzas" texto="Cartera" class="mb-4" />

            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="font-mono text-sm">{{ matricula.matricula }}</p>
                    <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                        {{ matricula.carrera ?? '—' }}
                        <span v-if="matricula.campus"> · {{ matricula.campus }}</span>
                        <span v-if="matricula.ingreso"> · ingresó el {{ matricula.ingreso }}</span>
                    </p>
                </div>
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
                <a
                    v-if="permisos.facturar"
                    :href="`/finanzas/facturas/emitir/${matricula.id}`"
                    class="rounded-lg border px-4 py-2 text-sm"
                    :style="{ borderColor: 'var(--color-borde)' }"
                >
                    Emitir factura
                </a>
            </div>

            <p v-if="!planCobro" class="mt-3 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800">
                No hay plan de cobro vigente que aplique a esta matrícula: generar cargos no producirá nada.
            </p>
            <p v-else class="mt-3 text-xs" :style="{ color: 'var(--color-suave)' }">
                Sus cargos salen del plan <strong>{{ planCobro.nombre }}</strong><template v-if="planCobro.ciclo"> del ciclo {{ planCobro.ciclo }}</template>,
                con {{ planCobro.conceptos }} {{ planCobro.conceptos === 1 ? 'concepto' : 'conceptos' }}.
                <!-- Si le aplican varios, decirlo: si no, el renglón nombra uno
                     y el alumno tiene cargos que no salen de ahí. -->
                <template v-if="planCobro.total_planes > 1">
                    Le aplican {{ planCobro.total_planes }} planes en total.
                </template>
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
                <BotonPrincipal texto="Registrar" icono="crear" />
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
                    <CampoSelect
                        v-model="pago.metodo_pago_id"
                        etiqueta="Método"
                        :opciones="metodosPago.map((m) => ({ valor: m.id, texto: m.nombre }))"
                        :error="pago.errors.metodo_pago_id"
                    />
                    <CampoTexto v-model="pago.monto" tipo="number" etiqueta="Monto" requerido step="0.01" min="0.01" :error="pago.errors.monto" />
                    <div class="sm:col-span-2">
                        <CampoTexto v-model="pago.referencia" etiqueta="Referencia" :error="pago.errors.referencia" />
                    </div>
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

                <BotonPrincipal :procesando="pago.processing" texto="Registrar pago" icono="crear" class="mt-3" />
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
                        <th class="px-6 py-3 font-medium text-right">Acciones</th>
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
                                v-if="adeudo.ajustes.length"
                                class="mt-0.5 block text-xs"
                                :style="{ color: 'var(--color-suave)' }"
                            >
                                {{ pesos.format(adeudo.monto) }} base
                            </span>
                            <!--
                                Cada ajuste con su NOMBRE: no basta decir
                                "−400", hay que poder responder "de qué beca".
                                La etiqueta es un snapshot, así que sigue
                                explicando aunque la beca se renombre después.
                            -->
                            <span
                                v-for="(j, i) in adeudo.ajustes"
                                :key="i"
                                class="block text-xs"
                                :style="{ color: j.monto < 0 ? '#16a34a' : '#dc2626' }"
                                :title="j.periodo ? `Periodo ${j.periodo}` : ''"
                            >
                                {{ j.monto < 0 ? '−' : '+' }}{{ pesos.format(Math.abs(j.monto)) }}
                                <span :style="{ color: 'var(--color-suave)' }">{{ j.etiqueta }}</span>
                            </span>
                        </td>
                        <td class="px-4 py-3 text-right font-medium tabular-nums">
                            {{ adeudo.saldo > 0 ? pesos.format(adeudo.saldo) : '—' }}
                        </td>
                        <td class="px-4 py-3">
                            <PildoraEstado :texto="adeudo.estatus" />
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
                <BotonPrincipal texto="Aplicar" />
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
                        <th class="px-6 py-3 font-medium text-right">Acciones</th>
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
                            <PildoraEstado :texto="p.estatus" />
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

        <section v-if="permisos.facturar && facturas.length" class="tarjeta overflow-hidden">
            <header class="px-6 py-4">
                <h2 class="text-base font-semibold">Facturas</h2>
            </header>
            <table class="w-full text-sm">
                <thead class="text-left text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">
                    <tr>
                        <th class="px-6 py-3 font-medium">Folio fiscal</th>
                        <th class="px-4 py-3 font-medium">Timbrado</th>
                        <th class="px-4 py-3 text-right font-medium">Total</th>
                        <th class="px-4 py-3 font-medium">Estatus</th>
                        <th class="px-6 py-3 font-medium text-right">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="f in facturas" :key="f.id" class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
                        <td class="px-6 py-3 font-mono text-xs">{{ f.uuid ?? '—' }}</td>
                        <td class="px-4 py-3 tabular-nums" :style="{ color: 'var(--color-suave)' }">
                            {{ f.fecha_timbrado ?? '—' }}
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ pesos.format(f.total) }}</td>
                        <td class="px-4 py-3">
                            <PildoraEstado :texto="f.estatus" />
                        </td>
                        <td class="px-6 py-3">
                            <div class="flex justify-end">
                                <BotonAccion variante="ver" solo-icono :href="`/finanzas/facturas/${f.id}`" />
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
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
