<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';
import { ICONOS } from '@/iconos';
import { hoyLocal } from '@/utils/fechas';

/**
 * Cuadrar el banco contra el sistema.
 *
 * Los dos saldos se CAPTURAN leyéndolos del estado de cuenta, y ése es el
 * punto: son una comprobación independiente del archivo. Si no cuadran, la
 * importación se rehúsa — un estado de cuenta incompleto concilia impecable y
 * se entrega como si estuviera revisado.
 */
interface Cuenta {
    id: number;
    nombre: string;
    banco: string | null;
    clabe: string | null;
    activa: boolean;
    tiene_mapeo: boolean;
    mapeo: Record<string, any>;
}

interface Estado {
    id: number;
    cuenta: string | null;
    banco: string | null;
    periodo_inicio: string | null;
    periodo_fin: string | null;
    saldo_inicial: number;
    saldo_final: number;
    neto: number;
    descuadre: number;
    cuadra: boolean;
    movimientos: number;
    sin_resolver: number;
    archivo: string | null;
}

const props = defineProps<{
    cuentas: Cuenta[];
    estados: Estado[];
    mapeoPorOmision: Record<string, any>;
}>();

const pesos = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' });

const conMapeo = computed(() => props.cuentas.filter((c) => c.tiene_mapeo));
const opcionesCuenta = computed(() =>
    conMapeo.value.map((c) => ({ valor: c.id, texto: `${c.nombre}${c.banco ? ` · ${c.banco}` : ''}` })),
);

// --- importar
const importando = ref(false);
const importar = useForm<{
    cuenta_bancaria_id: number | null;
    periodo_inicio: string;
    periodo_fin: string;
    saldo_inicial: string;
    saldo_final: string;
    archivo: File | null;
}>({
    cuenta_bancaria_id: null,
    periodo_inicio: hoyLocal(),
    periodo_fin: hoyLocal(),
    saldo_inicial: '',
    saldo_final: '',
    archivo: null,
});

function tomarArchivo(e: Event): void {
    importar.archivo = (e.target as HTMLInputElement).files?.[0] ?? null;
}

function enviarImportacion(): void {
    importar.post('/finanzas/conciliacion', { forceFormData: true, preserveScroll: true });
}

// --- mapeo por cuenta
const mapeando = ref<number | null>(null);
const mapeo = useForm({ ...props.mapeoPorOmision });

function abrirMapeo(c: Cuenta): void {
    mapeando.value = mapeando.value === c.id ? null : c.id;
    Object.assign(mapeo, { ...props.mapeoPorOmision, ...c.mapeo });
}

function guardarMapeo(c: Cuenta): void {
    mapeo.put(`/finanzas/conciliacion/cuentas/${c.id}/mapeo`, {
        preserveScroll: true,
        onSuccess: () => (mapeando.value = null),
    });
}

function retirar(e: Estado): void {
    if (!confirm(`¿Retirar el estado de cuenta del ${e.periodo_inicio} al ${e.periodo_fin}?`)) return;
    router.delete(`/finanzas/conciliacion/${e.id}`, { preserveScroll: true });
}
</script>

<template>
    <Head title="Conciliación bancaria" />

    <AppLayout titulo="Conciliación bancaria">
        <TarjetaSeccion
            titulo="Importar un estado de cuenta"
            descripcion="El archivo del banco, en CSV, con los dos saldos del periodo."
            :icono="ICONOS.dinero"
        >
            <!--
                Por qué se piden los saldos. Va arriba y con todas sus letras:
                quien los teclea creerá que son un adorno hasta que sepa que son
                la única forma de saber que el archivo está completo.
            -->
            <p class="text-sm" :style="{ color: 'var(--color-suave)' }">
                Los dos saldos se leen del estado de cuenta y se capturan aquí a mano, a propósito: son la
                comprobación de que el archivo está <strong>completo</strong>. Si el saldo inicial más los
                movimientos no da el final, la importación se rehúsa — un archivo al que le faltan renglones
                concilia impecable y se entrega como si estuviera revisado.
            </p>

            <p v-if="!conMapeo.length" class="mt-4 text-sm" :style="{ color: 'var(--color-peligro)' }">
                Ninguna cuenta dice todavía cómo se lee su archivo. Configura abajo el mapeo de columnas de al
                menos una antes de importar.
            </p>

            <div v-else class="mt-4">
                <BotonPrincipal
                    v-if="!importando"
                    tipo="button"
                    texto="Importar estado de cuenta"
                    icono="crear"
                    @click="importando = true"
                />

                <form v-else class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3" @submit.prevent="enviarImportacion">
                    <CampoSelect
                        v-model="importar.cuenta_bancaria_id"
                        etiqueta="Cuenta"
                        :opciones="opcionesCuenta"
                        vacio="Elige la cuenta…"
                        requerido
                        :error="importar.errors.cuenta_bancaria_id"
                    />
                    <CampoTexto v-model="importar.periodo_inicio" tipo="date" etiqueta="Del" requerido :error="importar.errors.periodo_inicio" />
                    <CampoTexto v-model="importar.periodo_fin" tipo="date" etiqueta="Al" requerido :error="importar.errors.periodo_fin" />
                    <CampoTexto v-model="importar.saldo_inicial" tipo="number" paso="0.01" etiqueta="Saldo inicial" requerido :error="importar.errors.saldo_inicial" />
                    <CampoTexto v-model="importar.saldo_final" tipo="number" paso="0.01" etiqueta="Saldo final" requerido :error="importar.errors.saldo_final" />
                    <div>
                        <label class="block text-xs" :style="{ color: 'var(--color-suave)' }">Archivo CSV</label>
                        <input type="file" accept=".csv,.txt" class="mt-1 text-xs" @change="tomarArchivo" />
                        <p v-if="importar.errors.archivo" class="mt-1 text-xs" :style="{ color: 'var(--color-peligro)' }">
                            {{ importar.errors.archivo }}
                        </p>
                    </div>
                    <div class="flex gap-2 sm:col-span-2 lg:col-span-3">
                        <BotonPrincipal :procesando="importar.processing" texto="Importar" icono="crear" />
                        <button
                            type="button"
                            class="rounded-lg border px-4 py-2 text-sm"
                            :style="{ borderColor: 'var(--color-borde)' }"
                            @click="importando = false; importar.reset()"
                        >Cancelar</button>
                    </div>
                </form>
            </div>
        </TarjetaSeccion>

        <TarjetaSeccion
            class="mt-6"
            titulo="Periodos importados"
            descripcion="Cuánto queda por explicar de cada uno."
            :icono="ICONOS.escudo"
            sin-relleno
        >
            <div v-if="estados.length" class="overflow-x-auto">
                <table class="w-full min-w-[52rem] text-sm">
                    <thead class="text-left text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">
                        <tr>
                            <th class="px-6 py-3 font-medium">Cuenta</th>
                            <th class="px-4 py-3 font-medium">Periodo</th>
                            <th class="px-4 py-3 text-right font-medium">Movimientos</th>
                            <th class="px-4 py-3 text-right font-medium">Sin explicar</th>
                            <th class="px-4 py-3 text-right font-medium">Cuadre</th>
                            <th class="px-6 py-3 text-right font-medium"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="e in estados" :key="e.id" class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
                            <td class="px-6 py-3">
                                <a class="font-medium underline" :href="`/finanzas/conciliacion/${e.id}`">{{ e.cuenta }}</a>
                                <span v-if="e.archivo" class="block text-[11px] break-all" :style="{ color: 'var(--color-suave)' }">{{ e.archivo }}</span>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">{{ e.periodo_inicio }} → {{ e.periodo_fin }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ e.movimientos }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                <!-- El cero se dice, no se calla: aquí «cero» es la meta. -->
                                <span :style="{ color: e.sin_resolver ? 'var(--color-peligro)' : 'var(--color-exito)' }">
                                    {{ e.sin_resolver }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                <span v-if="e.cuadra" :style="{ color: 'var(--color-exito)' }">Cuadra</span>
                                <span v-else :style="{ color: 'var(--color-peligro)' }">{{ pesos.format(e.descuadre) }}</span>
                            </td>
                            <td class="px-6 py-3 text-right">
                                <BotonAccion variante="eliminar" @click="retirar(e)" />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p v-else class="px-6 py-10 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                Todavía no se ha importado ningún estado de cuenta.
            </p>
        </TarjetaSeccion>

        <TarjetaSeccion
            class="mt-6"
            titulo="Cómo se lee el archivo de cada cuenta"
            descripcion="Cada banco exporta a su manera; aquí se dice cuál columna es cuál."
            :icono="ICONOS.dinero"
            sin-relleno
        >
            <div class="px-6 pt-4">
                <p class="text-sm" :style="{ color: 'var(--color-suave)' }">
                    Las columnas se nombran por su <strong>título</strong>, no por su posición: si el banco
                    agrega una columna al principio, por posición se leerían los importes de otra columna sin
                    que nada fallara. Los acentos y las mayúsculas dan igual.
                </p>
            </div>

            <div class="mt-4 overflow-x-auto">
                <table class="w-full min-w-[40rem] text-sm">
                    <tbody>
                        <template v-for="c in cuentas" :key="c.id">
                            <tr class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
                                <td class="px-6 py-3">
                                    <span class="font-medium">{{ c.nombre }}</span>
                                    <span class="block text-[11px]" :style="{ color: 'var(--color-suave)' }">
                                        {{ c.banco ?? 'sin banco' }}<template v-if="c.clabe"> · {{ c.clabe }}</template>
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <span
                                        class="rounded px-2 py-0.5 text-[11px]"
                                        :style="c.tiene_mapeo
                                            ? { background: 'color-mix(in srgb, var(--color-exito) 14%, transparent)', color: 'var(--color-exito)' }
                                            : { background: 'color-mix(in srgb, var(--color-suave) 14%, transparent)', color: 'var(--color-suave)' }"
                                    >{{ c.tiene_mapeo ? 'Con mapeo' : 'Sin mapeo' }}</span>
                                </td>
                                <td class="px-6 py-3 text-right">
                                    <BotonAccion :variante="mapeando === c.id ? 'cerrar' : 'editar'" @click="abrirMapeo(c)" />
                                </td>
                            </tr>
                            <tr v-if="mapeando === c.id" class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
                                <td colspan="3" class="px-6 py-4">
                                    <form class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3" @submit.prevent="guardarMapeo(c)">
                                        <CampoTexto v-model="mapeo.delimitador" etiqueta="Separador" ayuda="Normalmente una coma. Escribe «tab» si va tabulado." :error="mapeo.errors.delimitador" />
                                        <CampoTexto v-model="mapeo.renglon_encabezado" tipo="number" paso="1" min="1" etiqueta="Renglón del encabezado" ayuda="Muchos bancos ponen antes los datos de la cuenta." :error="mapeo.errors.renglon_encabezado" />
                                        <CampoTexto v-model="mapeo.formato_fecha" etiqueta="Formato de fecha" ayuda="d/m/Y, Y-m-d, d-m-Y…" :error="mapeo.errors.formato_fecha" />
                                        <CampoTexto v-model="mapeo.columna_fecha" etiqueta="Columna de la fecha" requerido :error="mapeo.errors.columna_fecha" />
                                        <CampoTexto v-model="mapeo.columna_descripcion" etiqueta="Columna del concepto" requerido :error="mapeo.errors.columna_descripcion" />
                                        <CampoTexto v-model="mapeo.columna_referencia" etiqueta="Columna de la referencia" ayuda="Opcional: hay bancos que la meten dentro del concepto." :error="mapeo.errors.columna_referencia" />
                                        <CampoTexto v-model="mapeo.columna_cargo" etiqueta="Columna de cargos" ayuda="Lo que SALE." :error="mapeo.errors.columna_cargo" />
                                        <CampoTexto v-model="mapeo.columna_abono" etiqueta="Columna de abonos" ayuda="Lo que ENTRA." :error="mapeo.errors.columna_abono" />
                                        <CampoTexto v-model="mapeo.columna_monto" etiqueta="…o una sola columna con signo" ayuda="Si tu banco trae una sola, escribe su título aquí y deja cargo y abono en blanco." :error="mapeo.errors.columna_monto" />
                                        <div class="sm:col-span-2 lg:col-span-3">
                                            <BotonPrincipal :procesando="mapeo.processing" texto="Guardar mapeo" />
                                        </div>
                                    </form>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <p v-if="!cuentas.length" class="px-6 py-8 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                No hay cuentas bancarias dadas de alta.
            </p>
        </TarjetaSeccion>
    </AppLayout>
</template>
