<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonVolver from '@/Components/BotonVolver.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';
import { ICONOS } from '@/iconos';

interface PagoFacturable {
    id: number;
    monto: number;
    metodo: string | null;
    referencia: string | null;
    momento: string | null;
    concepto: string | null;
    deducible: boolean;
}

const props = defineProps<{
    matricula: { id: number; matricula: string; nombre: string | null };
    pagos: PagoFacturable[];
    ultimoReceptor: {
        rfc: string;
        razon_social: string;
        uso_cfdi: string;
        regimen_fiscal: string;
        cp: string;
    } | null;
    usoDefault: string;
    iedu: { impedimentos: string[] };
}>();

const pesos = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' });

// Se precargan los datos de su última factura: quien factura cada mes no
// debería recapturar su RFC y su régimen todas las veces.
const form = useForm({
    pago_ids: [] as number[],
    rfc: props.ultimoReceptor?.rfc ?? '',
    razon_social: props.ultimoReceptor?.razon_social ?? '',
    uso_cfdi: props.ultimoReceptor?.uso_cfdi ?? props.usoDefault,
    regimen_fiscal: props.ultimoReceptor?.regimen_fiscal ?? '605',
    cp: props.ultimoReceptor?.cp ?? '',
});

const total = computed(() =>
    props.pagos.filter((p) => form.pago_ids.includes(p.id)).reduce((s, p) => s + p.monto, 0),
);

/*
 * El aviso del complemento educativo, y por qué se arma AQUÍ y no en el
 * servidor.
 *
 * La decisión de verdad la toma `ComplementoEducativo` al emitir; esto es sólo
 * la advertencia previa, y depende de qué pagos lleve uno palomeados, o sea de
 * algo que el servidor todavía no sabe. Lo que se evita duplicar es el
 * CRITERIO: qué concepto es enseñanza lo dice el servidor por pago
 * (`deducible`), y qué le falta a la matrícula lo dice también él
 * (`iedu.impedimentos`). Aquí sólo se mira la selección.
 *
 * Y se avisa antes porque después no hay marcha atrás barata: una factura
 * timbrada sin complemento se corrige cancelando ante el SAT y volviendo a
 * emitir.
 */
const elegidos = computed(() => props.pagos.filter((p) => form.pago_ids.includes(p.id)));

const avisoIedu = computed<string | null>(() => {
    const conEnsenanza = elegidos.value.filter((p) => p.deducible);

    if (conEnsenanza.length === 0) return null;

    const otros = elegidos.value.filter((p) => !p.deducible);

    if (otros.length > 0) {
        const nombres = [...new Set(otros.map((p) => p.concepto ?? 'un pago sin aplicar'))];

        return `Esta factura mezcla enseñanza con otros cobros (${nombres.join(', ')}). El complemento educativo ampara el comprobante entero, así que saldría sin él y nadie podría deducirla. Factúralos por separado.`;
    }

    if (props.iedu.impedimentos.length > 0) {
        return `Saldría sin complemento educativo: ${props.iedu.impedimentos.join('; ')}.`;
    }

    return null;
});

/** Que sí lo va a llevar también se dice: es la mitad tranquilizadora. */
const llevaIedu = computed(
    () => elegidos.value.length > 0 && elegidos.value.every((p) => p.deducible) && avisoIedu.value === null,
);

function emitir(): void {
    form.post(`/finanzas/facturas/emitir/${props.matricula.id}`);
}

// Los más usados en una escuela. Se deja escribir otro porque el catálogo del
// SAT tiene decenas y encerrarlo en una lista corta obligaría a tocar código
// cada vez que llegue un régimen que no está.
const usos = [
    { valor: 'D10', texto: 'D10 — Pagos por servicios educativos (colegiaturas)' },
    { valor: 'G03', texto: 'G03 — Gastos en general' },
    { valor: 'S01', texto: 'S01 — Sin efectos fiscales' },
];

const regimenes = [
    { valor: '605', texto: '605 — Sueldos y salarios' },
    { valor: '612', texto: '612 — Personas físicas con actividad empresarial' },
    { valor: '616', texto: '616 — Sin obligaciones fiscales' },
    { valor: '601', texto: '601 — General de ley personas morales' },
    { valor: '603', texto: '603 — Personas morales con fines no lucrativos' },
];
</script>

<template>
    <Head title="Emitir factura" />

    <AppLayout titulo="Emitir factura">
        <section class="tarjeta p-6">
            <BotonVolver :href="`/finanzas/cuentas/${matricula.id}`" texto="Estado de cuenta" class="mb-4" />

            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="font-medium">{{ matricula.nombre }}</p>
                    <p class="font-mono text-sm" :style="{ color: 'var(--color-suave)' }">{{ matricula.matricula }}</p>
                </div>
            </div>
        </section>

        <form @submit.prevent="emitir">
            <TarjetaSeccion titulo="Pagos por facturar" :icono="ICONOS.dinero" sin-relleno>
                <template #descripcion>
                    Solo aparecen los pagos <strong>cobrados</strong> que no están ya en una factura vigente. Un pago sin confirmar es una promesa: facturarlo emitiría un comprobante por dinero que todavía puede no llegar.
                </template>
                <div v-if="pagos.length" class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-left text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">
                            <tr>
                                <th class="px-6 py-3"></th>
                                <th class="px-4 py-3 font-medium">Fecha</th>
                                <th class="px-4 py-3 font-medium">Concepto</th>
                                <th class="px-4 py-3 font-medium">Método</th>
                                <th class="px-4 py-3 font-medium">Referencia</th>
                                <th class="px-6 py-3 text-right font-medium">Monto</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="p in pagos" :key="p.id" class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
                                <td class="px-6 py-3">
                                    <input v-model="form.pago_ids" type="checkbox" :value="p.id" />
                                </td>
                                <td class="px-4 py-3">{{ p.momento ?? '—' }}</td>
                                <td class="px-4 py-3">
                                    {{ p.concepto ?? '—' }}
                                    <!--
                                        La insignia sólo aparece en lo que SÍ es
                                        enseñanza: marcar también lo que no lo es
                                        llenaría la columna de etiquetas y dejaría
                                        de señalar nada.
                                    -->
                                    <span
                                        v-if="p.deducible"
                                        class="ml-1 whitespace-nowrap rounded-full px-2 py-0.5 text-[11px] font-medium"
                                        :style="{ color: '#15803d', backgroundColor: 'color-mix(in srgb, #16a34a 14%, transparent)' }"
                                    >
                                        Deducible
                                    </span>
                                </td>
                                <td class="px-4 py-3">{{ p.metodo ?? '—' }}</td>
                                <td class="px-4 py-3 font-mono text-xs">{{ p.referencia ?? '—' }}</td>
                                <td class="px-6 py-3 text-right font-medium tabular-nums">{{ pesos.format(p.monto) }}</td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
                                <td colspan="5" class="px-6 py-3 text-right font-medium">Seleccionado</td>
                                <td class="px-6 py-3 text-right text-base font-semibold tabular-nums">
                                    {{ pesos.format(total) }}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <p v-else class="px-6 py-10 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                    No hay pagos cobrados pendientes de facturar.
                </p>
            </TarjetaSeccion>

            <div
                v-if="avisoIedu"
                class="tarjeta mt-4 border-l-4 p-4 text-sm"
                :style="{ borderLeftColor: '#f59e0b', backgroundColor: 'color-mix(in srgb, #f59e0b 8%, transparent)' }"
            >
                <p class="font-medium">Sin complemento educativo</p>
                <p class="mt-1" :style="{ color: 'var(--color-suave)' }">{{ avisoIedu }}</p>
            </div>

            <div
                v-else-if="llevaIedu"
                class="tarjeta mt-4 border-l-4 p-4 text-sm"
                :style="{ borderLeftColor: '#16a34a', backgroundColor: 'color-mix(in srgb, #16a34a 8%, transparent)' }"
            >
                <p class="font-medium">Llevará complemento educativo (IEDU)</p>
                <p class="mt-1" :style="{ color: 'var(--color-suave)' }">
                    Con él, quien recibe la factura puede deducir la colegiatura.
                </p>
            </div>

            <TarjetaSeccion v-if="pagos.length" titulo="Datos fiscales del receptor" descripcion="Se copian a la factura y ahí se congelan: si el receptor cambia de régimen el año que entra, este comprobante debe seguir diciendo lo que decía." :icono="ICONOS.documento">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <label class="text-sm">
                        <span class="mb-1 block font-medium">RFC</span>
                        <input
                            v-model="form.rfc"
                            type="text"
                            required
                            maxlength="13"
                            class="w-full rounded-lg border px-3 py-2 font-mono text-sm uppercase"
                            :style="{ borderColor: 'var(--color-borde)' }"
                        />
                        <span v-if="form.errors.rfc" class="text-xs text-red-600">{{ form.errors.rfc }}</span>
                    </label>

                    <label class="text-sm">
                        <span class="mb-1 block font-medium">Razón social</span>
                        <input
                            v-model="form.razon_social"
                            type="text"
                            required
                            class="w-full rounded-lg border px-3 py-2 text-sm"
                            :style="{ borderColor: 'var(--color-borde)' }"
                        />
                        <span v-if="form.errors.razon_social" class="text-xs text-red-600">{{ form.errors.razon_social }}</span>
                    </label>

                    <label class="text-sm">
                        <span class="mb-1 block font-medium">Uso del CFDI</span>
                        <input
                            v-model="form.uso_cfdi"
                            list="usos-cfdi"
                            required
                            maxlength="5"
                            class="w-full rounded-lg border px-3 py-2 text-sm"
                            :style="{ borderColor: 'var(--color-borde)' }"
                        />
                        <datalist id="usos-cfdi">
                            <option v-for="u in usos" :key="u.valor" :value="u.valor">{{ u.texto }}</option>
                        </datalist>
                    </label>

                    <label class="text-sm">
                        <span class="mb-1 block font-medium">Régimen fiscal</span>
                        <input
                            v-model="form.regimen_fiscal"
                            list="regimenes"
                            required
                            maxlength="5"
                            class="w-full rounded-lg border px-3 py-2 text-sm"
                            :style="{ borderColor: 'var(--color-borde)' }"
                        />
                        <datalist id="regimenes">
                            <option v-for="r in regimenes" :key="r.valor" :value="r.valor">{{ r.texto }}</option>
                        </datalist>
                    </label>

                    <label class="text-sm">
                        <span class="mb-1 block font-medium">Código postal fiscal</span>
                        <input
                            v-model="form.cp"
                            type="text"
                            required
                            maxlength="5"
                            class="w-full rounded-lg border px-3 py-2 text-sm"
                            :style="{ borderColor: 'var(--color-borde)' }"
                        />
                        <span class="text-xs" :style="{ color: 'var(--color-suave)' }">
                            Obligatorio en CFDI 4.0 y debe coincidir con el del SAT.
                        </span>
                        <span v-if="form.errors.cp" class="text-xs text-red-600">{{ form.errors.cp }}</span>
                    </label>
                </div>

                <BotonPrincipal
                    :procesando="form.processing"
                    :deshabilitado="form.pago_ids.length === 0"
                    texto="Emitir y timbrar"
                    cargando="Timbrando…"
                    class="mt-5"
                />
                <p class="mt-2 text-xs" :style="{ color: 'var(--color-suave)' }">
                    El timbrado corre en segundo plano porque el PAC puede tardar. El folio fiscal aparece
                    en cuanto responda.
                </p>
            </TarjetaSeccion>
        </form>
    </AppLayout>
</template>
