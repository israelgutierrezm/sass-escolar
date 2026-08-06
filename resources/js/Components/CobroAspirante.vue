<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import { ICONOS } from '@/iconos';
import { toast } from 'vue-sonner';

/**
 * Lo que el aspirante debe y lo que ya pagó.
 *
 * La ficha, el examen, la inscripción: se cobran ANTES de que exista matrícula,
 * y al convertirlo en alumno estos cargos pasan a ella —de eso se encarga
 * `ReligadorFinanzas`—, así que el estado de cuenta del alumno nace completo.
 *
 * Vive en un componente y no dentro de `Aspirantes/Detalle.vue` porque esa
 * pantalla ya es larga y esto es un asunto entero: cargos, pagos y dos formas.
 */
interface Cargo {
    id: number;
    concepto: string | null;
    total: number;
    saldo: number;
    vencimiento: string | null;
    vencido: boolean;
    estatus: string;
}

const props = defineProps<{
    aspiranteId: number;
    cobro: {
        cargos: Cargo[];
        pagos: {
            id: number;
            monto: number;
            metodo: string | null;
            referencia: string | null;
            estatus: string;
            cobrado: boolean;
            momento: string | null;
        }[];
        saldo: number;
        conceptos: { id: number; nombre: string }[];
        metodos: { id: number; nombre: string }[];
    };
    puedeCobrar: boolean;
}>();

const pesos = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' });

const panel = ref<'ninguno' | 'cargo' | 'pago'>('ninguno');

// ── Generar cargo ──────────────────────────────────────────────────────────

const formCargo = useForm({
    concepto_id: null as number | null,
    monto: '' as string | number,
    fecha_vencimiento: '',
});

function generarCargo(): void {
    formCargo.post(`/aspirantes/${props.aspiranteId}/cobro/cargos`, {
        preserveScroll: true,
        onSuccess: () => {
            panel.value = 'ninguno';
            formCargo.reset();
        },
    });
}

// ── Registrar pago ─────────────────────────────────────────────────────────

const formPago = useForm({
    metodo_pago_id: null as number | null,
    monto: '' as string | number,
    referencia: '',
    adeudo_ids: [] as number[],
});

/** Sólo se puede aplicar un pago a lo que sigue abierto. */
const abiertos = computed(() => props.cobro.cargos.filter((c) => c.saldo > 0));

/**
 * Lo marcado suma solo el monto.
 *
 * Sin esto hay que sumar a mano los cargos elegidos y teclear el total, que es
 * de donde salen los pagos que no cuadran con lo que se quiso cubrir.
 */
function alternarCargo(cargo: Cargo): void {
    formPago.adeudo_ids = formPago.adeudo_ids.includes(cargo.id)
        ? formPago.adeudo_ids.filter((id) => id !== cargo.id)
        : [...formPago.adeudo_ids, cargo.id];

    const suma = abiertos.value
        .filter((c) => formPago.adeudo_ids.includes(c.id))
        .reduce((total, c) => total + c.saldo, 0);

    formPago.monto = suma > 0 ? suma.toFixed(2) : '';
}

function registrarPago(): void {
    if (Number(formPago.monto) <= 0) {
        toast.error('Captura cuánto se está pagando.');

        return;
    }

    formPago.post(`/aspirantes/${props.aspiranteId}/cobro/pagos`, {
        preserveScroll: true,
        onSuccess: () => {
            panel.value = 'ninguno';
            formPago.reset();
        },
    });
}

/**
 * Cancelar, no borrar.
 *
 * Un cargo con pagos encima no puede desaparecer sin dejar el dinero apuntando
 * a la nada —el servidor lo impide—, y el rastro de que se cobró de más es
 * justo lo que alguien va a querer revisar después.
 */
function cancelar(cargo: Cargo): void {
    if (!confirm(`¿Cancelar el cargo de ${cargo.concepto ?? 'este concepto'} por ${pesos.format(cargo.total)}?`)) {
        return;
    }

    router.delete(`/aspirantes/cargos/${cargo.id}`, { preserveScroll: true });
}

function abrirPago(): void {
    panel.value = 'pago';
    formPago.reset();
    formPago.clearErrors();
    // Lo más común es cobrarlo todo de una vez.
    formPago.monto = props.cobro.saldo > 0 ? props.cobro.saldo.toFixed(2) : '';
}

// ── Textos ─────────────────────────────────────────────────────────────────

const COLOR_ESTATUS: Record<string, { backgroundColor: string; color: string }> = {
    pagado: { backgroundColor: 'color-mix(in srgb, #16a34a 12%, transparent)', color: '#16a34a' },
    parcial: { backgroundColor: 'color-mix(in srgb, #f59e0b 14%, transparent)', color: '#b45309' },
    cancelado: { backgroundColor: 'var(--color-fondo)', color: 'var(--color-suave)' },
    condonado: { backgroundColor: 'var(--color-fondo)', color: 'var(--color-suave)' },
};

const conceptosDisponibles = computed(() =>
    props.cobro.conceptos.map((c) => ({ valor: c.id, texto: c.nombre })),
);

const metodosDisponibles = computed(() =>
    props.cobro.metodos.map((m) => ({ valor: m.id, texto: m.nombre })),
);
</script>

<template>
    <TarjetaSeccion
        titulo="Pagos de admisión"
        descripcion="Su ficha, su examen. Al convertirlo en alumno estos cargos pasan a su matrícula."
        :icono="ICONOS.dinero"
    >
        <template #insignia>
            <span
                v-if="cobro.cargos.length"
                class="rounded-full px-2.5 py-0.5 text-xs font-medium tabular-nums"
                :style="cobro.saldo > 0
                    ? { backgroundColor: 'color-mix(in srgb, #dc2626 10%, transparent)', color: '#dc2626' }
                    : { backgroundColor: 'color-mix(in srgb, #16a34a 12%, transparent)', color: '#16a34a' }"
            >
                {{ cobro.saldo > 0 ? `Debe ${pesos.format(cobro.saldo)}` : 'Sin adeudo' }}
            </span>
        </template>

        <!-- Cargos -->
        <div v-if="cobro.cargos.length" class="-mx-1 overflow-x-auto px-1">
            <table class="w-full min-w-[32rem] text-sm">
                <thead class="text-left text-xs uppercase tracking-wide text-suave">
                    <tr>
                        <th class="pb-2 font-medium">Concepto</th>
                        <th class="pb-2 font-medium">Vence</th>
                        <th class="pb-2 text-right font-medium">Total</th>
                        <th class="pb-2 text-right font-medium">Saldo</th>
                        <th class="pb-2"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="c in cobro.cargos" :key="c.id" class="border-t border-borde">
                        <td class="py-2">
                            {{ c.concepto ?? '—' }}
                            <span
                                v-if="['pagado', 'parcial', 'cancelado', 'condonado'].includes(c.estatus)"
                                class="ml-1.5 rounded-full px-2 py-0.5 text-xs capitalize"
                                :style="COLOR_ESTATUS[c.estatus]"
                            >{{ c.estatus }}</span>
                        </td>
                        <td class="py-2" :class="c.vencido ? 'font-medium text-red-600' : ''">
                            {{ c.vencimiento }}
                            <span v-if="c.vencido" class="text-xs">(vencido)</span>
                        </td>
                        <td class="py-2 text-right tabular-nums">{{ pesos.format(c.total) }}</td>
                        <td class="py-2 text-right font-medium tabular-nums">
                            {{ c.saldo > 0 ? pesos.format(c.saldo) : '—' }}
                        </td>
                        <td class="py-2 text-right">
                            <BotonAccion
                                v-if="puedeCobrar && c.saldo > 0 && c.estatus !== 'cancelado'"
                                variante="eliminar"
                                @click="cancelar(c)"
                            />
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p v-else class="text-sm text-suave">
            Todavía no se le ha generado ningún cargo. Si la escuela cobra ficha o examen de
            admisión, genéralo aquí y le aparecerá en su portal.
        </p>

        <!-- Pagos recibidos -->
        <div v-if="cobro.pagos.length" class="mt-5 border-t border-borde pt-4">
            <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-suave">Pagos recibidos</p>
            <ul class="space-y-1.5">
                <li v-for="p in cobro.pagos" :key="p.id" class="flex flex-wrap items-baseline justify-between gap-2 text-sm">
                    <span>
                        {{ p.metodo ?? 'Sin método' }}
                        <span v-if="p.referencia" class="text-suave">· {{ p.referencia }}</span>
                        <span class="text-xs text-suave"> · {{ p.momento }}</span>
                    </span>
                    <span class="flex items-center gap-2">
                        <!-- Un pago sin confirmar todavía NO es dinero: el cargo
                             sigue abierto y quien lo mira debe saberlo. -->
                        <span
                            v-if="!p.cobrado"
                            class="rounded-full px-2 py-0.5 text-xs"
                            style="background-color: color-mix(in srgb, #f59e0b 14%, transparent); color: #b45309"
                        >{{ p.estatus }}</span>
                        <span class="font-medium tabular-nums">{{ pesos.format(p.monto) }}</span>
                    </span>
                </li>
            </ul>
        </div>

        <template v-if="puedeCobrar" #pie>
            <div v-if="panel === 'ninguno'" class="flex flex-wrap items-center gap-2">
                <BotonAccion variante="nuevo" texto="Generar cargo" @click="panel = 'cargo'" />
                <button
                    v-if="abiertos.length"
                    type="button"
                    class="rounded-lg px-4 py-2 text-sm font-medium text-white"
                    :style="{ backgroundColor: 'var(--color-acento)' }"
                    @click="abrirPago"
                >
                    Registrar pago
                </button>
            </div>

            <!-- Generar cargo -->
            <form v-else-if="panel === 'cargo'" class="space-y-4" @submit.prevent="generarCargo">
                <div class="grid gap-4 sm:grid-cols-3">
                    <CampoSelect
                        v-model="formCargo.concepto_id"
                        etiqueta="Concepto"
                        requerido
                        vacio="Elige…"
                        :opciones="conceptosDisponibles"
                        :error="formCargo.errors.concepto_id"
                    />
                    <CampoTexto
                        v-model="formCargo.monto"
                        tipo="number"
                        etiqueta="Monto"
                        requerido
                        :error="formCargo.errors.monto"
                    />
                    <CampoTexto
                        v-model="formCargo.fecha_vencimiento"
                        tipo="date"
                        etiqueta="Fecha límite"
                        requerido
                        ayuda="Sin ella el cargo no se podría marcar como vencido."
                        :error="formCargo.errors.fecha_vencimiento"
                    />
                </div>
                <div class="flex items-center gap-2">
                    <BotonPrincipal :procesando="formCargo.processing" texto="Generar cargo" icono="crear" />
                    <button type="button" class="text-sm text-suave" @click="panel = 'ninguno'">Cancelar</button>
                </div>
            </form>

            <!-- Registrar pago -->
            <form v-else class="space-y-4" @submit.prevent="registrarPago">
                <div v-if="abiertos.length > 1">
                    <p class="mb-1 text-sm font-medium">A qué cargos se aplica</p>
                    <p class="mb-2 text-xs text-suave">
                        Sin marcar ninguno se cubren los más vencidos primero.
                    </p>
                    <label v-for="c in abiertos" :key="c.id" class="fila-casilla text-sm">
                        <input
                            type="checkbox"
                            class="mt-0.5"
                            :checked="formPago.adeudo_ids.includes(c.id)"
                            @change="alternarCargo(c)"
                        />
                        <span>
                            {{ c.concepto ?? 'Cargo' }}
                            <span class="text-suave">· {{ pesos.format(c.saldo) }}</span>
                        </span>
                    </label>
                </div>

                <div class="grid gap-4 sm:grid-cols-3">
                    <CampoSelect
                        v-model="formPago.metodo_pago_id"
                        etiqueta="Método de pago"
                        requerido
                        vacio="Elige…"
                        :opciones="metodosDisponibles"
                        ayuda="Los que exigen confirmación no liquidan hasta confirmarse."
                        :error="formPago.errors.metodo_pago_id"
                    />
                    <CampoTexto
                        v-model="formPago.monto"
                        tipo="number"
                        etiqueta="Monto"
                        requerido
                        :error="formPago.errors.monto"
                    />
                    <CampoTexto
                        v-model="formPago.referencia"
                        etiqueta="Referencia"
                        ayuda="Folio del recibo o de la transferencia."
                        :error="formPago.errors.referencia"
                    />
                </div>

                <div class="flex items-center gap-2">
                    <BotonPrincipal :procesando="formPago.processing" texto="Registrar pago" />
                    <button type="button" class="text-sm text-suave" @click="panel = 'ninguno'">Cancelar</button>
                </div>
            </form>
        </template>
    </TarjetaSeccion>
</template>
