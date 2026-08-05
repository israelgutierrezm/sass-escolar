<script setup lang="ts">
import { Head, useForm, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import CampoCasillas from '@/Components/CampoCasillas.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import PildoraEstado from '@/Components/PildoraEstado.vue';
import BarraListado from '@/Components/BarraListado.vue';
import { ICONOS } from '@/iconos';

interface Descuento {
    id: number;
    clave: string;
    nombre: string;
    descripcion: string | null;
    tipo: string;
    modo: string;
    valor: number;
    tope_monto: number | null;
    dias_anticipacion: number | null;
    vigente_desde: string | null;
    vigente_hasta: string | null;
    conceptos: string[];
    activo: boolean;
}

const props = defineProps<{
    descuentos: Descuento[];
    catalogoConceptos: { id: number; nombre: string }[];
    tipos: { valor: string; etiqueta: string }[];
    filtros: { busqueda: string; tipo: string | null; activo: string | null };
}>();

/*
 * Lo que se pregunta al buscar un descuento: de qué tipo es y si sigue vivo.
 * Es la forma de contestar «qué le puedo aplicar a este alumno hoy» sin leer
 * la lista entera.
 */
const definicionFiltros = computed(() => [
    {
        clave: 'tipo',
        etiqueta: 'Tipo',
        opciones: props.tipos.map((t) => ({ valor: t.valor, texto: t.etiqueta })),
    },
    { clave: 'activo', etiqueta: 'Solo activos', tipo: 'booleano' as const },
]);

const pesos = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' });

const creando = ref(false);
const editando = ref<number | null>(null);

function vacio() {
    return {
        clave: '', nombre: '', descripcion: '',
        tipo: 'pago_anticipado', modo: 'porcentaje',
        valor: 0.05, tope_monto: '' as string | number,
        dias_anticipacion: 5 as number | null,
        vigente_desde: '', vigente_hasta: '',
        conceptos: [] as number[],
        activo: true,
    };
}

const form = useForm(vacio());

const opcionesConcepto = computed(() => props.catalogoConceptos.map((c) => ({ valor: c.id, texto: c.nombre })));
const esAnticipado = computed(() => form.tipo === 'pago_anticipado');
const esCampana = computed(() => form.tipo === 'campana');

function abrirNuevo(): void {
    form.defaults(vacio());
    form.reset();
    creando.value = true;
    editando.value = null;
}

function abrirEdicion(d: Descuento): void {
    editando.value = d.id;
    creando.value = false;
    form.clave = d.clave;
    form.nombre = d.nombre;
    form.descripcion = d.descripcion ?? '';
    form.tipo = d.tipo;
    form.modo = d.modo;
    form.valor = d.valor;
    form.tope_monto = d.tope_monto ?? '';
    form.dias_anticipacion = d.dias_anticipacion;
    form.vigente_desde = d.vigente_desde ?? '';
    form.vigente_hasta = d.vigente_hasta ?? '';
    form.conceptos = props.catalogoConceptos.filter((c) => d.conceptos.includes(c.nombre)).map((c) => c.id);
    form.activo = d.activo;
}

function cerrar(): void {
    creando.value = false;
    editando.value = null;
    form.reset();
}

function guardar(): void {
    if (editando.value !== null) {
        form.put(`/finanzas/descuentos/${editando.value}`, { preserveScroll: true, onSuccess: cerrar });
        return;
    }
    form.post('/finanzas/descuentos', { preserveScroll: true, onSuccess: cerrar });
}

function eliminar(d: Descuento): void {
    if (!confirm(`¿Eliminar el descuento "${d.nombre}"?`)) return;
    router.delete(`/finanzas/descuentos/${d.id}`, { preserveScroll: true });
}

function textoValor(d: Descuento): string {
    return d.modo === 'porcentaje' ? `${Math.round(d.valor * 100)}%` : pesos.format(d.valor);
}

const etiquetaTipo: Record<string, string> = {
    pago_anticipado: 'Pago anticipado', campana: 'Campaña', manual: 'Manual',
};
const colorTipo: Record<string, string> = {
    pago_anticipado: '#16a34a', campana: '#2563eb', manual: 'var(--color-suave)',
};
</script>

<template>
    <Head title="Descuentos" />

    <AppLayout titulo="Descuentos">
        <BarraListado
            url="/finanzas/descuentos"
            :valores="filtros"
            :filtros="definicionFiltros"
            placeholder="Buscar por nombre o clave…"
            titulo="Descuentos comerciales"
            descripcion="Lo contrario al recargo. A diferencia de una beca, no se le otorga a nadie: depende de cuándo o cómo paga. El de pago anticipado premia pagar antes del límite; el de campaña vive en una ventana de fechas."
            :icono="ICONOS.dinero"
            :puede-crear="!creando && editando === null"
            nuevo-texto="Nuevo descuento"
            @nuevo="abrirNuevo"
        >
            <template #conteo>
                <span class="rounded-full px-3 py-1 text-xs font-medium" :style="{ backgroundColor: 'color-mix(in srgb, var(--color-acento) 12%, transparent)', color: 'var(--color-acento)' }">
                    {{ descuentos.length }} {{ descuentos.length === 1 ? 'descuento' : 'descuentos' }}
                </span>
            </template>
        </BarraListado>

        <!-- Alta / edición -->
        <form v-if="creando || editando !== null" @submit.prevent="guardar">
            <TarjetaSeccion
                :titulo="editando !== null ? 'Editar descuento' : 'Nuevo descuento'"
                descripcion="Cuánto descuenta, cuándo aplica y sobre qué conceptos."
                :icono="ICONOS.dinero"
            >
                <div class="grid gap-4 sm:grid-cols-4">
                    <CampoTexto v-model="form.clave" etiqueta="Clave" mono requerido marcador="PRONTO5" :error="form.errors.clave" />
                    <div class="sm:col-span-3">
                        <CampoTexto v-model="form.nombre" etiqueta="Nombre" requerido marcador="5% por pago anticipado" :error="form.errors.nombre" />
                    </div>
                    <div class="sm:col-span-4">
                        <CampoTexto v-model="form.descripcion" etiqueta="Descripción" :error="form.errors.descripcion" />
                    </div>

                    <CampoSelect
                        v-model="form.tipo"
                        etiqueta="Cuándo aplica"
                        :opciones="tipos.map((t) => ({ valor: t.valor, texto: t.etiqueta }))"
                        :error="form.errors.tipo"
                    />
                    <CampoSelect
                        v-model="form.modo"
                        etiqueta="Tipo de descuento"
                        :opciones="[{ valor: 'porcentaje', texto: 'Porcentaje' }, { valor: 'monto_fijo', texto: 'Monto fijo' }]"
                        :error="form.errors.modo"
                    />
                    <CampoTexto
                        v-model="form.valor"
                        tipo="number"
                        step="0.0001"
                        min="0"
                        :etiqueta="form.modo === 'porcentaje' ? 'Valor (0.05 = 5%)' : 'Monto'"
                        requerido
                        :error="form.errors.valor"
                    />
                    <CampoTexto v-model="form.tope_monto" tipo="number" step="0.01" min="0" etiqueta="Tope" :error="form.errors.tope_monto" ayuda="En blanco, sin tope." />

                    <CampoTexto
                        v-if="esAnticipado"
                        v-model="form.dias_anticipacion"
                        tipo="number"
                        min="1"
                        max="365"
                        etiqueta="Días de anticipación"
                        requerido
                        :error="form.errors.dias_anticipacion"
                        ayuda="Cuántos días antes del límite hay que pagar."
                    />

                    <template v-if="esCampana">
                        <CampoTexto v-model="form.vigente_desde" tipo="date" etiqueta="Campaña desde" :error="form.errors.vigente_desde" />
                        <CampoTexto v-model="form.vigente_hasta" tipo="date" etiqueta="Campaña hasta" :error="form.errors.vigente_hasta" />
                    </template>
                </div>

                <div class="mt-5 border-t pt-5" :style="{ borderColor: 'var(--color-borde)' }">
                    <CampoCasillas
                        v-model="form.conceptos"
                        etiqueta="¿Sobre qué conceptos aplica?"
                        :opciones="opcionesConcepto"
                        :error="form.errors.conceptos"
                        ayuda="Sin marcar ninguno, aplica a todos."
                    />
                </div>

                <label class="mt-5 flex items-start gap-2 border-t pt-5 text-sm" :style="{ borderColor: 'var(--color-borde)' }">
                    <input v-model="form.activo" type="checkbox" class="mt-0.5 rounded" />
                    <span class="font-medium">Activo</span>
                </label>

                <template #pie>
                    <div class="flex items-center gap-2">
                        <BotonPrincipal :procesando="form.processing" :texto="editando !== null ? 'Guardar cambios' : 'Crear descuento'" />
                        <button type="button" class="rounded-lg border px-4 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" @click="cerrar">Cancelar</button>
                    </div>
                </template>
            </TarjetaSeccion>
        </form>

        <!-- Listado -->
        <section class="tarjeta overflow-hidden">
            <div class="overflow-x-auto">
                <table v-if="descuentos.length" class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-[11px] uppercase tracking-wider" :style="{ color: 'var(--color-suave)', backgroundColor: 'color-mix(in srgb, var(--color-suave) 6%, transparent)' }">
                            <th class="px-6 py-3 font-semibold">Descuento</th>
                            <th class="px-4 py-3 font-semibold">Cuándo</th>
                            <th class="px-4 py-3 font-semibold text-center">Valor</th>
                            <th class="px-4 py-3 font-semibold">Aplica a</th>
                            <th class="px-4 py-3 font-semibold">Estado</th>
                            <th class="px-6 py-3 font-semibold text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="d in descuentos" :key="d.id" class="fila-nueva border-t transition-colors" :class="d.activo ? '' : 'opacity-60'" :style="{ borderColor: 'var(--color-borde)' }">
                            <td class="px-6 py-4">
                                <span class="block font-semibold text-contenido">{{ d.nombre }}</span>
                                <span class="mt-0.5 block font-mono text-[11px]" :style="{ color: 'var(--color-suave)' }">{{ d.clave }}</span>
                            </td>

                            <td class="px-4 py-4">
                                <PildoraEstado :texto="etiquetaTipo[d.tipo] ?? d.tipo" :color="colorTipo[d.tipo]" sin-capitalizar />
                                <span v-if="d.tipo === 'pago_anticipado' && d.dias_anticipacion" class="mt-0.5 block text-[11px]" :style="{ color: 'var(--color-suave)' }">
                                    {{ d.dias_anticipacion }} días antes
                                </span>
                                <span v-else-if="d.tipo === 'campana'" class="mt-0.5 block text-[11px] tabular-nums" :style="{ color: 'var(--color-suave)' }">
                                    {{ d.vigente_desde ?? '—' }} → {{ d.vigente_hasta ?? 'sin fin' }}
                                </span>
                            </td>

                            <td class="px-4 py-4 text-center">
                                <span class="inline-block rounded-full px-2.5 py-1 text-xs font-semibold" :style="{ backgroundColor: 'color-mix(in srgb, #16a34a 14%, transparent)', color: '#16a34a' }">
                                    {{ textoValor(d) }}
                                </span>
                                <span v-if="d.tope_monto" class="mt-0.5 block text-[11px]" :style="{ color: 'var(--color-suave)' }">tope {{ pesos.format(d.tope_monto) }}</span>
                            </td>

                            <td class="px-4 py-4 text-xs" :style="{ color: 'var(--color-suave)' }">
                                {{ d.conceptos.length ? d.conceptos.join(', ') : 'Todos los conceptos' }}
                            </td>

                            <td class="px-4 py-4">
                                <PildoraEstado :texto="d.activo ? 'Activo' : 'Inactivo'" :color="d.activo ? '#16a34a' : 'var(--color-suave)'" />
                            </td>

                            <td class="px-6 py-4">
                                <div class="flex items-center justify-end gap-2">
                                    <BotonAccion variante="editar" solo-icono @click="abrirEdicion(d)" />
                                    <BotonAccion variante="eliminar" solo-icono @click="eliminar(d)" />
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <p v-else class="px-6 py-10 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                    Todavía no hay descuentos configurados.
                </p>
            </div>
        </section>
    </AppLayout>
</template>

<style scoped>
.fila-nueva:hover {
    background-color: color-mix(in srgb, var(--color-acento) 5%, transparent);
}
</style>
