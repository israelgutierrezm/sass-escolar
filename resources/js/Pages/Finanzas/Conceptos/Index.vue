<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonAccion from '@/Components/BotonAccion.vue';

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
}>();

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
        onSuccess: () => {
            alta.reset();
            creando.value = false;
        },
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
        <section class="tarjeta p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="max-w-2xl">
                    <h2 class="text-base font-semibold">Qué se cobra y cómo se factura</h2>
                    <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                        Colegiatura, inscripción, credencial… Cada concepto lleva sus datos fiscales
                        —clave del SAT, unidad, <strong>objeto de impuesto</strong> y si causa IVA— para que
                        el CFDI salga bien sin capturarlos en cada factura.
                    </p>
                </div>
                <button
                    v-if="!creando"
                    type="button"
                    class="rounded-lg px-4 py-2 text-sm font-medium"
                    :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                    @click="creando = true"
                >
                    Nuevo concepto
                </button>
            </div>

            <!-- Alta -->
            <form v-if="creando" class="mt-5 border-t pt-5" :style="{ borderColor: 'var(--color-borde)' }" @submit.prevent="crear">
                <div class="grid gap-3 sm:grid-cols-3 lg:grid-cols-4">
                    <label class="text-sm">
                        <span class="mb-1 block font-medium">Clave</span>
                        <input v-model="alta.clave" type="text" required class="w-full rounded-lg border px-3 py-2 font-mono text-sm" :style="{ borderColor: 'var(--color-borde)' }" />
                        <span v-if="alta.errors.clave" class="text-xs text-red-600">{{ alta.errors.clave }}</span>
                    </label>
                    <label class="text-sm lg:col-span-2">
                        <span class="mb-1 block font-medium">Nombre</span>
                        <input v-model="alta.nombre" type="text" required class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" />
                    </label>
                    <label class="text-sm">
                        <span class="mb-1 block font-medium">Clave SAT (ProdServ)</span>
                        <input v-model="alta.clave_sat" type="text" class="w-full rounded-lg border px-3 py-2 font-mono text-sm" :style="{ borderColor: 'var(--color-borde)' }" />
                    </label>
                    <label class="text-sm">
                        <span class="mb-1 block font-medium">Clave de unidad</span>
                        <input v-model="alta.clave_unidad_sat" type="text" class="w-full rounded-lg border px-3 py-2 font-mono text-sm" :style="{ borderColor: 'var(--color-borde)' }" />
                    </label>
                    <label class="text-sm lg:col-span-2">
                        <span class="mb-1 block font-medium">Objeto de impuesto</span>
                        <select v-model="alta.objeto_impuesto" required class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }">
                            <option v-for="o in catalogos.objeto_impuesto" :key="o.clave" :value="o.clave">{{ o.texto }}</option>
                        </select>
                    </label>
                    <label class="flex items-center gap-2 text-sm">
                        <input v-model="alta.gravado" type="checkbox" class="rounded" />
                        Causa IVA
                    </label>
                    <label class="text-sm">
                        <span class="mb-1 block font-medium">Tasa de IVA</span>
                        <input v-model.number="alta.tasa_iva" type="number" step="0.01" min="0" max="1" placeholder="0.16" class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" />
                    </label>
                </div>
                <div class="mt-4 flex gap-2">
                    <button type="submit" :disabled="alta.processing" class="rounded-lg px-4 py-2 text-sm font-medium disabled:opacity-50" :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }">
                        Crear
                    </button>
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
                    <thead class="text-left text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">
                        <tr>
                            <th class="px-4 py-3 font-medium">Concepto</th>
                            <th class="px-4 py-3 font-medium">Clave SAT</th>
                            <th class="px-4 py-3 font-medium">Unidad</th>
                            <th class="px-4 py-3 font-medium">Objeto de impuesto</th>
                            <th class="px-4 py-3 font-medium">IVA</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="c in conceptos" :key="c.id">
                            <tr class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
                                <td class="px-4 py-3">
                                    <span class="font-medium">{{ c.nombre }}</span>
                                    <span class="block font-mono text-xs" :style="{ color: 'var(--color-suave)' }">{{ c.clave }}</span>
                                </td>
                                <td class="px-4 py-3 font-mono text-xs" :style="{ color: 'var(--color-suave)' }">{{ c.clave_sat ?? '—' }}</td>
                                <td class="px-4 py-3 font-mono text-xs" :style="{ color: 'var(--color-suave)' }">{{ c.clave_unidad_sat ?? '—' }}</td>
                                <td class="px-4 py-3 text-xs">{{ objImp(c.objeto_impuesto) }}</td>
                                <td class="px-4 py-3 text-xs" :style="{ color: 'var(--color-suave)' }">
                                    {{ c.gravado ? `${Math.round(Number(c.tasa_iva ?? 0) * 100)}%` : 'Exento' }}
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center justify-end gap-1">
                                        <BotonAccion :variante="editando === c.id ? 'cerrar' : 'editar'" @click="abrirEdicion(c)" />
                                        <BotonAccion v-if="!c.en_uso" variante="eliminar" @click="eliminar(c)" />
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="editando === c.id" class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
                                <td colspan="6" class="px-4 py-4" style="background-color: color-mix(in srgb, var(--color-acento) 4%, transparent)">
                                    <form class="grid gap-3 sm:grid-cols-3 lg:grid-cols-4" @submit.prevent="guardar(c)">
                                        <label class="text-sm">
                                            <span class="mb-1 block font-medium">Clave</span>
                                            <input v-model="datos.clave" type="text" required class="w-full rounded-lg border px-3 py-2 font-mono text-sm" :style="{ borderColor: 'var(--color-borde)' }" />
                                        </label>
                                        <label class="text-sm lg:col-span-2">
                                            <span class="mb-1 block font-medium">Nombre</span>
                                            <input v-model="datos.nombre" type="text" required class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" />
                                        </label>
                                        <label class="text-sm">
                                            <span class="mb-1 block font-medium">Clave SAT (ProdServ)</span>
                                            <input v-model="datos.clave_sat" type="text" class="w-full rounded-lg border px-3 py-2 font-mono text-sm" :style="{ borderColor: 'var(--color-borde)' }" />
                                        </label>
                                        <label class="text-sm">
                                            <span class="mb-1 block font-medium">Clave de unidad</span>
                                            <input v-model="datos.clave_unidad_sat" type="text" class="w-full rounded-lg border px-3 py-2 font-mono text-sm" :style="{ borderColor: 'var(--color-borde)' }" />
                                        </label>
                                        <label class="text-sm lg:col-span-2">
                                            <span class="mb-1 block font-medium">Objeto de impuesto</span>
                                            <select v-model="datos.objeto_impuesto" required class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }">
                                                <option v-for="o in catalogos.objeto_impuesto" :key="o.clave" :value="o.clave">{{ o.texto }}</option>
                                            </select>
                                        </label>
                                        <label class="flex items-center gap-2 text-sm">
                                            <input v-model="datos.gravado" type="checkbox" class="rounded" />
                                            Causa IVA
                                        </label>
                                        <label class="text-sm">
                                            <span class="mb-1 block font-medium">Tasa de IVA</span>
                                            <input v-model.number="datos.tasa_iva" type="number" step="0.01" min="0" max="1" placeholder="0.16" class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" />
                                        </label>
                                        <div class="sm:col-span-3 lg:col-span-4">
                                            <button type="submit" :disabled="datos.processing" class="rounded-lg px-4 py-2 text-sm font-medium disabled:opacity-50" :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }">
                                                Guardar
                                            </button>
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
