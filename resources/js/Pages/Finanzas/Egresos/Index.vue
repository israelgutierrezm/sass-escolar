<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';
import { ICONOS } from '@/iconos';
import { hoyLocal } from '@/utils/fechas';

/**
 * El registro de egresos: el dinero que sale.
 *
 * No es contabilidad —no hay órdenes de compra ni cuentas por pagar, y el
 * comprobante no se valida ni se timbra—: es control presupuestal, contra qué
 * partida se cargó cada salida.
 */
interface Egreso {
    id: number;
    fecha: string | null;
    centro: string | null;
    partida: string | null;
    monto: number;
    descripcion: string;
    beneficiario: string | null;
    referencia: string | null;
    comprobante: string | null;
    de_nomina: boolean;
}

const props = defineProps<{
    egresos: Egreso[];
    total: number;
    filtros: { ciclo: number; centro: number; partida: number };
    ciclos: { valor: number; texto: string }[];
    centros: { valor: number; texto: string }[];
    partidas: { valor: number; texto: string }[];
}>();

const pesos = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' });

const filtros = ref({ ...props.filtros });

function filtrar(): void {
    router.get('/finanzas/egresos', filtros.value, { preserveState: true, preserveScroll: true });
}

function vacio() {
    return {
        fecha: hoyLocal(),
        centro_costo_id: props.centros[0]?.valor ?? 0,
        partida_id: props.partidas[0]?.valor ?? 0,
        ciclo_id: props.filtros.ciclo,
        monto: '',
        descripcion: '',
        beneficiario: '',
        referencia: '',
        comprobante: null as File | null,
    };
}

const creando = ref(false);
const editando = ref<number | null>(null);
const alta = useForm(vacio());

function tomarArchivo(e: Event): void {
    alta.comprobante = (e.target as HTMLInputElement).files?.[0] ?? null;
}

function enviar(id?: number): void {
    alta.post(id ? `/finanzas/egresos/${id}` : '/finanzas/egresos', {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            alta.reset();
            creando.value = false;
            editando.value = null;
        },
    });
}

function abrirEdicion(e: Egreso): void {
    editando.value = editando.value === e.id ? null : e.id;
    creando.value = false;
    alta.fecha = e.fecha ?? hoyLocal();
    alta.monto = String(e.monto);
    alta.descripcion = e.descripcion;
    alta.beneficiario = e.beneficiario ?? '';
    alta.referencia = e.referencia ?? '';
    alta.ciclo_id = props.filtros.ciclo;
}

function retirar(e: Egreso): void {
    if (!confirm(`¿Retirar el egreso de ${pesos.format(e.monto)}?`)) return;
    router.delete(`/finanzas/egresos/${e.id}`, { preserveScroll: true });
}
</script>

<template>
    <Head title="Egresos" />

    <AppLayout titulo="Egresos">
        <TarjetaSeccion
            titulo="El dinero que sale"
            descripcion="Contra qué centro de costo y qué partida se cargó cada salida."
            :icono="ICONOS.dinero"
            sin-relleno
        >
            <div class="px-6 pt-4">
                <!--
                    Lo que esto NO es. Sin decirlo, alguien lo va a usar como
                    contabilidad y a descubrir en abril que no lo era.
                -->
                <p class="text-sm" :style="{ color: 'var(--color-suave)' }">
                    Esto es <strong>control presupuestal</strong>, no contabilidad: no hay órdenes de compra ni
                    cuentas por pagar, y el comprobante que se adjunta no se valida ni se timbra. Lo que este
                    registro contesta es en qué se está yendo el presupuesto de cada área.
                </p>

                <div class="mt-4 grid gap-4 sm:grid-cols-3">
                    <CampoSelect v-model="filtros.ciclo" etiqueta="Ciclo" :opciones="ciclos" @update:model-value="filtrar" />
                    <CampoSelect v-model="filtros.centro" etiqueta="Centro de costo" :opciones="centros" vacio="Todos" @update:model-value="filtrar" />
                    <CampoSelect v-model="filtros.partida" etiqueta="Partida" :opciones="partidas" vacio="Todas" @update:model-value="filtrar" />
                </div>

                <p class="mt-3 text-sm">
                    Total de lo filtrado: <strong class="tabular-nums">{{ pesos.format(total) }}</strong>
                </p>

                <div class="mt-4">
                    <BotonPrincipal
                        v-if="!creando"
                        tipo="button"
                        texto="Registrar egreso"
                        icono="crear"
                        :deshabilitado="!centros.length || !partidas.length"
                        @click="creando = true; editando = null; alta.reset()"
                    />
                    <p v-if="!centros.length || !partidas.length" class="mt-2 text-sm" :style="{ color: 'var(--color-peligro)' }">
                        Hacen falta al menos un centro de costo y una partida. Se crean en
                        <a class="underline" href="/finanzas/presupuesto">Presupuesto</a>.
                    </p>
                </div>

                <form v-if="creando" class="mt-4 grid gap-4 rounded-lg border p-4 sm:grid-cols-2 lg:grid-cols-3" :style="{ borderColor: 'var(--color-borde)' }" @submit.prevent="enviar()">
                    <CampoTexto v-model="alta.fecha" tipo="date" etiqueta="Fecha" requerido :error="alta.errors.fecha" />
                    <CampoSelect v-model="alta.centro_costo_id" etiqueta="Centro de costo" :opciones="centros" requerido :error="alta.errors.centro_costo_id" />
                    <CampoSelect v-model="alta.partida_id" etiqueta="Partida" :opciones="partidas" requerido :error="alta.errors.partida_id" />
                    <CampoTexto v-model="alta.monto" tipo="number" paso="0.01" min="0.01" etiqueta="Importe" requerido :error="alta.errors.monto" />
                    <CampoTexto v-model="alta.beneficiario" etiqueta="A quién se le pagó" :error="alta.errors.beneficiario" />
                    <CampoTexto v-model="alta.referencia" etiqueta="Referencia" :error="alta.errors.referencia" ayuda="Folio de la factura, número de cheque…" />
                    <div class="sm:col-span-2">
                        <CampoTexto v-model="alta.descripcion" etiqueta="En qué se gastó" requerido :error="alta.errors.descripcion" />
                    </div>
                    <div>
                        <label class="block text-xs" :style="{ color: 'var(--color-suave)' }">Comprobante</label>
                        <input type="file" accept=".pdf,.xml,.jpg,.jpeg,.png" class="mt-1 text-xs" @change="tomarArchivo" />
                        <p v-if="alta.errors.comprobante" class="mt-1 text-xs" :style="{ color: 'var(--color-peligro)' }">{{ alta.errors.comprobante }}</p>
                    </div>
                    <div class="flex gap-2 sm:col-span-2 lg:col-span-3">
                        <BotonPrincipal :procesando="alta.processing" texto="Guardar" icono="crear" />
                        <button type="button" class="rounded-lg border px-4 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" @click="creando = false; alta.reset()">Cancelar</button>
                    </div>
                </form>
            </div>

            <div v-if="egresos.length" class="mt-4 overflow-x-auto">
                <table class="w-full min-w-[52rem] text-sm">
                    <thead class="text-left text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">
                        <tr>
                            <th class="px-6 py-3 font-medium">Fecha</th>
                            <th class="px-4 py-3 font-medium">Concepto</th>
                            <th class="px-4 py-3 font-medium">Centro / partida</th>
                            <th class="px-4 py-3 text-right font-medium">Importe</th>
                            <th class="px-6 py-3 text-right font-medium"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="e in egresos" :key="e.id">
                            <tr class="border-t align-top" :style="{ borderColor: 'var(--color-borde)' }">
                                <td class="px-6 py-3 whitespace-nowrap">{{ e.fecha }}</td>
                                <td class="px-4 py-3">
                                    <span class="block break-words">{{ e.descripcion }}</span>
                                    <span v-if="e.beneficiario || e.referencia" class="block text-[11px]" :style="{ color: 'var(--color-suave)' }">
                                        {{ e.beneficiario }}<template v-if="e.referencia"> · {{ e.referencia }}</template>
                                    </span>
                                    <a v-if="e.comprobante" class="text-[11px] underline" :href="`/finanzas/egresos/${e.id}/comprobante`">{{ e.comprobante }}</a>
                                    <!--
                                        De dónde salió. Un egreso de nómina no se
                                        edita ni se retira desde aquí, y decirlo
                                        evita que alguien lo intente y crea que
                                        el botón falla.
                                    -->
                                    <span v-if="e.de_nomina" class="mt-0.5 block text-[11px]" :style="{ color: 'var(--color-acento)' }">
                                        Viene de un periodo de nómina
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="block">{{ e.centro }}</span>
                                    <span class="block text-[11px]" :style="{ color: 'var(--color-suave)' }">{{ e.partida }}</span>
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums whitespace-nowrap">{{ pesos.format(e.monto) }}</td>
                                <td class="px-6 py-3 text-right">
                                    <div v-if="!e.de_nomina" class="flex items-center justify-end gap-2">
                                        <BotonAccion :variante="editando === e.id ? 'cerrar' : 'editar'" @click="abrirEdicion(e)" />
                                        <BotonAccion variante="eliminar" @click="retirar(e)" />
                                    </div>
                                </td>
                            </tr>

                            <tr v-if="editando === e.id" class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
                                <td colspan="5" class="px-6 py-4">
                                    <form class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3" @submit.prevent="enviar(e.id)">
                                        <CampoTexto v-model="alta.fecha" tipo="date" etiqueta="Fecha" requerido :error="alta.errors.fecha" />
                                        <CampoSelect v-model="alta.centro_costo_id" etiqueta="Centro de costo" :opciones="centros" requerido :error="alta.errors.centro_costo_id" />
                                        <CampoSelect v-model="alta.partida_id" etiqueta="Partida" :opciones="partidas" requerido :error="alta.errors.partida_id" />
                                        <CampoTexto v-model="alta.monto" tipo="number" paso="0.01" min="0.01" etiqueta="Importe" requerido :error="alta.errors.monto" />
                                        <CampoTexto v-model="alta.beneficiario" etiqueta="A quién se le pagó" :error="alta.errors.beneficiario" />
                                        <CampoTexto v-model="alta.referencia" etiqueta="Referencia" :error="alta.errors.referencia" />
                                        <div class="sm:col-span-2">
                                            <CampoTexto v-model="alta.descripcion" etiqueta="En qué se gastó" requerido :error="alta.errors.descripcion" />
                                        </div>
                                        <div>
                                            <label class="block text-xs" :style="{ color: 'var(--color-suave)' }">Reemplazar comprobante</label>
                                            <input type="file" accept=".pdf,.xml,.jpg,.jpeg,.png" class="mt-1 text-xs" @change="tomarArchivo" />
                                        </div>
                                        <div class="sm:col-span-2 lg:col-span-3">
                                            <BotonPrincipal :procesando="alta.processing" texto="Guardar" />
                                        </div>
                                    </form>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <p v-else class="px-6 py-10 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                No hay egresos registrados con esos filtros.
            </p>
        </TarjetaSeccion>
    </AppLayout>
</template>
