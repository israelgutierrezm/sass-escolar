<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';
import { ICONOS } from '@/iconos';

interface Servicio {
    id: number;
    clave: string;
    nombre: string;
    descripcion: string | null;
    concepto_id: number | null;
    concepto: string | null;
    precio: number;
    tiene_costo: boolean;
    solicitable: boolean;
    activo: boolean;
}

defineProps<{ servicios: Servicio[]; conceptos: { id: number; nombre: string }[] }>();

const formulario = useForm({
    clave: '',
    nombre: '',
    descripcion: '',
    concepto_id: null as number | null,
    precio: 0,
    activo: true,
});

const editando = ref<number | null>(null);

/** Con precio hace falta concepto; el aviso se da antes de mandar. */
const faltaConcepto = computed(
    () => Number(formulario.precio) > 0 && formulario.concepto_id === null,
);

const pesos = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' });

function editar(servicio: Servicio): void {
    editando.value = servicio.id;
    formulario.clave = servicio.clave;
    formulario.nombre = servicio.nombre;
    formulario.descripcion = servicio.descripcion ?? '';
    formulario.concepto_id = servicio.concepto_id;
    formulario.precio = servicio.precio;
    formulario.activo = servicio.activo;
}

function limpiar(): void {
    editando.value = null;
    formulario.reset();
    formulario.clearErrors();
}

function guardar(): void {
    const alTerminar = { preserveScroll: true, onSuccess: () => limpiar() };

    if (editando.value === null) {
        formulario.post('/finanzas/servicios', alTerminar);
    } else {
        formulario.put(`/finanzas/servicios/${editando.value}`, alTerminar);
    }
}

function retirar(servicio: Servicio): void {
    router.delete(`/finanzas/servicios/${servicio.id}`, { preserveScroll: true });
}
</script>

<template>
    <Head title="Productos y servicios" />

    <AppLayout titulo="Productos y servicios">
        <section class="tarjeta p-6">
            <h2 class="text-base font-semibold">Lo que tu escuela vende suelto</h2>
            <p class="mt-1 max-w-3xl text-sm" :style="{ color: 'var(--color-suave)' }">
                Constancias, credenciales de repuesto, exámenes extraordinarios. Aquí va el
                <strong>precio</strong> y con qué concepto se factura. Qué puede pedir el alumno se
                decide en Control Escolar, sobre estos mismos servicios.
            </p>
            <p class="mt-2 max-w-3xl text-sm" :style="{ color: 'var(--color-suave)' }">
                Un precio de <strong>0</strong> es un servicio sin costo: se pide y arranca el
                trámite sin pasar por caja.
            </p>
        </section>

        <TarjetaSeccion
            :titulo="editando === null ? 'Agregar un servicio' : 'Editar el servicio'"
            :icono="ICONOS.ajustes"
        >
            <form class="grid gap-4 sm:grid-cols-2" @submit.prevent="guardar">
                <label class="block">
                    <span class="text-sm font-medium">Clave</span>
                    <input v-model="formulario.clave" type="text" class="mt-1 w-full rounded-lg border px-3 py-2 text-sm" />
                    <span v-if="formulario.errors.clave" class="text-xs text-red-600">{{ formulario.errors.clave }}</span>
                </label>

                <label class="block">
                    <span class="text-sm font-medium">Nombre</span>
                    <input v-model="formulario.nombre" type="text" class="mt-1 w-full rounded-lg border px-3 py-2 text-sm" />
                    <span v-if="formulario.errors.nombre" class="text-xs text-red-600">{{ formulario.errors.nombre }}</span>
                </label>

                <label class="block sm:col-span-2">
                    <span class="text-sm font-medium">Descripción</span>
                    <input v-model="formulario.descripcion" type="text" class="mt-1 w-full rounded-lg border px-3 py-2 text-sm" />
                </label>

                <label class="block">
                    <span class="text-sm font-medium">Precio</span>
                    <input
                        v-model.number="formulario.precio"
                        type="number"
                        step="0.01"
                        min="0"
                        class="mt-1 w-full rounded-lg border px-3 py-2 text-sm tabular-nums"
                    />
                    <span v-if="formulario.errors.precio" class="text-xs text-red-600">{{ formulario.errors.precio }}</span>
                </label>

                <label class="block">
                    <span class="text-sm font-medium">Concepto de pago</span>
                    <select v-model="formulario.concepto_id" class="mt-1 w-full rounded-lg border px-3 py-2 text-sm">
                        <option :value="null">Sin concepto (sólo para servicios sin costo)</option>
                        <option v-for="concepto in conceptos" :key="concepto.id" :value="concepto.id">
                            {{ concepto.nombre }}
                        </option>
                    </select>
                    <span v-if="faltaConcepto" class="text-xs text-amber-700">
                        Con precio hace falta el concepto: de ahí salen la clave del SAT y el IVA con
                        los que se factura.
                    </span>
                    <span v-else-if="formulario.errors.concepto_id" class="text-xs text-red-600">
                        {{ formulario.errors.concepto_id }}
                    </span>
                </label>

                <label class="flex items-center gap-2 sm:col-span-2">
                    <input v-model="formulario.activo" type="checkbox" class="h-4 w-4 rounded" />
                    <span class="text-sm">En el catálogo</span>
                </label>

                <div class="flex gap-2 sm:col-span-2">
                    <button
                        type="submit"
                        class="rounded-full px-4 py-2 text-sm font-semibold text-white"
                        :style="{ backgroundColor: 'var(--color-acento)' }"
                        :disabled="formulario.processing || faltaConcepto"
                    >
                        {{ editando === null ? 'Agregar' : 'Guardar cambios' }}
                    </button>
                    <button
                        v-if="editando !== null"
                        type="button"
                        class="rounded-full border px-4 py-2 text-sm"
                        :style="{ borderColor: 'var(--color-borde)' }"
                        @click="limpiar"
                    >
                        Cancelar
                    </button>
                </div>
            </form>
        </TarjetaSeccion>

        <TarjetaSeccion titulo="Catálogo" :icono="ICONOS.ajustes">
            <p v-if="!servicios.length" class="text-sm" :style="{ color: 'var(--color-suave)' }">
                Todavía no hay ninguno.
            </p>

            <ul v-else class="space-y-1">
                <li
                    v-for="servicio in servicios"
                    :key="servicio.id"
                    class="flex flex-wrap items-center gap-3 border-t py-2 first:border-0"
                    :style="{ borderColor: 'var(--color-borde)' }"
                >
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-medium">
                            {{ servicio.nombre }}
                            <span class="ml-1 text-xs" :style="{ color: 'var(--color-suave)' }">
                                {{ servicio.clave }}
                            </span>
                        </span>
                        <span class="block truncate text-xs" :style="{ color: 'var(--color-suave)' }">
                            {{ servicio.concepto ?? 'Sin concepto' }}
                            <template v-if="servicio.solicitable"> · lo puede pedir el alumno</template>
                            <template v-if="!servicio.activo"> · fuera del catálogo</template>
                        </span>
                    </span>

                    <span class="shrink-0 text-sm font-semibold tabular-nums">
                        {{ servicio.tiene_costo ? pesos.format(servicio.precio) : 'Sin costo' }}
                    </span>

                    <span class="flex shrink-0 gap-1">
                        <button type="button" class="rounded-full border px-2 py-1 text-xs" @click="editar(servicio)">
                            Editar
                        </button>
                        <button
                            v-if="servicio.activo"
                            type="button"
                            class="rounded-full border px-2 py-1 text-xs text-red-600"
                            @click="retirar(servicio)"
                        >
                            Retirar
                        </button>
                    </span>
                </li>
            </ul>
        </TarjetaSeccion>
    </AppLayout>
</template>
