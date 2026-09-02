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
import PanelPagoEnLinea from '@/Components/PanelPagoEnLinea.vue';
import { hoyLocal } from '@/utils/fechas';

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
    /** Puesto cuando este cargo es una PARCIALIDAD de un convenio. */
    convenio_id: number | null;
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
        programa_academico: string | null;
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
    permisos: { registrarPagos: boolean; condonar: boolean; facturar: boolean; convenios: boolean };
    /**
     * El convenio vigente de este alumno, si tiene. Llega aunque no se pueda
     * autorizar ninguno: quien cobra en el mostrador necesita ver que hay un
     * acuerdo antes de reclamarle un cargo que ya está reprogramado.
     */
    convenioVigente: {
        id: number;
        motivo: string;
        concepto: string | null;
        firmado_en: string | null;
        monto_cubierto: number;
        saldo: number;
        con_atraso: boolean;
        parcialidades: { id: number; vencimiento: string | null; monto: number; saldo: number; estatus: string }[];
    } | null;
    /*
     * Con qué se puede pagar en línea. Llega vacío cuando la escuela no tiene
     * ninguna encendida, y entonces el botón ni aparece: ofrecer un pago que no
     * se puede completar es peor que no ofrecerlo.
     */
    pasarelas: {
        clave: string;
        nombre: string;
        color: string | null;
        pruebas: boolean;
        /** Plazos de meses sin intereses, de mayor a menor. Vacío = un solo pago. */
        meses: number[];
        /** ¿Acepta efectivo en tienda (OXXO y similares)? */
        efectivo: boolean;
    }[];
    /**
     * La otra vía: transferir a la cuenta de la escuela y subir el comprobante.
     * Vacío = la escuela no tiene ninguna cuenta para este programa académico.
     */
    cuentasBancarias: {
        id: number; nombre: string; banco: string; titular: string;
        clabe: string | null; numero_cuenta: string | null; instrucciones: string | null;
    }[];
    facturas: { id: number; uuid: string | null; estatus: string; total: number; fecha_timbrado: string | null }[];
}>();

const pesos = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' });

const cobrando = ref(false);
const seleccionados = ref<number[]>([]);

/*
 * Pago en línea. Comparte la MISMA selección de cargos que el cobro en
 * ventanilla: son la misma pregunta —«¿qué se está pagando?»— y tener dos
 * listas obligaría a marcar dos veces para acabar en el mismo sitio.
 *
 * Lo demás —pedir la liga, explicar los fallos— vive en `PanelPagoEnLinea`,
 * porque el portal del padre hace exactamente lo mismo.
 */
const pagandoEnLinea = ref(false);

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


// --- convenio de pago
const acordando = ref(false);
const cargosElegibles = ref<{ id: number; concepto_id: number; concepto: string | null; periodo: string | null; vencimiento: string | null; saldo: number; vencido: boolean }[]>([]);
const elegidos = ref<number[]>([]);
const convenio = useForm<{
    adeudo_ids: number[];
    parcialidades: { fecha: string; monto: number | string }[];
    motivo: string;
    firmado_en: string;
}>({ adeudo_ids: [], parcialidades: [], motivo: '', firmado_en: hoyLocal() });

const totalElegido = computed(() =>
    cargosElegibles.value.filter((c) => elegidos.value.includes(c.id)).reduce((t, c) => t + c.saldo, 0),
);

const totalParcialidades = computed(() =>
    convenio.parcialidades.reduce((t, p) => t + (Number(p.monto) || 0), 0),
);

// Lo que falta para que cuadre, redondeado al centavo: un convenio reprograma
// y no perdona, así que el servidor lo va a exigir exacto. Decirlo aquí evita
// que se envíe para descubrirlo.
const diferencia = computed(() => Math.round((totalParcialidades.value - totalElegido.value) * 100) / 100);

async function abrirConvenio(): Promise<void> {
    acordando.value = !acordando.value;
    if (!acordando.value) return;

    elegidos.value = [];
    convenio.reset();
    convenio.firmado_en = hoyLocal();

    const r = await fetch(`/finanzas/convenios/elegibles/${props.matricula.id}`, {
        headers: { Accept: 'application/json' },
    });
    if (r.ok) cargosElegibles.value = (await r.json()).cargos ?? [];
}

function alternarCargo(id: number): void {
    elegidos.value = elegidos.value.includes(id)
        ? elegidos.value.filter((x) => x !== id)
        : [...elegidos.value, id];
}

function agregarParcialidad(): void {
    convenio.parcialidades = [...convenio.parcialidades, { fecha: convenio.firmado_en, monto: '' }];
}

function quitarParcialidad(i: number): void {
    convenio.parcialidades = convenio.parcialidades.filter((_, j) => j !== i);
}

/** Reparte lo elegido en N partes, dejando el sobrante del redondeo en la última. */
function repartir(n: number): void {
    const total = Math.round(totalElegido.value * 100);
    const base = Math.floor(total / n);
    const partes: { fecha: string; monto: number | string }[] = [];
    const inicio = new Date(convenio.firmado_en + 'T00:00:00');

    for (let i = 0; i < n; i++) {
        const f = new Date(inicio);
        f.setMonth(f.getMonth() + i + 1);
        partes.push({
            fecha: f.toISOString().slice(0, 10),
            monto: ((i === n - 1 ? total - base * (n - 1) : base) / 100).toFixed(2),
        });
    }

    convenio.parcialidades = partes;
}

function firmarConvenio(): void {
    convenio.adeudo_ids = elegidos.value;
    convenio.post(`/finanzas/convenios/${props.matricula.id}`, {
        preserveScroll: true,
        onSuccess: () => (acordando.value = false),
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
                        {{ matricula.programa_academico ?? '—' }}
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

        <!-- Convenio de pago -->
        <section v-if="convenioVigente || permisos.convenios" class="tarjeta p-6">
            <header class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-base font-semibold">Convenio de pago</h2>
                <BotonPrincipal
                    v-if="permisos.convenios && !convenioVigente"
                    tipo="button"
                    :texto="acordando ? 'Cerrar' : 'Acordar parcialidades'"
                    :icono="acordando ? 'ninguno' : 'crear'"
                    @click="abrirConvenio"
                />
            </header>

            <template v-if="convenioVigente">
                <p class="mt-2 text-sm" :style="{ color: 'var(--color-suave)' }">
                    Convenio #{{ convenioVigente.id }} sobre <strong>{{ convenioVigente.concepto }}</strong>,
                    firmado el {{ convenioVigente.firmado_en }}.
                    Acordado {{ pesos.format(convenioVigente.monto_cubierto) }}, saldo
                    {{ pesos.format(convenioVigente.saldo) }}.
                    <span v-if="convenioVigente.con_atraso" :style="{ color: 'var(--color-peligro)' }">
                        Tiene una parcialidad vencida.
                    </span>
                </p>
                <p class="mt-1 text-xs" :style="{ color: 'var(--color-suave)' }">
                    {{ convenioVigente.motivo }}
                </p>
                <!--
                    Lo que hay que saber al mirar los cargos de abajo: los que el
                    convenio cubre siguen ahí con estatus «en convenio», y no se
                    cobran por su renglón sino por estas parcialidades.
                -->
                <p class="mt-2 text-xs" :style="{ color: 'var(--color-suave)' }">
                    Mientras esté vigente, los cargos acordados no generan mora y aparecen abajo como
                    «en convenio»: se cobran por estas parcialidades, no por su renglón.
                </p>

                <ul class="mt-3 space-y-1">
                    <li v-for="(p, i) in convenioVigente.parcialidades" :key="p.id" class="flex flex-wrap items-center gap-x-3 text-sm">
                        <span>Parcialidad {{ i + 1 }}</span>
                        <span :style="{ color: 'var(--color-suave)' }">vence {{ p.vencimiento }}</span>
                        <span class="tabular-nums">{{ pesos.format(p.monto) }}</span>
                        <span class="tabular-nums" :style="{ color: p.saldo > 0 ? 'var(--color-peligro)' : 'var(--color-exito)' }">
                            {{ p.saldo > 0 ? `saldo ${pesos.format(p.saldo)}` : 'pagada' }}
                        </span>
                    </li>
                </ul>

                <p class="mt-3 text-xs" :style="{ color: 'var(--color-suave)' }">
                    Se cancela o se declara incumplido desde
                    <a class="underline" href="/finanzas/convenios">Convenios de pago</a>.
                </p>
            </template>

            <template v-else-if="acordando">
                <p class="mt-2 text-sm" :style="{ color: 'var(--color-suave)' }">
                    Un convenio <strong>reprograma</strong> la deuda: las parcialidades tienen que sumar
                    exactamente el saldo elegido. Para perdonar parte, condónala primero y acuerda lo que quede.
                    Todos los cargos elegidos tienen que ser del <strong>mismo concepto</strong>.
                </p>

                <div v-if="!cargosElegibles.length" class="mt-4 text-sm" :style="{ color: 'var(--color-suave)' }">
                    Este alumno no tiene cargos por cobrar que acordar.
                </div>

                <form v-else @submit.prevent="firmarConvenio">
                    <ul class="mt-4 space-y-1">
                        <li v-for="c in cargosElegibles" :key="c.id" class="flex flex-wrap items-center gap-x-3 text-sm">
                            <label class="flex items-center gap-2">
                                <input type="checkbox" :checked="elegidos.includes(c.id)" @change="alternarCargo(c.id)" />
                                <span>{{ c.concepto }}</span>
                            </label>
                            <span :style="{ color: 'var(--color-suave)' }">{{ c.periodo }}</span>
                            <span :style="{ color: c.vencido ? 'var(--color-peligro)' : 'var(--color-suave)' }">
                                vence {{ c.vencimiento }}
                            </span>
                            <span class="tabular-nums">{{ pesos.format(c.saldo) }}</span>
                        </li>
                    </ul>

                    <p class="mt-3 text-sm">
                        Saldo elegido: <strong class="tabular-nums">{{ pesos.format(totalElegido) }}</strong>
                    </p>

                    <div class="mt-4 flex flex-wrap items-end gap-3">
                        <div class="w-44">
                            <CampoTexto v-model="convenio.firmado_en" tipo="date" etiqueta="Firmado el" requerido :error="convenio.errors.firmado_en" />
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <BotonAccion v-for="n in [2, 3, 4, 6, 12]" :key="n" variante="agregar" :texto="`${n} pagos`" @click="repartir(n)" />
                        </div>
                    </div>

                    <div class="mt-4 space-y-2">
                        <div v-for="(p, i) in convenio.parcialidades" :key="i" class="flex flex-wrap items-end gap-2">
                            <div class="w-44">
                                <CampoTexto v-model="p.fecha" tipo="date" :etiqueta="`Parcialidad ${i + 1}`" />
                            </div>
                            <div class="w-36">
                                <CampoTexto v-model="p.monto" tipo="number" paso="0.01" min="0" etiqueta="Importe" />
                            </div>
                            <BotonAccion variante="eliminar" @click="quitarParcialidad(i)" />
                        </div>
                        <BotonAccion variante="agregar" texto="Agregar parcialidad" @click="agregarParcialidad" />
                    </div>

                    <p class="mt-3 text-sm" :style="{ color: Math.abs(diferencia) < 0.005 ? 'var(--color-exito)' : 'var(--color-peligro)' }">
                        Parcialidades: <span class="tabular-nums">{{ pesos.format(totalParcialidades) }}</span>
                        <template v-if="Math.abs(diferencia) >= 0.005">
                            · {{ diferencia > 0 ? 'sobran' : 'faltan' }}
                            <span class="tabular-nums">{{ pesos.format(Math.abs(diferencia)) }}</span>
                        </template>
                        <template v-else> · cuadra</template>
                    </p>

                    <div class="mt-4">
                        <CampoTexto
                            v-model="convenio.motivo"
                            etiqueta="Motivo"
                            requerido
                            :error="convenio.errors.motivo"
                            ayuda="Por qué se acuerda. Sin la razón escrita, dentro de un año nadie podrá explicar estos plazos."
                        />
                    </div>

                    <div class="mt-4 flex gap-2">
                        <BotonPrincipal
                            :procesando="convenio.processing"
                            :deshabilitado="!elegidos.length || !convenio.parcialidades.length || Math.abs(diferencia) >= 0.005 || !convenio.motivo.trim()"
                            texto="Firmar el convenio"
                        />
                        <button
                            type="button"
                            class="rounded-lg border px-4 py-2 text-sm"
                            :style="{ borderColor: 'var(--color-borde)' }"
                            @click="acordando = false"
                        >Cancelar</button>
                    </div>
                </form>
            </template>

            <p v-else class="mt-2 text-sm" :style="{ color: 'var(--color-suave)' }">
                Este alumno no tiene ningún convenio vigente.
            </p>
        </section>

        <section class="tarjeta overflow-hidden">
            <header class="flex flex-wrap items-center justify-between gap-3 px-6 py-4">
                <h2 class="text-base font-semibold">Cargos</h2>
                <div class="flex flex-wrap gap-2">
                    <!--
                        Pagar en línea no pide `registrarPagos`: quien puede ver
                        esta cuenta puede pagarla —el alumno la suya, el padre la
                        de su hijo—, y ese permiso es el de quien cobra EN
                        VENTANILLA, que es otra cosa.
                    -->
                    <!--
                        Aparece con pasarelas O con cuenta para transferir: una
                        escuela puede ofrecer sólo la transferencia, y atarlo a
                        las pasarelas dejaba esa vía sin puerta de entrada.
                    -->
                    <button
                        v-if="(pasarelas.length || cuentasBancarias.length) && cuenta.resumen.saldo > 0"
                        type="button"
                        class="rounded-lg border px-4 py-2 text-sm font-medium"
                        :style="{ borderColor: 'var(--color-acento)', color: 'var(--color-acento)' }"
                        @click="pagandoEnLinea = !pagandoEnLinea"
                    >
                        {{ pagandoEnLinea ? 'Cancelar' : (pasarelas.length ? 'Pagar en línea' : 'Pagar') }}
                    </button>
                    <button
                        v-if="permisos.registrarPagos && cuenta.resumen.adeudos_por_cobrar > 0"
                        type="button"
                        class="rounded-lg px-4 py-2 text-sm font-medium"
                        :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                        @click="cobrando = !cobrando"
                    >
                        {{ cobrando ? 'Cancelar cobro' : 'Registrar pago' }}
                    </button>
                </div>
            </header>

            <!-- Con qué pagar. El panel es el mismo que usa el portal del padre. -->
            <div v-if="pagandoEnLinea" class="border-t px-6 py-4" :style="{ borderColor: 'var(--color-borde)' }">
                <PanelPagoEnLinea
                    :matricula-id="matricula.id"
                    :adeudos="cuenta.adeudos"
                    :pasarelas="pasarelas"
                    :seleccionados="seleccionados"
                    :cuentas="cuentasBancarias"
                >
                    <template #nota>
                        <template v-if="!seleccionados.length">
                            Marca cargos en la tabla si quieres pagar sólo algunos.
                        </template>
                    </template>
                </PanelPagoEnLinea>
            </div>

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

            <div v-if="cuenta.adeudos.length" class="overflow-x-auto">
                <table class="w-full text-sm">
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
                                <PildoraEstado :texto="adeudo.estatus.replace('_', ' ')" />
                                <!--
                                    De qué convenio es esta parcialidad. Sin
                                    decirlo, en la tabla aparece un cargo con una
                                    fecha que no cuadra con ninguna del plan y
                                    nadie sabe de dónde salió.
                                -->
                                <span
                                    v-if="adeudo.convenio_id"
                                    class="mt-0.5 block text-[11px]"
                                    :style="{ color: 'var(--color-suave)' }"
                                >Convenio #{{ adeudo.convenio_id }}</span>
                            </td>
                            <td class="px-6 py-3 text-right">
                                <button
                                    v-if="permisos.condonar && !['pagado', 'condonado', 'cancelado', 'en_convenio'].includes(adeudo.estatus)"
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
            </div>

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

            <div v-if="cuenta.pagos.length" class="overflow-x-auto">
                <table class="w-full text-sm">
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
                                    <!--
                                        Sólo de lo que de verdad entró: el recibo
                                        de un pago PENDIENTE sería un papel con el
                                        logo de la escuela por dinero que todavía
                                        no llegó.
                                    -->
                                    <a
                                        v-if="p.estatus === 'completado'"
                                        :href="`/finanzas/pagos/${p.id}/recibo`"
                                        target="_blank"
                                        class="text-xs font-medium"
                                        :style="{ color: 'var(--color-acento)' }"
                                    >
                                        Recibo
                                    </a>
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
            </div>

            <p v-else class="px-6 py-10 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                Sin pagos registrados.
            </p>
        </section>

        <section v-if="permisos.facturar && facturas.length" class="tarjeta overflow-hidden">
            <header class="px-6 py-4">
                <h2 class="text-base font-semibold">Facturas</h2>
            </header>
            <div class="overflow-x-auto">
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
            </div>
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
