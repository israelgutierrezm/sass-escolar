<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import BarraListado from '@/Components/BarraListado.vue';
import { ICONOS } from '@/iconos';

interface Concepto {
    id: number;
    clave: string;
    nombre: string;
    clave_sat: string | null;
    clave_unidad_sat: string | null;
    objeto_impuesto: string | null;
    gravado: boolean;
    tasa_iva: string | null;
    cuenta_contable: string | null;
    en_uso: boolean;
}

const props = defineProps<{
    conceptos: Concepto[];
    catalogos: { objeto_impuesto: { clave: string; texto: string }[] };
    filtros: { busqueda: string; gravado: string | null };
}>();

/*
 * Los filtros que de verdad se preguntan de un concepto: si causa IVA —lo que
 * decide cómo sale en el CFDI— y su nombre o clave. El catálogo crece con los
 * años y se consultaba entero, sin manera de encontrar nada.
 */
const definicionFiltros = [
    { clave: 'gravado', etiqueta: 'Solo los que causan IVA', tipo: 'booleano' as const },
];

function vacio() {
    return {
        clave: '', nombre: '', clave_sat: '86121600', clave_unidad_sat: 'E48',
        objeto_impuesto: '02', gravado: false, tasa_iva: null as number | null, cuenta_contable: '',
    };
}

const creando = ref(false);
const editando = ref<number | null>(null);

const alta = useForm(vacio());
const datos = useForm(vacio());

function crear(): void {
    alta.post('/finanzas/conceptos', {
        preserveScroll: true,
        // Se queda abierto tras agregar para encadenar altas (se cierra con «Cancelar»).
        onSuccess: () => alta.reset(),
    });
}

function abrirEdicion(c: Concepto): void {
    if (editando.value === c.id) {
        editando.value = null;
        return;
    }
    editando.value = c.id;
    const base = vacio();
    for (const k of Object.keys(base) as (keyof typeof base)[]) {
        (datos as any)[k] = (c as any)[k] ?? base[k];
    }
    datos.tasa_iva = c.tasa_iva !== null ? Number(c.tasa_iva) : null;
}

function guardar(c: Concepto): void {
    datos.put(`/finanzas/conceptos/${c.id}`, { preserveScroll: true, onSuccess: () => (editando.value = null) });
}

function eliminar(c: Concepto): void {
    if (!confirm(`¿Eliminar el concepto "${c.nombre}"?`)) {
        return;
    }
    router.delete(`/finanzas/conceptos/${c.id}`, { preserveScroll: true });
}

const objImp = (clave: string | null) =>
    props.catalogos.objeto_impuesto.find((o) => o.clave === clave)?.texto ?? (clave ?? '—');
</script>

<template>
    <Head title="Conceptos de pago" />

    <AppLayout titulo="Conceptos de pago">
        <BarraListado
            url="/finanzas/conceptos"
            :valores="filtros"
            :filtros="definicionFiltros"
            placeholder="Buscar por nombre, clave o clave del SAT…"
            titulo="Qué se cobra y cómo se factura"
            descripcion="Cada concepto lleva sus datos fiscales —clave del SAT, unidad, objeto de impuesto y si causa IVA— para que el CFDI salga bien sin capturarlos en cada factura."
            :icono="ICONOS.dinero"
            :puede-crear="!creando"
            nuevo-texto="Nuevo concepto"
            @nuevo="creando = true"
        >
            <template #conteo>
                <span class="rounded-full px-3 py-1 text-xs font-medium" :style="{ backgroundColor: 'color-mix(in srgb, var(--color-acento) 12%, transparent)', color: 'var(--color-acento)' }">
                    {{ conceptos.length }} {{ conceptos.length === 1 ? 'concepto' : 'conceptos' }}
                </span>
            </template>
        </BarraListado>

        <!-- Alta: en la misma pantalla, para no perder de vista el catálogo. -->
        <section v-if="creando" class="tarjeta p-6">
            <form @submit.prevent="crear">
                <div class="grid gap-3 sm:grid-cols-3 lg:grid-cols-4">
                    <CampoTexto v-model="alta.clave" etiqueta="Clave" mono requerido :error="alta.errors.clave" />
                    <div class="lg:col-span-2">
                        <CampoTexto v-model="alta.nombre" etiqueta="Nombre" requerido :error="alta.errors.nombre" />
                    </div>
                    <CampoTexto v-model="alta.clave_sat" etiqueta="Clave SAT (ProdServ)" mono :error="alta.errors.clave_sat" />
                    <CampoTexto v-model="alta.clave_unidad_sat" etiqueta="Clave de unidad" mono :error="alta.errors.clave_unidad_sat" />
                    <div class="lg:col-span-2">
                        <CampoSelect
                            v-model="alta.objeto_impuesto"
                            etiqueta="Objeto de impuesto"
                            requerido
                            :opciones="catalogos.objeto_impuesto.map((o) => ({ valor: o.clave, texto: o.texto }))"
                            :error="alta.errors.objeto_impuesto"
                        />
                    </div>
                    <label class="flex items-center gap-2 text-sm">
                        <input v-model="alta.gravado" type="checkbox" />
                        Causa IVA
                    </label>
                    <label class="text-sm">
                        <span class="mb-1 block font-medium">Tasa de IVA</span>
                        <input v-model.number="alta.tasa_iva" type="number" step="0.01" min="0" max="1" placeholder="0.16" class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" />
                    </label>
                </div>
                <div class="mt-4 flex gap-2">
                    <BotonPrincipal :procesando="alta.processing" texto="Crear" icono="crear-circulo" solo-icono />
                    <button type="button" class="rounded-lg border px-4 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" @click="creando = false">
                        Cancelar
                    </button>
                </div>
            </form>
        </section>

        <!-- Listado -->
        <div class="tarjeta overflow-hidden">
            <div class="overflow-x-auto">
                <table v-if="conceptos.length" class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-[11px] uppercase tracking-wider" :style="{ color: 'var(--color-suave)', backgroundColor: 'color-mix(in srgb, var(--color-suave) 6%, transparent)' }">
                            <th class="px-6 py-3 font-semibold">Concepto</th>
                            <th class="px-4 py-3 font-semibold">Clave SAT</th>
                            <th class="px-4 py-3 font-semibold">Unidad</th>
                            <th class="px-4 py-3 font-semibold">Objeto de impuesto</th>
                            <th class="px-4 py-3 font-semibold">IVA</th>
                            <th class="px-6 py-3 font-semibold text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="c in conceptos" :key="c.id">
                            <tr class="fila-nueva border-t transition-colors" :style="{ borderColor: 'var(--color-borde)' }">
                                <td class="px-6 py-4">
                                    <span class="block font-semibold text-contenido">{{ c.nombre }}</span>
                                    <span class="mt-0.5 block font-mono text-[11px]" :style="{ color: 'var(--color-suave)' }">{{ c.clave }}</span>
                                </td>
                                <td class="px-4 py-4 font-mono text-xs" :style="{ color: 'var(--color-suave)' }">{{ c.clave_sat ?? '—' }}</td>
                                <td class="px-4 py-4 font-mono text-xs" :style="{ color: 'var(--color-suave)' }">{{ c.clave_unidad_sat ?? '—' }}</td>
                                <td class="px-4 py-4 text-xs">{{ objImp(c.objeto_impuesto) }}</td>
                                <td class="px-4 py-4">
                                    <span
                                        class="inline-block rounded-full px-2.5 py-0.5 text-[11px] font-medium"
                                        :style="c.gravado
                                            ? { backgroundColor: 'color-mix(in srgb, var(--color-acento) 12%, transparent)', color: 'var(--color-acento)' }
                                            : { backgroundColor: 'color-mix(in srgb, var(--color-suave) 10%, transparent)', color: 'var(--color-suave)' }"
                                    >{{ c.gravado ? `IVA ${Math.round(Number(c.tasa_iva ?? 0) * 100)}%` : 'Exento' }}</span>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="flex items-center justify-end gap-1">
                                        <BotonAccion :variante="editando === c.id ? 'cerrar' : 'editar'" @click="abrirEdicion(c)" />
                                        <BotonAccion v-if="!c.en_uso" variante="eliminar" @click="eliminar(c)" />
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="editando === c.id" class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
                                <td colspan="6" class="px-4 py-4" style="background-color: color-mix(in srgb, var(--color-acento) 4%, transparent)">
                                    <form class="grid gap-3 sm:grid-cols-3 lg:grid-cols-4" @submit.prevent="guardar(c)">
                                        <CampoTexto v-model="datos.clave" etiqueta="Clave" mono requerido :error="datos.errors.clave" />
                                        <div class="lg:col-span-2">
                                            <CampoTexto v-model="datos.nombre" etiqueta="Nombre" requerido :error="datos.errors.nombre" />
                                        </div>
                                        <CampoTexto v-model="datos.clave_sat" etiqueta="Clave SAT (ProdServ)" mono :error="datos.errors.clave_sat" />
                                        <CampoTexto v-model="datos.clave_unidad_sat" etiqueta="Clave de unidad" mono :error="datos.errors.clave_unidad_sat" />
                                        <div class="lg:col-span-2">
                                            <CampoSelect
                                                v-model="datos.objeto_impuesto"
                                                etiqueta="Objeto de impuesto"
                                                requerido
                                                :opciones="catalogos.objeto_impuesto.map((o) => ({ valor: o.clave, texto: o.texto }))"
                                                :error="datos.errors.objeto_impuesto"
                                            />
                                        </div>
                                        <label class="flex items-center gap-2 text-sm">
                                            <input v-model="datos.gravado" type="checkbox" />
                                            Causa IVA
                                        </label>
                                        <label class="text-sm">
                                            <span class="mb-1 block font-medium">Tasa de IVA</span>
                                            <input v-model.number="datos.tasa_iva" type="number" step="0.01" min="0" max="1" placeholder="0.16" class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" />
                                        </label>
                                        <div class="sm:col-span-3 lg:col-span-4">
                                            <BotonPrincipal :procesando="datos.processing" texto="Guardar" />
                                        </div>
                                    </form>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>

                <p v-else class="px-4 py-12 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                    Aún no hay conceptos de pago.
                </p>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
.fila-nueva:hover {
    background-color: color-mix(in srgb, var(--color-acento) 5%, transparent);
}
</style>
